<?php

namespace App\Http\Controllers;

use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Support\Scoring;
use App\Support\Shuffle;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SubmissionController extends Controller
{
    /** GET /student/scores — list of the student's own submissions. */
    public function studentScores(Request $request)
    {
        $user = $request->user();
        $rows = ExamSubmission::with('exam:id,exam_code')
            ->where('user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->get();

        $sessionIds = $rows->pluck('session_id')->filter()->all();
        $startedAt = $sessionIds
            ? ExamSession::whereIn('id', $sessionIds)->pluck('started_at', 'id')
            : collect();

        $submissions = $rows->map(fn ($r) => [
            'id' => $r->id,
            'examId' => $r->exam?->exam_code,
            'examName' => $r->exam_name,
            'startedAt' => $r->session_id && $startedAt->has($r->session_id)
                ? $startedAt->get($r->session_id)->toIso8601String() : null,
            'submittedAt' => $r->submitted_at->toIso8601String(),
            'finalScore' => $r->final_score,
            'possibleScore' => $r->possible_score,
            'percentScore' => $r->percent_score,
            'passed' => (bool) $r->passed,
            'pendingEssayCount' => $r->pending_essay_count,
            'gradingStatus' => $r->pending_essay_count > 0 ? 'pending_grading' : 'graded',
        ])->all();

        return Inertia::render('student/Scores', ['submissions' => $submissions]);
    }

    /** GET /results/{id} — immediate post-submit result (non-essay vs essay split). */
    public function result(Request $request, string $id)
    {
        $user = $request->user();
        $submission = ExamSubmission::with('exam:id,exam_code,exam_mode')->find($id);
        if (! $submission) {
            abort(404, 'Submission not found.');
        }
        if ($submission->user_id !== $user->id && $user->role !== 'admin') {
            abort(403, 'You cannot access this result.');
        }

        $questions = ExamQuestion::where('exam_id', $submission->exam_id)
            ->get(['id', 'topic', 'points', 'correct_answer', 'type']);
        $answers = is_array($submission->answers_snapshot) ? $submission->answers_snapshot : [];
        $manual = is_array($submission->manual_scores) ? $submission->manual_scores : [];

        $scoring = Scoring::scoreExam(
            $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all(),
            $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
            $answers,
            $manual
        );

        $byId = $questions->keyBy('id');
        $autoEarned = 0;
        $autoPossible = 0;
        $essayEarned = 0;
        $essayPossible = 0;
        $essayPending = 0;
        foreach ($scoring['itemResults'] as $it) {
            $isEssay = ($byId[$it['questionId']]->type ?? null) === 'essay';
            if ($isEssay) {
                $essayPossible += $it['possible'];
                if ($it['requiresGrading']) {
                    $essayPending++;
                } else {
                    $essayEarned += $it['awarded'];
                }
            } else {
                $autoPossible += $it['possible'];
                $autoEarned += $it['awarded'];
            }
        }

        return Inertia::render('results/Show', [
            'result' => [
                'id' => $submission->id,
                'examId' => $submission->exam->exam_code,
                'examName' => $submission->exam_name,
                'examMode' => $submission->exam->exam_mode,
                'submittedAt' => $submission->submitted_at->toIso8601String(),
                'finalScore' => $scoring['finalScore'],
                'possibleScore' => $scoring['possibleScore'],
                'percentScore' => $scoring['percentScore'],
                'passingGrade' => $submission->passing_grade,
                'passed' => $scoring['pendingEssayCount'] === 0 && $scoring['percentScore'] >= $submission->passing_grade,
                'topicBreakdown' => $scoring['topicBreakdown'],
                'autoEarned' => round($autoEarned, 2),
                'autoPossible' => round($autoPossible, 2),
                'autoPct' => $autoPossible > 0 ? round($autoEarned / $autoPossible * 100, 2) : 0,
                'essayEarned' => round($essayEarned, 2),
                'essayPossible' => round($essayPossible, 2),
                'essayPct' => $essayPossible > 0 ? round($essayEarned / $essayPossible * 100, 2) : 0,
                'essayPendingCount' => $essayPending,
            ],
        ]);
    }

    /** GET /student/scores/{id} — full review of one submission (delayed key release). */
    public function studentScoreDetail(Request $request, string $id)
    {
        $user = $request->user();
        $submission = ExamSubmission::with(['exam' => function ($q) {
            $q->select('id', 'exam_code', 'end_time', 'shuffle_questions');
        }])->find($id);

        if (! $submission) {
            abort(404, 'Submission not found.');
        }
        if ($submission->user_id !== $user->id && $user->role !== 'admin') {
            abort(403, 'You cannot access this submission.');
        }

        // Anti-leak gate: mid-attempt students can't fish the answer key.
        if ($user->role === 'student') {
            $examEnded = $submission->exam->end_time && $submission->exam->end_time->lte(now());
            if (! $examEnded) {
                $activeDraft = ExamSession::where('user_id', $user->id)
                    ->where('exam_id', $submission->exam_id)
                    ->where('status', 'draft')->exists();
                if ($activeDraft) {
                    return redirect('/student/scores')
                        ->with('error', 'You can review this submission after you finish your current attempt.');
                }
            }
        }

        $questions = ExamQuestion::with('media')
            ->where('exam_id', $submission->exam_id)
            ->orderBy('position')->get();

        $answersSnapshot = is_array($submission->answers_snapshot) ? $submission->answers_snapshot : [];
        $manualScores = is_array($submission->manual_scores) ? $submission->manual_scores : [];

        $scoring = Scoring::scoreExam(
            $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all(),
            $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
            $answersSnapshot,
            $manualScores
        );
        $itemResults = collect($scoring['itemResults'])->keyBy('questionId');

        $items = $questions->map(function ($q) use ($itemResults, $answersSnapshot) {
            $item = $itemResults->get($q->id);
            $isEssay = $q->type === 'essay';

            return [
                'question' => [
                    'id' => $q->id, 'position' => $q->position, 'type' => $q->type,
                    'topic' => $q->topic, 'tags' => $q->tags ?? [], 'prompt' => $q->prompt,
                    'options' => $q->options, 'points' => $q->points,
                ],
                'media' => $q->media->map(fn ($m) => [
                    'id' => $m->id, 'questionId' => $m->question_id, 'type' => $m->type,
                    'url' => $m->url, 'altText' => $m->alt_text, 'caption' => $m->caption,
                ])->all(),
                'studentAnswer' => $answersSnapshot[$q->id] ?? null,
                'correctAnswer' => $q->correct_answer,
                'explanationText' => $q->explanation_text ?? '',
                'isAutoGraded' => ! $isEssay,
                'isCorrect' => $item['isCorrect'] ?? false,
                'awarded' => $item['awarded'] ?? 0,
                'possible' => $item['possible'] ?? (float) $q->points,
                'requiresGrading' => $item['requiresGrading'] ?? false,
            ];
        })->all();

        // Reorder to match what the student saw when shuffled.
        if ($submission->exam->shuffle_questions && $submission->session_id) {
            $nonEssay = array_values(array_filter($items, fn ($it) => $it['question']['type'] !== 'essay'));
            $essay = array_values(array_filter($items, fn ($it) => $it['question']['type'] === 'essay'));
            $items = array_merge(Shuffle::withSeed($nonEssay, $submission->session_id . '::q'), $essay);
        }

        $startedAt = null;
        if ($submission->session_id) {
            $sess = ExamSession::find($submission->session_id);
            $startedAt = $sess?->started_at->toIso8601String();
        }

        $passed = $scoring['pendingEssayCount'] === 0 && $scoring['percentScore'] >= $submission->passing_grade;

        return Inertia::render('student/ScoreDetail', [
            'submission' => [
                'id' => $submission->id,
                'examId' => $submission->exam->exam_code,
                'examName' => $submission->exam_name,
                'passingGrade' => $submission->passing_grade,
                'startedAt' => $startedAt,
                'submittedAt' => $submission->submitted_at->toIso8601String(),
                'finalScore' => $scoring['finalScore'],
                'possibleScore' => $scoring['possibleScore'],
                'percentScore' => $scoring['percentScore'],
                'passed' => $passed,
                'pendingEssayCount' => $scoring['pendingEssayCount'],
                'gradingStatus' => $scoring['pendingEssayCount'] > 0 ? 'pending_grading' : 'graded',
                'topicBreakdown' => $scoring['topicBreakdown'],
                'items' => $items,
            ],
        ]);
    }
}
