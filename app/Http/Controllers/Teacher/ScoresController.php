<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\ClassStudent;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Models\StudentClass;
use App\Models\User;
use App\Support\Scoring;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Teacher Scores + Grading — faithful port of the original Next.js
 * /teacher/scores, /teacher/scores/[submissionId], /teacher/auto-score
 * and /teacher/pending-score views plus their API routes.
 *
 * Ownership model: teachers are scoped to exams they created
 * (Exam.created_by); admins see every exam. All score aggregates are
 * recomputed on the fly through App\Support\Scoring so manual essay
 * grades take effect immediately and the per-portion (auto vs essay)
 * splits stay honest.
 */
class ScoresController extends Controller
{
    private const PROCTORING_EVENT_LABELS = [
        'tab_blur' => 'Tab/window lost focus',
        'tab_focus' => 'Returned to tab',
        'fullscreen_exit' => 'Exited fullscreen',
        'fullscreen_enter' => 'Entered fullscreen',
        'paste_blocked' => 'Paste blocked',
        'copy_blocked' => 'Copy blocked',
        'contextmenu_blocked' => 'Right-click blocked',
        'seb_missing' => 'Tried to enter without SEB',
    ];

    // ---------------------------------------------------------------
    // GET /teacher/scores — full tree (Exam → Class → Students).
    // ---------------------------------------------------------------
    public function index(Request $request)
    {
        return Inertia::render('teacher/Scores', [
            'groups' => $this->buildScoresTree($request),
        ]);
    }

    // ---------------------------------------------------------------
    // GET /teacher/auto-score — same tree, focused on the auto-graded
    // portion (MCQ + multi + short + numeric).
    // ---------------------------------------------------------------
    public function autoScore(Request $request)
    {
        return Inertia::render('teacher/AutoScore', [
            'groups' => $this->buildScoresTree($request),
        ]);
    }

    // ---------------------------------------------------------------
    // GET /teacher/pending-score — same tree, restricted client-side to
    // submissions with pending essays. We also pre-build the per-exam +
    // per-submission AI export markdown bundles so the page can copy /
    // download them without an extra round-trip (the original hit a
    // dedicated /ai-export endpoint; we inline the same bundle here).
    // ---------------------------------------------------------------
    public function pendingScore(Request $request)
    {
        $groups = $this->buildScoresTree($request);
        $exports = $this->buildAiExports($request, $groups);

        return Inertia::render('teacher/PendingScore', [
            'groups' => $groups,
            'aiExports' => $exports,
        ]);
    }

    // ---------------------------------------------------------------
    // GET /teacher/scores/{submissionId} — full grading detail.
    // ---------------------------------------------------------------
    public function show(Request $request, string $submissionId)
    {
        $user = $request->user();
        $submission = ExamSubmission::with(['exam' => function ($q) {
            $q->select('id', 'exam_code', 'subject', 'created_by');
        }])->find($submissionId);

        if (! $submission) {
            abort(404, 'Submission not found.');
        }
        if ($user->role === 'teacher' && $submission->exam->created_by !== $user->id) {
            abort(403, 'This submission is not in your account.');
        }

        $questions = ExamQuestion::with('media')
            ->where('exam_id', $submission->exam_id)
            ->orderBy('position')->get();

        $answersSnapshot = is_array($submission->answers_snapshot) ? $submission->answers_snapshot : [];
        $manualScores = is_array($submission->manual_scores) ? $submission->manual_scores : [];
        $manualFeedback = is_array($submission->review_items) ? $submission->review_items : [];

        $scoring = Scoring::scoreExam(
            $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all(),
            $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
            $answersSnapshot,
            $manualScores
        );
        $itemResults = collect($scoring['itemResults'])->keyBy('questionId');

        $items = $questions->map(function ($q) use ($itemResults, $answersSnapshot, $manualFeedback) {
            $item = $itemResults->get($q->id);
            $isEssay = $q->type === 'essay';

            return [
                'question' => [
                    'id' => $q->id,
                    'position' => $q->position,
                    'type' => $q->type,
                    'topic' => $q->topic,
                    'tags' => $q->tags ?? [],
                    'prompt' => $q->prompt,
                    'options' => $q->options,
                    'points' => (float) $q->points,
                ],
                'media' => $q->media->map(fn ($m) => [
                    'id' => $m->id,
                    'questionId' => $m->question_id,
                    'type' => $m->type,
                    'url' => $m->url,
                    'altText' => $m->alt_text,
                    'caption' => $m->caption,
                ])->all(),
                'studentAnswer' => $answersSnapshot[$q->id] ?? null,
                'correctAnswer' => $q->correct_answer ?? null,
                'explanationText' => $q->explanation_text ?? '',
                'feedback' => is_string($manualFeedback[$q->id] ?? null) ? $manualFeedback[$q->id] : '',
                'isAutoGraded' => ! $isEssay,
                'isCorrect' => $item['isCorrect'] ?? false,
                'awarded' => $item['awarded'] ?? 0,
                'possible' => $item['possible'] ?? (float) $q->points,
                'requiresGrading' => $item['requiresGrading'] ?? false,
            ];
        })->all();

        $passed = $scoring['pendingEssayCount'] === 0
            && $scoring['percentScore'] >= $submission->passing_grade;

        // Anti-cheat / proctoring events: normalise + attach a label so
        // the page can render the kind map + summary pills + table.
        $rawEvents = is_array($submission->anti_cheat_events) ? $submission->anti_cheat_events : [];
        $antiCheatEvents = array_values(array_map(function ($ev) {
            $kind = is_array($ev) ? ($ev['kind'] ?? '') : '';
            $at = is_array($ev) ? ($ev['at'] ?? null) : null;

            return [
                'kind' => $kind,
                'label' => self::PROCTORING_EVENT_LABELS[$kind] ?? $kind,
                'at' => $at,
            ];
        }, $rawEvents));

        return Inertia::render('teacher/Grade', [
            'submission' => [
                'id' => $submission->id,
                'examDatabaseId' => $submission->exam->id,
                'examId' => $submission->exam->exam_code,
                'examName' => $submission->exam_name,
                'examSubject' => $submission->exam->subject ?? '',
                'passingGrade' => $submission->passing_grade,
                'studentName' => $submission->full_name,
                'username' => $submission->username,
                'submittedAt' => $submission->submitted_at?->toIso8601String(),
                'finalScore' => $scoring['finalScore'],
                'possibleScore' => $scoring['possibleScore'],
                'percentScore' => $scoring['percentScore'],
                'passed' => $passed,
                'pendingEssayCount' => $scoring['pendingEssayCount'],
                'gradingStatus' => $scoring['pendingEssayCount'] > 0 ? 'pending_grading' : 'graded',
                'topicBreakdown' => $scoring['topicBreakdown'],
                'items' => $items,
                'antiCheatEvents' => $antiCheatEvents,
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // POST /teacher/scores/{submissionId}/grade — save (or clear) the
    // manual essay score + feedback for one question, then recompute.
    // body: { questionId, score: number|null, feedback?: string }
    // ---------------------------------------------------------------
    public function grade(Request $request, string $submissionId)
    {
        $user = $request->user();

        $questionId = (string) $request->input('questionId', '');
        if ($questionId === '') {
            return back()->with('error', 'questionId is required.');
        }

        $scoreRaw = $request->input('score');
        $score = null;
        if ($scoreRaw !== null && $scoreRaw !== '') {
            if (! is_numeric($scoreRaw)) {
                return back()->with('error', 'score must be a finite number or null.');
            }
            $score = (float) $scoreRaw;
            if ($score < 0) {
                return back()->with('error', 'Score must be 0 or greater.');
            }
        }
        $feedbackRaw = $request->input('feedback');
        $feedback = is_string($feedbackRaw) ? trim($feedbackRaw) : '';

        $submission = ExamSubmission::with(['exam' => function ($q) {
            $q->select('id', 'created_by');
        }])->find($submissionId);
        if (! $submission) {
            return back()->with('error', 'Submission not found.');
        }
        if ($user->role === 'teacher' && $submission->exam->created_by !== $user->id) {
            return back()->with('error', 'This submission is not in your account.');
        }

        $questions = ExamQuestion::where('exam_id', $submission->exam_id)
            ->orderBy('position')->get();
        $question = $questions->firstWhere('id', $questionId);
        if (! $question) {
            return back()->with('error', 'Question not found in this exam.');
        }
        if ($question->type !== 'essay') {
            return back()->with('error', 'Only essay questions can be manually scored.');
        }
        if ($score !== null && $score > (float) $question->points) {
            return back()->with('error', "Score cannot exceed the question's max of {$question->points} point(s).");
        }

        $manualScores = is_array($submission->manual_scores) ? $submission->manual_scores : [];
        if ($score === null) {
            unset($manualScores[$questionId]);
        } else {
            $manualScores[$questionId] = $score;
        }

        // Feedback lives in the spare review_items JSON column as a
        // {questionId => string} map — kept out of manual_scores so the
        // scoring engine only ever sees numbers.
        $manualFeedback = is_array($submission->review_items) ? $submission->review_items : [];
        if ($feedback === '') {
            unset($manualFeedback[$questionId]);
        } else {
            $manualFeedback[$questionId] = $feedback;
        }

        $this->recomputeAndSave($submission, $questions, $manualScores, $manualFeedback);

        return back()->with('success', 'Score saved.');
    }

    // ---------------------------------------------------------------
    // POST /teacher/grade-bulk — apply many manual essay scores at once
    // (the "Import AI scores" path). Each row is independent so a bad
    // row never rolls back the good ones.
    // body: { scores: [{ submissionId, questionId, score, feedback? }] }
    // ---------------------------------------------------------------
    public function gradeBulk(Request $request)
    {
        $user = $request->user();
        $wantsJson = $request->expectsJson();
        $rows = $request->input('scores');
        if (! is_array($rows)) {
            $msg = '`scores` must be an array of { submissionId, questionId, score }.';

            return $wantsJson ? response()->json(['error' => $msg], 400) : back()->with('error', $msg);
        }

        $applied = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $raw) {
            $sid = is_array($raw) && is_string($raw['submissionId'] ?? null) ? $raw['submissionId'] : null;
            $qid = is_array($raw) && is_string($raw['questionId'] ?? null) ? $raw['questionId'] : null;
            $scoreVal = is_array($raw) && is_numeric($raw['score'] ?? null) ? (float) $raw['score'] : null;
            $fb = is_array($raw) && is_string($raw['feedback'] ?? null) ? trim($raw['feedback']) : '';

            if (! $sid || ! $qid) {
                $errors[] = ['reason' => 'Each row needs submissionId + questionId (strings).'];
                $skipped++;
                continue;
            }
            if ($scoreVal === null || $scoreVal < 0) {
                $errors[] = ['submissionId' => $sid, 'questionId' => $qid, 'reason' => 'score must be a non-negative number.'];
                $skipped++;
                continue;
            }

            $sub = ExamSubmission::with(['exam' => fn ($q) => $q->select('id', 'created_by', 'passing_grade')])->find($sid);
            if (! $sub) {
                $errors[] = ['submissionId' => $sid, 'questionId' => $qid, 'reason' => 'Submission not found.'];
                $skipped++;
                continue;
            }
            if ($user->role === 'teacher' && $sub->exam->created_by !== $user->id) {
                $errors[] = ['submissionId' => $sid, 'questionId' => $qid, 'reason' => 'Not owner of this exam.'];
                $skipped++;
                continue;
            }

            $questions = ExamQuestion::where('exam_id', $sub->exam_id)->orderBy('position')->get();
            $q = $questions->first(fn ($qq) => $qq->id === $qid && $qq->type === 'essay');
            if (! $q) {
                $errors[] = ['submissionId' => $sid, 'questionId' => $qid, 'reason' => 'Question not found, wrong exam, or not an essay.'];
                $skipped++;
                continue;
            }

            $clamped = max(0.0, min((float) $q->points, $scoreVal));
            $manualScores = is_array($sub->manual_scores) ? $sub->manual_scores : [];
            $manualScores[$qid] = $clamped;
            $manualFeedback = is_array($sub->review_items) ? $sub->review_items : [];
            if ($fb !== '') {
                $manualFeedback[$qid] = $fb;
            }

            try {
                $this->recomputeAndSave($sub, $questions, $manualScores, $manualFeedback);
                $applied++;
            } catch (\Throwable $e) {
                $errors[] = ['submissionId' => $sid, 'questionId' => $qid, 'reason' => substr($e->getMessage(), 0, 140)];
                $skipped++;
            }
        }

        $errors = array_slice($errors, 0, 50);
        if ($wantsJson) {
            return response()->json(['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors]);
        }

        return back()->with('success', "Applied {$applied} · Skipped {$skipped}.");
    }

    // ---------------------------------------------------------------
    // POST /teacher/submissions/bulk-delete — permanently delete many
    // submissions. Teachers may only delete their own exams' rows; off-
    // limits ids are skipped, not rejected wholesale.
    // body: { ids: string[] }  (cap 200)
    // ---------------------------------------------------------------
    public function bulkDelete(Request $request)
    {
        $user = $request->user();
        $rawIds = $request->input('ids');
        if (! is_array($rawIds) || count($rawIds) === 0) {
            return back()->with('error', '`ids` array is required and must be non-empty.');
        }
        $ids = array_values(array_unique(array_filter($rawIds, fn ($id) => is_string($id) && $id !== '')));
        if (count($ids) === 0) {
            return back()->with('error', '`ids` contained no valid string ids.');
        }
        if (count($ids) > 200) {
            return back()->with('error', 'Too many ids in one request (max 200).');
        }

        $deleted = $this->deleteOwnedSubmissions($user, $ids);

        return back()->with('success', "Deleted {$deleted} submission(s).");
    }

    // ---------------------------------------------------------------
    // POST /teacher/exams/{examId}/scores/delete-all — wipe every
    // submission for one exam (the exam itself stays). examId is the
    // exam_code (matching the tree's examId), with database-id fallback.
    // ---------------------------------------------------------------
    public function deleteAllForExam(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = Exam::where('exam_code', $examId)->orWhere('id', $examId)->first();
        if (! $exam) {
            return back()->with('error', 'Exam not found.');
        }
        if ($user->role === 'teacher' && $exam->created_by !== $user->id) {
            return back()->with('error', "This exam is not in your account.");
        }

        $ids = ExamSubmission::where('exam_id', $exam->id)->pluck('id')->all();
        if (count($ids) === 0) {
            return back()->with('error', 'No submissions to delete for this exam.');
        }
        $deleted = $this->deleteOwnedSubmissions($user, $ids);

        return back()->with('success', "Deleted {$deleted} submission(s) from {$exam->exam_code}.");
    }

    // ===============================================================
    // Internals
    // ===============================================================

    /**
     * Re-run scoreExam from the canonical snapshot + key + manual scores
     * and persist the recomputed aggregates so listing pages don't have
     * to recompute. Mirrors the original grade route exactly.
     */
    private function recomputeAndSave(
        ExamSubmission $submission,
        $questions,
        array $manualScores,
        array $manualFeedback
    ): void {
        $scoring = Scoring::scoreExam(
            $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all(),
            $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
            is_array($submission->answers_snapshot) ? $submission->answers_snapshot : [],
            $manualScores
        );
        $passed = $scoring['pendingEssayCount'] === 0
            && $scoring['percentScore'] >= $submission->passing_grade;

        $submission->manual_scores = $manualScores;
        $submission->review_items = $manualFeedback;
        $submission->final_score = $scoring['finalScore'];
        $submission->possible_score = $scoring['possibleScore'];
        $submission->percent_score = $scoring['percentScore'];
        $submission->pending_essay_count = $scoring['pendingEssayCount'];
        $submission->topic_breakdown = $scoring['topicBreakdown'];
        $submission->passed = $passed;
        $submission->graded_at = $scoring['pendingEssayCount'] === 0 ? now() : null;
        $submission->save();
    }

    /**
     * Delete the subset of $ids the caller is allowed to remove (admins:
     * all; teachers: only their own exams' submissions). Returns the
     * number actually deleted.
     */
    private function deleteOwnedSubmissions(User $user, array $ids): int
    {
        $rows = ExamSubmission::with(['exam' => fn ($q) => $q->select('id', 'created_by')])
            ->whereIn('id', $ids)->get();

        $deletable = $rows->filter(
            fn ($r) => $user->role === 'admin' || ($r->exam && $r->exam->created_by === $user->id)
        )->pluck('id')->all();

        if (count($deletable) === 0) {
            return 0;
        }

        return ExamSubmission::whereIn('id', $deletable)->delete();
    }

    /**
     * Build the Exam → Class → Students tree shared by Scores,
     * AutoScore and PendingScore. Faithful port of /api/teacher/scores:
     * per-portion (auto vs essay) splits, per-class + per-exam averages,
     * tri-state selection support, and the "not submitted" roster with
     * per-student diagnostics.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildScoresTree(Request $request): array
    {
        $user = $request->user();
        $isTeacher = $user->role === 'teacher';
        $teacherFilter = $isTeacher ? null : $request->query('teacherId');

        $examQuery = Exam::query()->orderBy('name');
        if ($isTeacher) {
            $examQuery->where('created_by', $user->id);
        } elseif ($teacherFilter) {
            $examQuery->where('created_by', $teacherFilter);
        }
        $exams = $examQuery->get(['id', 'exam_code', 'name', 'passing_grade']);
        if ($exams->isEmpty()) {
            return [];
        }

        $examIds = $exams->pluck('id')->all();
        $submissions = ExamSubmission::whereIn('exam_id', $examIds)
            ->orderByDesc('submitted_at')->get();

        // Essay question points per exam — used for the auto/essay split.
        $essayQuestions = ExamQuestion::whereIn('exam_id', $examIds)
            ->where('type', 'essay')
            ->get(['id', 'exam_id', 'points']);
        $essaysByExam = [];
        foreach ($essayQuestions as $q) {
            $essaysByExam[$q->exam_id][] = ['id' => $q->id, 'points' => (float) $q->points];
        }

        // Class roster — (studentIdentifier => classId) flattened in app
        // code since class_students.student_identifier is a plain string,
        // not an FK. Admins see all classes; teachers only their own.
        $classQuery = StudentClass::query()->with('students:id,class_id,student_identifier,student_name')
            ->orderBy('name');
        if ($isTeacher) {
            $classQuery->where('created_by', $user->id);
        } elseif ($teacherFilter) {
            $classQuery->where('created_by', $teacherFilter);
        }
        $classes = $classQuery->get(['id', 'name', 'academic_year', 'created_by']);

        $classIdByStudent = [];
        $classRoster = [];
        foreach ($classes as $cls) {
            $classRoster[$cls->id] = [];
            foreach ($cls->students as $link) {
                $classRoster[$cls->id][] = [
                    'studentIdentifier' => $link->student_identifier,
                    'studentName' => $link->student_name,
                ];
                if (! isset($classIdByStudent[$link->student_identifier])) {
                    $classIdByStudent[$link->student_identifier] = $cls->id;
                }
            }
        }

        $groups = [];
        foreach ($exams as $exam) {
            $examSubs = $submissions->where('exam_id', $exam->id)->values();
            $essayQs = $essaysByExam[$exam->id] ?? [];
            $essayTotalPoints = array_sum(array_column($essayQs, 'points'));

            // Per-submission summary with the auto/essay portion split.
            $summaries = [];
            foreach ($examSubs as $s) {
                $manualScores = is_array($s->manual_scores) ? $s->manual_scores : [];
                $pendingEssayPoints = 0.0;
                $gradedEssayEarned = 0.0;
                foreach ($essayQs as $eq) {
                    $score = $manualScores[$eq['id']] ?? null;
                    if (is_numeric($score)) {
                        $gradedEssayEarned += max(0.0, min($eq['points'], (float) $score));
                    } else {
                        $pendingEssayPoints += $eq['points'];
                    }
                }
                $autoPossible = max(0.0, (float) $s->possible_score - $essayTotalPoints);
                $autoEarned = max(0.0, (float) $s->final_score - $gradedEssayEarned);
                $gradedPossible = max(0.0, $autoPossible + ($essayTotalPoints - $pendingEssayPoints));
                $gradedPercent = $gradedPossible == 0.0
                    ? 0.0
                    : round(((float) $s->final_score / $gradedPossible) * 100, 2);
                $nonEssayPercent = $autoPossible > 0
                    ? round(($autoEarned / $autoPossible) * 100, 2)
                    : 0.0;
                $essayPercent = $essayTotalPoints == 0.0
                    ? null
                    : ($pendingEssayPoints > 0
                        ? null
                        : round(($gradedEssayEarned / $essayTotalPoints) * 100, 2));
                $nonEssayPassed = $nonEssayPercent >= $exam->passing_grade;
                $essayPassed = $essayPercent === null ? null : $essayPercent >= $exam->passing_grade;

                $summaries[] = [
                    'id' => $s->id,
                    'userId' => $s->user_id,
                    'examId' => $exam->exam_code,
                    'examName' => $s->exam_name,
                    'studentName' => $s->full_name,
                    'username' => $s->username,
                    'finalScore' => (float) $s->final_score,
                    'possibleScore' => (float) $s->possible_score,
                    'percentScore' => (float) $s->percent_score,
                    'passed' => (bool) $s->passed,
                    'pendingEssayCount' => (int) $s->pending_essay_count,
                    'autoEarned' => round($autoEarned, 2),
                    'autoPossible' => round($autoPossible, 2),
                    'essayEarned' => round($gradedEssayEarned, 2),
                    'pendingEssayPoints' => round($pendingEssayPoints, 2),
                    'essayTotalPoints' => round($essayTotalPoints, 2),
                    'gradedPercent' => $gradedPercent,
                    'nonEssayPercent' => $nonEssayPercent,
                    'essayPercent' => $essayPercent,
                    'nonEssayPassed' => $nonEssayPassed,
                    'essayPassed' => $essayPassed,
                    'gradingStatus' => $s->pending_essay_count > 0 ? 'pending_grading' : 'graded',
                    'submittedAt' => $s->submitted_at?->toIso8601String(),
                ];
            }

            // Bucket by class (by submitter uid so multiple attempts land
            // together + count as one student).
            $byClass = [];
            $studentsByClass = [];
            foreach ($examSubs as $i => $sub) {
                $classId = $classIdByStudent[$sub->user_id] ?? null;
                $key = $classId ?? '__none__';
                $byClass[$key][] = $summaries[$i];
                $studentsByClass[$key][$sub->user_id] = true;
            }

            // Diagnostics for not-submitted roster students (one pass per
            // exam): login time + session state, so the teacher can tell
            // "never opened" from "opened but autosave never landed".
            $notSubmittedIds = [];
            foreach ($classes as $cls) {
                $submitted = $studentsByClass[$cls->id] ?? [];
                foreach ($classRoster[$cls->id] ?? [] as $r) {
                    if (! isset($submitted[$r['studentIdentifier']])) {
                        $notSubmittedIds[$r['studentIdentifier']] = true;
                    }
                }
            }
            $diagByUser = $this->buildNotSubmittedDiagnostics($exam->id, array_keys($notSubmittedIds));

            $classGroups = [];
            foreach ($classes as $cls) {
                $key = $cls->id;
                $subs = $byClass[$key] ?? [];
                $submitted = $studentsByClass[$key] ?? [];
                $roster = $classRoster[$cls->id] ?? [];

                $notSubmittedRaw = array_values(array_filter(
                    $roster,
                    fn ($r) => ! isset($submitted[$r['studentIdentifier']])
                ));
                usort($notSubmittedRaw, fn ($a, $b) => strcmp($a['studentName'], $b['studentName']));
                $notSubmitted = array_map(function ($r) use ($diagByUser) {
                    $d = $diagByUser[$r['studentIdentifier']] ?? null;

                    return [
                        'studentIdentifier' => $r['studentIdentifier'],
                        'studentName' => $r['studentName'],
                        'username' => $d['username'] ?? null,
                        'lastSignInAt' => $d['lastSignInAt'] ?? null,
                        'sessionStartedAt' => $d['sessionStartedAt'] ?? null,
                        'sessionLastSavedAt' => $d['sessionLastSavedAt'] ?? null,
                        'sessionStatus' => $d['sessionStatus'] ?? null,
                        'sessionTimeUsedSeconds' => $d['sessionTimeUsedSeconds'] ?? 0,
                        'sessionAntiCheatEventCount' => $d['sessionAntiCheatEventCount'] ?? 0,
                        'sessionDraftCount' => $d['sessionDraftCount'] ?? 0,
                        'sessionId' => $d['sessionId'] ?? null,
                    ];
                }, $notSubmittedRaw);

                if (count($subs) === 0 && count($notSubmitted) === 0) {
                    continue;
                }
                $summary = $this->summariseSubs($subs);
                $classGroups[] = [
                    'classId' => $cls->id,
                    'className' => $cls->name,
                    'academicYear' => $cls->academic_year,
                    'studentCount' => count($submitted),
                    'submissionCount' => count($subs),
                    'passedCount' => $summary['passedCount'],
                    'pendingCount' => $summary['pendingCount'],
                    'averagePercent' => $summary['averagePercent'],
                    'notSubmittedCount' => count($notSubmitted),
                    'notSubmitted' => $notSubmitted,
                    'rosterSize' => count($roster),
                    'submissions' => $subs,
                ];
            }

            // Trailing "No class" bucket for submitters not on any roster.
            $orphan = $byClass['__none__'] ?? [];
            if (count($orphan) > 0) {
                $summary = $this->summariseSubs($orphan);
                $classGroups[] = [
                    'classId' => null,
                    'className' => 'No class',
                    'academicYear' => null,
                    'studentCount' => count($studentsByClass['__none__'] ?? []) ?: count($orphan),
                    'submissionCount' => count($orphan),
                    'passedCount' => $summary['passedCount'],
                    'pendingCount' => $summary['pendingCount'],
                    'averagePercent' => $summary['averagePercent'],
                    'notSubmittedCount' => 0,
                    'notSubmitted' => [],
                    'rosterSize' => count($orphan),
                    'submissions' => $orphan,
                ];
            }

            $examSummary = $this->summariseSubs($summaries);
            $groups[] = [
                'examDatabaseId' => $exam->id,
                'examId' => $exam->exam_code,
                'examName' => $exam->name,
                'passingGrade' => $exam->passing_grade,
                'totalSubmissions' => count($summaries),
                'pendingCount' => $examSummary['pendingCount'],
                'passedCount' => $examSummary['passedCount'],
                'averagePercent' => $examSummary['averagePercent'],
                'classes' => $classGroups,
            ];
        }

        return $groups;
    }

    /**
     * passedCount / pendingCount / averagePercent for a set of summaries.
     *
     * @param  array<int,array<string,mixed>>  $subs
     * @return array{passedCount:int,pendingCount:int,averagePercent:float|null}
     */
    private function summariseSubs(array $subs): array
    {
        $passedCount = 0;
        $pendingCount = 0;
        $sum = 0.0;
        foreach ($subs as $s) {
            if ($s['passed']) {
                $passedCount++;
            }
            if ($s['gradingStatus'] === 'pending_grading') {
                $pendingCount++;
            }
            $sum += $s['percentScore'];
        }
        $averagePercent = count($subs) === 0 ? null : round($sum / count($subs), 2);

        return ['passedCount' => $passedCount, 'pendingCount' => $pendingCount, 'averagePercent' => $averagePercent];
    }

    /**
     * For each not-submitted student, fetch the login + most-recent
     * session timeline for this exam.
     *
     * @param  array<int,string>  $userIds
     * @return array<string,array<string,mixed>>
     */
    private function buildNotSubmittedDiagnostics(string $examId, array $userIds): array
    {
        if (count($userIds) === 0) {
            return [];
        }

        $users = User::with('credential:user_id,last_sign_in_at')
            ->whereIn('id', $userIds)
            ->get(['id', 'username']);
        $userInfo = [];
        foreach ($users as $u) {
            $userInfo[$u->id] = [
                'username' => $u->username,
                'lastSignInAt' => $u->credential?->last_sign_in_at?->toIso8601String(),
            ];
        }

        $sessions = ExamSession::withCount('drafts')
            ->where('exam_id', $examId)
            ->whereIn('user_id', $userIds)
            ->orderByDesc('attempt')
            ->get();
        $sessionByUser = [];
        foreach ($sessions as $s) {
            if (! isset($sessionByUser[$s->user_id])) {
                $sessionByUser[$s->user_id] = $s;
            }
        }

        $out = [];
        foreach ($userIds as $id) {
            $u = $userInfo[$id] ?? null;
            $s = $sessionByUser[$id] ?? null;
            $events = is_array($s?->anti_cheat_events) ? count($s->anti_cheat_events) : 0;
            $out[$id] = [
                'username' => $u['username'] ?? '?',
                'lastSignInAt' => $u['lastSignInAt'] ?? null,
                'sessionStartedAt' => $s?->started_at?->toIso8601String(),
                'sessionLastSavedAt' => $s?->last_saved_at?->toIso8601String(),
                'sessionStatus' => $s?->status,
                'sessionTimeUsedSeconds' => (int) ($s?->time_used_seconds ?? 0),
                'sessionAntiCheatEventCount' => $events,
                'sessionDraftCount' => (int) ($s?->drafts_count ?? 0),
                'sessionId' => $s?->id,
            ];
        }

        return $out;
    }

    /**
     * Pre-build the per-exam + per-submission "Export for AI" markdown
     * bundles for the Pending Score page. Faithful port of the original
     * /ai-export endpoint: self-contained instructions + per-essay prompt
     * + mark scheme + student answer + max points, so the teacher can
     * paste it into Claude/ChatGPT and feed the JSON back via gradeBulk.
     *
     * Keyed by exam database id and by submission id so the page can copy
     * either the whole-exam bundle or a single student's.
     *
     * @param  array<int,array<string,mixed>>  $groups
     * @return array{byExam:array<string,string>,bySubmission:array<string,string>}
     */
    private function buildAiExports(Request $request, array $groups): array
    {
        $byExam = [];
        $bySubmission = [];

        foreach ($groups as $group) {
            // Only exams that actually have a pending essay somewhere.
            $hasPending = false;
            foreach ($group['classes'] as $cls) {
                foreach ($cls['submissions'] as $sub) {
                    if (($sub['pendingEssayCount'] ?? 0) > 0) {
                        $hasPending = true;
                        break 2;
                    }
                }
            }
            if (! $hasPending) {
                continue;
            }

            $examDbId = $group['examDatabaseId'];
            $essayQuestions = ExamQuestion::where('exam_id', $examDbId)
                ->where('type', 'essay')
                ->orderBy('position')
                ->get(['id', 'position', 'points', 'prompt', 'explanation_text']);
            if ($essayQuestions->isEmpty()) {
                continue;
            }

            // Flatten this exam's pending submissions (any class).
            $pendingSubs = [];
            foreach ($group['classes'] as $cls) {
                foreach ($cls['submissions'] as $sub) {
                    if (($sub['pendingEssayCount'] ?? 0) > 0) {
                        $pendingSubs[$sub['id']] = $sub;
                    }
                }
            }
            if (count($pendingSubs) === 0) {
                continue;
            }

            $rows = ExamSubmission::whereIn('id', array_keys($pendingSubs))
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'username', 'manual_scores', 'answers_snapshot']);

            // Whole-exam bundle.
            $byExam[$examDbId] = $this->renderAiBundle(
                $group['examId'],
                $group['examName'],
                $essayQuestions,
                $rows
            );

            // Per-submission bundle (one student each).
            foreach ($rows as $row) {
                $bySubmission[$row->id] = $this->renderAiBundle(
                    $group['examId'],
                    $group['examName'],
                    $essayQuestions,
                    collect([$row])
                );
            }
        }

        return ['byExam' => $byExam, 'bySubmission' => $bySubmission];
    }

    /**
     * Render one AI-grading markdown bundle for a set of submissions.
     */
    private function renderAiBundle(string $examCode, string $examName, $essayQuestions, $submissions): string
    {
        $lines = [];
        $lines[] = "# AI grading request: {$examCode} — {$examName}";
        $lines[] = '';
        $lines[] = '## How to use this';
        $lines[] = '';
        $lines[] = '1. Paste this ENTIRE document into Claude or ChatGPT.';
        $lines[] = '2. The AI will grade every essay below against the mark scheme.';
        $lines[] = "3. Copy the JSON array it returns and paste it into the dashboard's **Import scores** dialog.";
        $lines[] = '';
        $lines[] = '## Instructions for the AI';
        $lines[] = '';
        $lines[] = 'You are grading essay-style exam answers. For each `### Q…` block I give you:';
        $lines[] = '';
        $lines[] = '- the question **prompt**';
        $lines[] = '- the **mark scheme** (what a full-credit answer looks like)';
        $lines[] = "- the **student's answer** (may contain LaTeX in `\$\$ … \$\$` or `\$ … \$`)";
        $lines[] = '- the **max points** for that question';
        $lines[] = '';
        $lines[] = 'Award an integer score from 0 to `maxPoints` based on how well the student answer matches the mark scheme. Be fair to partial credit. After ALL essays have been scored, output STRICTLY this JSON array and nothing else (no prose before or after):';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = '[';
        $lines[] = '  { "submissionId": "<id>", "questionId": "<id>", "score": <int>, "feedback": "<one sentence>" }';
        $lines[] = ']';
        $lines[] = '```';
        $lines[] = '';

        $totalEssays = 0;
        $subCount = 0;
        foreach ($submissions as $sub) {
            $snap = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];
            $manual = is_array($sub->manual_scores) ? $sub->manual_scores : [];

            $pendingEssays = $essayQuestions->filter(function ($q) use ($manual) {
                $ms = $manual[$q->id] ?? null;

                return ! is_numeric($ms);
            });
            if ($pendingEssays->isEmpty()) {
                continue;
            }
            $subCount++;

            $lines[] = '---';
            $lines[] = '';
            $lines[] = "## {$sub->full_name} ({$sub->username}) — submissionId `{$sub->id}`";
            $lines[] = '';
            foreach ($pendingEssays as $q) {
                $totalEssays++;
                $studentAnswer = $snap[$q->id] ?? null;
                $answerText = is_string($studentAnswer) && trim($studentAnswer) !== ''
                    ? $studentAnswer
                    : '_(no answer provided)_';
                $lines[] = "### Q{$q->position} — questionId `{$q->id}` — maxPoints **{$q->points}**";
                $lines[] = '';
                $lines[] = '**Prompt**';
                $lines[] = '';
                $lines[] = '> ' . str_replace("\n", "\n> ", $q->prompt);
                $lines[] = '';
                $lines[] = '**Mark scheme**';
                $lines[] = '';
                $markScheme = $q->explanation_text ?: '_(no explicit mark scheme on this question — judge holistically)_';
                $lines[] = '> ' . str_replace("\n", "\n> ", $markScheme);
                $lines[] = '';
                $lines[] = "**Student's answer**";
                $lines[] = '';
                $lines[] = '```';
                $lines[] = $answerText;
                $lines[] = '```';
                $lines[] = '';
            }
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = "_Bundle contains {$subCount} submission(s), {$totalEssays} pending essay(s) for **{$examCode}**. Paste the AI's JSON output into the dashboard's Import scores dialog when done._";

        return implode("\n", $lines);
    }
}
