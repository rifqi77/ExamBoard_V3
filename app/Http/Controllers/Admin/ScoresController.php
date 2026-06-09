<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
 * Admin Scores — school-wide oversight that mirrors the teacher Scores page
 * but adds a teacher grouping layer on top:  Teacher → Exam → Class →
 * Students.  The original admin Scores client only listed admin-OWNED exams
 * flat; this faithful-but-richer port spans every teacher's exams (admin
 * sees ALL data) with the same per-portion (auto vs essay) splits, per-class
 * + per-exam averages, "not submitted" roster diagnostics, and bulk delete
 * the teacher console has — just organised by teacher.
 *
 * A ?teacherId= query (the shared teacher picker) narrows the whole tree to
 * one teacher's exams (Exam.created_by = teacherId). When scoped, the single
 * teacher group is still emitted so the page renders identically.
 *
 * Submission detail + grading reuse the same Scoring engine + recompute path
 * as the teacher controller; grading links live under /admin/scores/{id}.
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
    // GET /admin/scores — Teacher → Exam → Class → Students tree.
    // Optional ?teacherId= scopes to one teacher.
    // ---------------------------------------------------------------
    public function index(Request $request)
    {
        $teacherFilter = $request->query('teacherId');
        $teacherFilter = is_string($teacherFilter) && $teacherFilter !== '' ? $teacherFilter : null;

        return Inertia::render('admin/Scores', [
            'teacherGroups' => $this->buildTeacherTree($teacherFilter),
            'teachers' => $this->teacherOptions(),
            'teacherId' => $teacherFilter,
        ]);
    }

    // ---------------------------------------------------------------
    // GET /admin/scores/{submissionId} — full grading detail (admin sees
    // every submission). Mirrors Teacher\ScoresController::show.
    // ---------------------------------------------------------------
    public function show(Request $request, string $submissionId)
    {
        $submission = ExamSubmission::with(['exam' => function ($q) {
            $q->select('id', 'exam_code', 'subject', 'created_by', 'created_by_name');
        }])->find($submissionId);

        if (! $submission) {
            abort(404, 'Submission not found.');
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

        return Inertia::render('admin/ScoreDetail', [
            'submission' => [
                'id' => $submission->id,
                'examDatabaseId' => $submission->exam->id,
                'examId' => $submission->exam->exam_code,
                'examName' => $submission->exam_name,
                'examSubject' => $submission->exam->subject ?? '',
                'teacherName' => $submission->exam->created_by_name ?? '',
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
    // POST /admin/scores/{submissionId}/grade — save / clear one essay's
    // manual score + feedback, then recompute. Admin can grade any exam.
    // body: { questionId, score: number|null, feedback?: string }
    // ---------------------------------------------------------------
    public function grade(Request $request, string $submissionId)
    {
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

        $submission = ExamSubmission::find($submissionId);
        if (! $submission) {
            return back()->with('error', 'Submission not found.');
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
    // POST /admin/submissions/bulk-delete — delete many submissions.
    // Admin may delete any. body: { ids: string[] } (cap 200)
    // ---------------------------------------------------------------
    public function bulkDelete(Request $request)
    {
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

        $deleted = ExamSubmission::whereIn('id', $ids)->delete();

        return back()->with('success', "Deleted {$deleted} submission(s).");
    }

    // ---------------------------------------------------------------
    // POST /admin/exams/{examId}/scores/delete-all — wipe every submission
    // for one exam (the exam stays). examId is the exam_code, db-id fallback.
    // ---------------------------------------------------------------
    public function deleteAllForExam(Request $request, string $examId)
    {
        $exam = Exam::where('exam_code', $examId)->orWhere('id', $examId)->first();
        if (! $exam) {
            return back()->with('error', 'Exam not found.');
        }

        $count = ExamSubmission::where('exam_id', $exam->id)->count();
        if ($count === 0) {
            return back()->with('error', 'No submissions to delete for this exam.');
        }
        $deleted = ExamSubmission::where('exam_id', $exam->id)->delete();

        return back()->with('success', "Deleted {$deleted} submission(s) from {$exam->exam_code}.");
    }

    // ===============================================================
    // Internals
    // ===============================================================

    /**
     * Build the Teacher → Exam → Class → Students tree. Each exam group has
     * the SAME shape the teacher Scores page consumes (per-portion splits,
     * per-class averages, not-submitted roster + diagnostics), wrapped in a
     * teacher group with school-wide rollups.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildTeacherTree(?string $teacherFilter): array
    {
        // Exams in scope, with owner info so we can group by teacher.
        $examQuery = Exam::query()->orderBy('name');
        if ($teacherFilter) {
            $examQuery->where('created_by', $teacherFilter);
        }
        $exams = $examQuery->get(['id', 'exam_code', 'name', 'passing_grade', 'created_by', 'created_by_name']);
        if ($exams->isEmpty()) {
            return [];
        }

        $examIds = $exams->pluck('id')->all();
        $submissions = ExamSubmission::whereIn('exam_id', $examIds)
            ->orderByDesc('submitted_at')->get();

        // Essay question points per exam — for the auto/essay split.
        $essayQuestions = ExamQuestion::whereIn('exam_id', $examIds)
            ->where('type', 'essay')
            ->get(['id', 'exam_id', 'points']);
        $essaysByExam = [];
        foreach ($essayQuestions as $q) {
            $essaysByExam[$q->exam_id][] = ['id' => $q->id, 'points' => (float) $q->points];
        }

        // Class roster (admin sees all classes). Flattened studentIdentifier
        // => classId since class_students.student_identifier is a plain string.
        $classes = StudentClass::query()
            ->with('students:id,class_id,student_identifier,student_name')
            ->orderBy('name')
            ->get(['id', 'name', 'academic_year', 'created_by']);

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

        // Build the per-exam groups first (identical shape to teacher tree).
        $examGroups = [];
        foreach ($exams as $exam) {
            $examGroups[] = $this->buildExamGroup($exam, $submissions, $essaysByExam, $classes, $classRoster, $classIdByStudent);
        }

        // Now bucket exam groups by their owning teacher.
        $teacherIds = $exams->pluck('created_by')->filter()->unique()->values()->all();
        $teacherRows = User::whereIn('id', $teacherIds)->get(['id', 'full_name', 'subject', 'active'])->keyBy('id');

        $byTeacher = [];
        foreach ($exams as $i => $exam) {
            $tid = $exam->created_by ?? '__none__';
            $byTeacher[$tid][] = $examGroups[$i];
        }

        $teacherGroups = [];
        foreach ($byTeacher as $tid => $groups) {
            $teacher = $tid === '__none__' ? null : ($teacherRows[$tid] ?? null);
            $totalSubmissions = array_sum(array_map(fn ($g) => $g['totalSubmissions'], $groups));
            $pendingCount = array_sum(array_map(fn ($g) => $g['pendingCount'], $groups));
            $passedCount = array_sum(array_map(fn ($g) => $g['passedCount'], $groups));

            $teacherGroups[] = [
                'teacherId' => $tid === '__none__' ? null : $tid,
                'teacherName' => $teacher?->full_name
                    ?? ($groups[0]['ownerName'] ?? 'Unknown teacher'),
                'teacherSubject' => $teacher?->subject,
                'teacherActive' => $teacher ? (bool) $teacher->active : null,
                'examCount' => count($groups),
                'totalSubmissions' => $totalSubmissions,
                'pendingCount' => $pendingCount,
                'passedCount' => $passedCount,
                'exams' => $groups,
            ];
        }

        // Teachers with the most pending grading float to the top; ties by name.
        usort($teacherGroups, function ($a, $b) {
            if ($b['pendingCount'] !== $a['pendingCount']) {
                return $b['pendingCount'] <=> $a['pendingCount'];
            }

            return strcasecmp((string) $a['teacherName'], (string) $b['teacherName']);
        });

        return $teacherGroups;
    }

    /**
     * One exam's class tree (faithful port of the teacher tree's per-exam
     * branch, including the not-submitted roster + diagnostics).
     *
     * @return array<string,mixed>
     */
    private function buildExamGroup($exam, $submissions, array $essaysByExam, $classes, array $classRoster, array $classIdByStudent): array
    {
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

        // Bucket by class (by submitter uid).
        $byClass = [];
        $studentsByClass = [];
        foreach ($examSubs as $i => $sub) {
            $classId = $classIdByStudent[$sub->user_id] ?? null;
            $key = $classId ?? '__none__';
            $byClass[$key][] = $summaries[$i];
            $studentsByClass[$key][$sub->user_id] = true;
        }

        // Not-submitted roster diagnostics (one pass per exam).
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

        // Trailing "No class" bucket.
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

        return [
            'examDatabaseId' => $exam->id,
            'examId' => $exam->exam_code,
            'examName' => $exam->name,
            'passingGrade' => $exam->passing_grade,
            'ownerName' => $exam->created_by_name,
            'totalSubmissions' => count($summaries),
            'pendingCount' => $examSummary['pendingCount'],
            'passedCount' => $examSummary['passedCount'],
            'averagePercent' => $examSummary['averagePercent'],
            'classes' => $classGroups,
        ];
    }

    /**
     * Re-run scoreExam from the canonical snapshot + key + manual scores and
     * persist the recomputed aggregates. Identical to the teacher controller.
     */
    private function recomputeAndSave(ExamSubmission $submission, $questions, array $manualScores, array $manualFeedback): void
    {
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
     * For each not-submitted student, fetch the login + most-recent session
     * timeline for this exam. Identical to the teacher controller.
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
     * Teacher options for the scope picker.
     *
     * @return array<int,array<string,mixed>>
     */
    private function teacherOptions(): array
    {
        return User::query()
            ->where('role', 'teacher')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'subject', 'active'])
            ->map(fn ($t) => [
                'userId' => $t->id,
                'fullName' => $t->full_name,
                'subject' => $t->subject,
                'active' => (bool) $t->active,
            ])->all();
    }
}
