<?php

namespace App\Http\Controllers;

use App\Models\AnswerDraft;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Services\ExamFinalizer;
use App\Support\ExamAccess;
use App\Support\Scoring;
use App\Support\Shuffle;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ExamController extends Controller
{
    private const KNOWN_EVENT_KINDS = [
        'tab_blur', 'tab_focus', 'fullscreen_exit', 'fullscreen_enter',
        'paste_blocked', 'copy_blocked', 'contextmenu_blocked', 'seb_missing',
        'session_resumed', 'auto_submitted_timeout',
    ];

    /** GET /exams/{examId} — load (or resume) the exam-taking page. */
    public function show(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = Exam::where('id', $examId)->orWhere('exam_code', $examId)->first();
        if (! $exam) {
            abort(404, 'Exam not found.');
        }
        if (! ExamAccess::has($request, $user, $exam->id)) {
            return redirect('/token')->with('error', 'A valid exam access token is required.');
        }

        $isAdmin = $user->role === 'admin';
        if (! $isAdmin) {
            $now = now();
            if (! $exam->active) {
                return redirect('/token')->with('error', 'This exam is not active.');
            }
            if ($exam->start_time && $exam->start_time->gt($now)) {
                return redirect('/token')->with('error', 'This exam has not started yet.');
            }
            if ($exam->end_time && $exam->end_time->lt($now)) {
                return redirect('/token')->with('error', 'This exam has ended.');
            }
        }

        if ($exam->exam_mode === 'strict' && ! $isAdmin) {
            $existing = ExamSubmission::where('user_id', $user->id)
                ->where('exam_id', $exam->id)
                ->orderByDesc('submitted_at')
                ->first();
            if ($existing) {
                return redirect('/student/scores/' . $existing->id)
                    ->with('error', 'You have already submitted this strict-mode exam. Only one attempt is allowed.');
            }
        }

        $session = ExamSession::where('user_id', $user->id)
            ->where('exam_id', $exam->id)
            ->where('status', 'draft')
            ->orderByDesc('created_at')
            ->first();
        $sessionPreexisted = $session !== null;

        if (! $session) {
            $previous = ExamSession::where('user_id', $user->id)
                ->where('exam_id', $exam->id)
                ->orderByDesc('attempt')
                ->first();
            $nextAttempt = ($previous?->attempt ?? 0) + 1;
            try {
                // EXPLICIT started_at (NOT relying on MySQL's useCurrent default):
                // Eloquent::create returns a model without re-fetching DB-side defaults,
                // so $session->started_at would be null in memory and break the
                // ->getTimestamp() math below. This is a load-test-only hot path
                // (every brand-new student first-load hits it).
                $session = ExamSession::create([
                    'user_id' => $user->id,
                    'exam_id' => $exam->id,
                    'attempt' => $nextAttempt,
                    'started_at' => now(),
                    // Per-attempt resume token — long-lived (lives as long as the
                    // session itself), unguessable UUID. Surfaced on the Student
                    // Hub so the student can resume even after their 8h
                    // exam-access cookie has expired. See ExamAccessController::resume.
                    'resume_token' => (string) \Illuminate\Support\Str::uuid(),
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23000') {
                    $session = ExamSession::where('user_id', $user->id)
                        ->where('exam_id', $exam->id)
                        ->where('status', 'draft')
                        ->orderByDesc('created_at')
                        ->first();
                    if (! $session) {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }
        }

        $elapsed = now()->getTimestamp() - $session->started_at->getTimestamp();
        $timeRemaining = max(0, $exam->duration_minutes * 60 - $elapsed);

        if (! $isAdmin && ExamFinalizer::isSessionExpired($session->started_at, $exam->duration_minutes)) {
            $finalised = ExamFinalizer::finaliseExpiredSession($session->id);

            return redirect('/student/scores/' . $finalised['submissionId'])
                ->with('error', 'Your time is up. Your answers have been submitted automatically.');
        }

        if ($sessionPreexisted && $user->role === 'student') {
            try {
                $events = is_array($session->anti_cheat_events) ? $session->anti_cheat_events : [];
                if (count($events) < 5000) {
                    $events[] = [
                        'kind' => 'session_resumed',
                        'at' => now()->toIso8601String(),
                        'detail' => 'Time elapsed ' . $elapsed . 's',
                    ];
                    ExamSession::where('id', $session->id)->update(['anti_cheat_events' => $events]);
                }
            } catch (\Throwable) {
                // best-effort
            }
        }

        $rawQuestions = ExamQuestion::with('media')
            ->where('exam_id', $exam->id)
            ->orderBy('position')
            ->get();
        $drafts = AnswerDraft::where('session_id', $session->id)->get(['question_id', 'value']);

        $nonEssay = $rawQuestions->filter(fn ($q) => $q->type !== 'essay')->values()->all();
        $essay = $rawQuestions->filter(fn ($q) => $q->type === 'essay')->values()->all();
        $orderedNonEssay = $exam->shuffle_questions
            ? Shuffle::withSeed($nonEssay, $session->id . '::q')
            : $nonEssay;
        $ordered = array_merge($orderedNonEssay, $essay);

        $questions = [];
        $media = [];
        foreach ($ordered as $i => $q) {
            $opts = $q->options;
            if ($opts && $exam->shuffle_options && in_array($q->type, ['single_choice', 'multi_select'], true)) {
                $opts = Shuffle::withSeed($opts, $session->id . '::o::' . $q->id);
            }
            $questions[] = [
                'id' => $q->id,
                'position' => $i + 1,
                'type' => $q->type,
                'topic' => $q->topic,
                'tags' => $q->tags ?? [],
                'prompt' => $q->prompt,
                'options' => $opts,
                'points' => $q->points,
            ];
            foreach ($q->media as $m) {
                $media[] = [
                    'id' => $m->id,
                    'questionId' => $m->question_id,
                    'type' => $m->type,
                    'url' => $m->url,
                    'altText' => $m->alt_text,
                    'caption' => $m->caption,
                ];
            }
        }

        $draftAnswers = [];
        foreach ($drafts as $d) {
            $draftAnswers[$d->question_id] = $d->value;
        }

        return Inertia::render('exam/Take', [
            'metadata' => [
                'id' => $exam->id,
                'examId' => $exam->exam_code,
                'name' => $exam->name,
                'durationMinutes' => $exam->duration_minutes,
                'passingGrade' => $exam->passing_grade,
                'generalInstructions' => $exam->general_instructions ?? '',
                'mediaBaseUrl' => $exam->media_base_url,
                'examMode' => $exam->exam_mode,
                'sebRequired' => (bool) $exam->seb_required,
            ],
            'session' => [
                'id' => $session->id,
                'startedAt' => $session->started_at->toIso8601String(),
                'lastSavedAt' => $session->last_saved_at?->toIso8601String(),
                'timeRemainingSeconds' => $timeRemaining,
            ],
            'questions' => $questions,
            'media' => $media,
            'draftAnswers' => (object) $draftAnswers,
        ]);
    }

    /** PUT /api/exams/{examId}/draft — autosave (JSON). */
    public function saveDraft(Request $request, string $examId)
    {
        $user = $request->user();
        RateLimiter::hit('draft:' . $user->id, 60);

        $answers = $request->input('answers', []);
        if (! is_array($answers)) {
            return response()->json(['error' => 'Invalid request payload.'], 400);
        }

        $session = $this->writableSession($user->id, $examId);
        if ($session instanceof \Illuminate\Http\JsonResponse) {
            return $session;
        }
        ExamAccess::require($request, $user, $session->exam_id);

        $questionIds = array_keys($answers);
        if (count($questionIds) === 0) {
            return response()->json(['ok' => true, 'savedAt' => now()->toIso8601String()]);
        }

        $validIds = ExamQuestion::where('exam_id', $session->exam_id)
            ->whereIn('id', $questionIds)
            ->pluck('id')->all();
        $validSet = array_flip($validIds);

        $now = now();
        $guard = ExamSession::where('id', $session->id)->where('status', 'draft')
            ->update(['last_saved_at' => $now]);
        if ($guard === 0) {
            return response()->json(['error' => 'This exam session is no longer accepting saves.'], 409);
        }

        $rows = [];
        foreach ($answers as $qid => $val) {
            if (! isset($validSet[$qid])) {
                continue;
            }
            $rows[] = [
                'id' => (string) Str::uuid(),
                'session_id' => $session->id,
                'question_id' => $qid,
                'value' => json_encode($val),
                'updated_at' => $now,
            ];
        }
        if ($rows) {
            DB::table('answer_drafts')->upsert($rows, ['session_id', 'question_id'], ['value', 'updated_at']);
        }

        return response()->json(['ok' => true, 'savedAt' => $now->toIso8601String()]);
    }

    /** POST /exams/{examId}/submit — score + create submission, redirect to results. */
    public function submit(Request $request, string $examId)
    {
        $user = $request->user();
        RateLimiter::hit('submit:' . $user->id, 60);

        $answers = is_array($request->input('answers')) ? $request->input('answers') : [];
        $finalEventsBatch = $this->sanitiseEvents($request->input('events'));

        try {
            $result = DB::transaction(function () use ($user, $examId, $answers, $finalEventsBatch, $request) {
                $session = ExamSession::with('exam')
                    ->where('user_id', $user->id)
                    ->whereIn('status', ['draft', 'expired'])
                    ->whereHas('exam', fn ($q) => $q->where('id', $examId)->orWhere('exam_code', $examId))
                    ->orderByDesc('created_at')
                    ->first();

                if (! $session) {
                    $existing = ExamSubmission::where('user_id', $user->id)
                        ->whereHas('exam', fn ($q) => $q->where('id', $examId)->orWhere('exam_code', $examId))
                        ->orderByDesc('submitted_at')->first();
                    if ($existing) {
                        return ['submissionId' => $existing->id];
                    }
                    abort(404, 'No active exam session found.');
                }

                $exam = $session->exam;
                ExamAccess::require($request, $user, $exam->id);

                if ($exam->exam_mode === 'strict' && $user->role !== 'admin') {
                    $prior = ExamSubmission::where('user_id', $user->id)
                        ->where('exam_id', $exam->id)->orderByDesc('submitted_at')->first();
                    if ($prior) {
                        return ['submissionId' => $prior->id];
                    }
                }

                $elapsed = now()->getTimestamp() - $session->started_at->getTimestamp();
                $pastDuration = $elapsed > $exam->duration_minutes * 60 + 60;
                $pastEndTime = $exam->end_time && now()->getTimestamp() > $exam->end_time->getTimestamp() + 60;
                $isLate = $pastDuration || $pastEndTime;

                if (! $isLate && count($answers) > 0) {
                    $validIds = ExamQuestion::where('exam_id', $exam->id)
                        ->whereIn('id', array_keys($answers))->pluck('id')->all();
                    $validSet = array_flip($validIds);
                    $rows = [];
                    foreach ($answers as $qid => $val) {
                        if (! isset($validSet[$qid])) {
                            continue;
                        }
                        $rows[] = [
                            'id' => (string) Str::uuid(),
                            'session_id' => $session->id,
                            'question_id' => $qid,
                            'value' => json_encode($val),
                            'updated_at' => now(),
                        ];
                    }
                    if ($rows) {
                        DB::table('answer_drafts')->upsert($rows, ['session_id', 'question_id'], ['value', 'updated_at']);
                    }
                }

                $questions = ExamQuestion::where('exam_id', $exam->id)
                    ->get(['id', 'topic', 'points', 'correct_answer', 'type']);
                $drafts = AnswerDraft::where('session_id', $session->id)->get(['question_id', 'value']);
                $answersSnapshot = [];
                foreach ($drafts as $d) {
                    $answersSnapshot[$d->question_id] = $d->value;
                }

                $scoring = Scoring::scoreExam(
                    $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all(),
                    $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
                    $answersSnapshot
                );

                $sessionEvents = is_array($session->anti_cheat_events) ? $session->anti_cheat_events : [];
                $merged = [];
                $seen = [];
                foreach (array_merge($sessionEvents, $finalEventsBatch) as $e) {
                    $k = ($e['kind'] ?? '') . '|' . ($e['at'] ?? '') . '|' . ($e['detail'] ?? '');
                    if (isset($seen[$k])) {
                        continue;
                    }
                    $seen[$k] = true;
                    $merged[] = $e;
                    if (count($merged) >= 5000) {
                        break;
                    }
                }

                $passed = $scoring['percentScore'] >= $exam->passing_grade;
                $submission = ExamSubmission::create([
                    'exam_id' => $exam->id,
                    'user_id' => $user->id,
                    'session_id' => $session->id,
                    'attempt' => $session->attempt,
                    'username' => $user->username,
                    'full_name' => $user->full_name,
                    'exam_name' => $exam->name,
                    'exam_mode' => $exam->exam_mode,
                    'passing_grade' => $exam->passing_grade,
                    'final_score' => $scoring['finalScore'],
                    'possible_score' => $scoring['possibleScore'],
                    'percent_score' => $scoring['percentScore'],
                    'passed' => $passed,
                    'pending_essay_count' => $scoring['pendingEssayCount'],
                    'topic_breakdown' => $scoring['topicBreakdown'],
                    'answers_snapshot' => $answersSnapshot,
                    'anti_cheat_events' => count($merged) > 0 ? $merged : null,
                    // Explicit submitted_at — same reason as session->started_at:
                    // Eloquent doesn't refresh DB-side useCurrent() defaults.
                    'submitted_at' => now(),
                ]);

                ExamSession::where('id', $session->id)->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'last_saved_at' => now(),
                ]);

                return ['submissionId' => $submission->id];
            }, 5); // 5 deadlock retries — supports concurrent submit bursts (2000+ students)
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $existing = ExamSubmission::where('user_id', $user->id)
                    ->whereHas('exam', fn ($q) => $q->where('id', $examId)->orWhere('exam_code', $examId))
                    ->orderByDesc('submitted_at')->first();
                if ($existing) {
                    if ($request->expectsJson()) {
                        return response()->json(['submissionId' => $existing->id, 'alreadySubmitted' => true]);
                    }
                    return redirect('/student/scores/' . $existing->id);
                }
            }
            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json(['submissionId' => $result['submissionId']]);
        }
        return redirect('/results/' . $result['submissionId']);
    }

    /** POST /api/exams/{examId}/events — anti-cheat event sink (JSON). */
    public function events(Request $request, string $examId)
    {
        $user = $request->user();
        RateLimiter::hit('events:' . $user->id, 60);

        $raw = $request->input('events');
        if (! is_array($raw)) {
            return response()->json(['error' => 'events must be an array.'], 400);
        }
        if (count($raw) === 0) {
            return response()->json(['saved' => 0, 'total' => 0]);
        }
        if (count($raw) > 500) {
            return response()->json(['error' => 'Too many events in one batch (max 500).'], 400);
        }
        $sanitised = $this->sanitiseEvents($raw, ['tab_blur', 'tab_focus', 'fullscreen_exit', 'fullscreen_enter', 'paste_blocked', 'copy_blocked', 'contextmenu_blocked', 'seb_missing']);
        if (count($sanitised) === 0) {
            return response()->json(['saved' => 0, 'total' => 0]);
        }

        return DB::transaction(function () use ($user, $examId, $sanitised, $request) {
            $session = ExamSession::where('user_id', $user->id)
                ->where('status', 'draft')
                ->whereHas('exam', fn ($q) => $q->where('id', $examId)->orWhere('exam_code', $examId))
                ->with('exam:id')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();
            if (! $session) {
                return response()->json(['error' => 'No active exam session found.'], 404);
            }
            ExamAccess::require($request, $user, $session->exam_id);

            $existing = is_array($session->anti_cheat_events) ? $session->anti_cheat_events : [];
            $seen = [];
            foreach ($existing as $e) {
                $seen[($e['kind'] ?? '') . '|' . ($e['at'] ?? '') . '|' . ($e['detail'] ?? '')] = true;
            }
            $fresh = [];
            foreach ($sanitised as $e) {
                $k = ($e['kind'] ?? '') . '|' . ($e['at'] ?? '') . '|' . ($e['detail'] ?? '');
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $fresh[] = $e;
            }
            $room = 5000 - count($existing);
            if (count($fresh) === 0 || $room <= 0) {
                return response()->json(['saved' => 0, 'total' => count($existing)]);
            }
            $toAdd = array_slice($fresh, 0, $room);
            $next = array_merge($existing, $toAdd);
            ExamSession::where('id', $session->id)->update(['anti_cheat_events' => $next]);

            return response()->json(['saved' => count($toAdd), 'total' => count($next)]);
        });
    }

    private function writableSession(string $userId, string $examId)
    {
        $session = ExamSession::with('exam:id,duration_minutes,end_time')
            ->where('user_id', $userId)
            ->where('status', 'draft')
            ->whereHas('exam', fn ($q) => $q->where('id', $examId)->orWhere('exam_code', $examId))
            ->orderByDesc('created_at')
            ->first();
        if (! $session) {
            return response()->json(['error' => 'No active exam session found.'], 404);
        }
        $elapsed = now()->getTimestamp() - $session->started_at->getTimestamp();
        $pastDuration = $elapsed > $session->exam->duration_minutes * 60 + 60;
        $pastEndTime = $session->exam->end_time && now()->getTimestamp() > $session->exam->end_time->getTimestamp() + 60;
        if ($pastDuration || $pastEndTime) {
            ExamSession::where('id', $session->id)->where('status', 'draft')->update(['status' => 'expired']);

            return response()->json(['error' => 'This exam session has expired.'], 409);
        }

        return $session;
    }

    private function sanitiseEvents(mixed $raw, ?array $kinds = null): array
    {
        $allowed = $kinds ?? self::KNOWN_EVENT_KINDS;
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, 500) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $kind = $item['kind'] ?? null;
            if (! is_string($kind) || ! in_array($kind, $allowed, true)) {
                continue;
            }
            $at = (isset($item['at']) && is_string($item['at']) && strtotime($item['at']) !== false)
                ? $item['at'] : now()->toIso8601String();
            $entry = ['kind' => $kind, 'at' => $at];
            if (isset($item['detail']) && is_string($item['detail'])) {
                $entry['detail'] = substr($item['detail'], 0, 200);
            }
            $out[] = $entry;
        }

        return $out;
    }
}
