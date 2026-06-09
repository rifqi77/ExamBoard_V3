<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\BankController as TeacherBankController;
use App\Http\Controllers\Teacher\LearningObjectiveController as TeacherLOController;
use App\Http\Controllers\Teacher\ScoresController as TeacherScoresController;
use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Models\LearningObjective;
use App\Models\StudentClass;
use App\Models\User;
use App\Support\Subjects;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admin content oversight — school-wide. These pages mirror the teacher
 * Bank / Curriculum / Auto-Score / Pending-Score views, but admins are
 * never scoped by uploaded_by / created_by — they see every teacher's
 * contributions (the original admin pages re-rendered the teacher clients
 * because /api/teacher/* already returns the whole database for admins).
 *
 * The query logic is the same shape the Teacher\BankController,
 * Teacher\LearningObjectiveController and Teacher\ScoresController use; the
 * admin variants just drop the per-user WHERE clauses (and add the
 * per-teacher scope picker on Auto / Pending).
 */
class ContentController extends Controller
{
    // Bloom's revised taxonomy + olympiad.
    private const DIFFICULTIES = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'];

    private const TYPES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

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

    // =================================================================
    // QUESTION BANK — GET /admin/bank  (school-wide)
    // =================================================================

    /**
     * Whole-database question bank. Same subject→topic→subtopic→difficulty
     * →media tree + 6 filters + search as the teacher page, just unscoped.
     * isAdmin=true so the page shows the collapsible subject roots and
     * marks every row manageable.
     */
    public function bank(Request $request)
    {
        $f = [
            'language' => $this->trimOrNull($request->query('language')),
            'subject' => $this->trimOrNull($request->query('subject')),
            'topic' => $this->trimOrNull($request->query('topic')),
            'subtopic' => $this->trimOrNull($request->query('subtopic')),
            'difficulty' => $this->oneOf($request->query('difficulty'), self::DIFFICULTIES),
            'type' => $this->oneOf($request->query('type'), self::TYPES),
        ];
        $search = $this->trimOrNull($request->query('search'));

        $base = BankQuestion::query(); // admin: no uploaded_by scope

        $rows = (clone $base)
            ->when($f['language'], fn ($q, $v) => $q->where('language', $v))
            ->when($f['subject'], fn ($q, $v) => $q->where('subject', $v))
            ->when($f['topic'], fn ($q, $v) => $q->where('topic', $v))
            ->when($f['subtopic'], fn ($q, $v) => $q->where('subtopic', $v))
            ->when($f['difficulty'], fn ($q, $v) => $q->where('difficulty', $v))
            ->when($f['type'], fn ($q, $v) => $q->where('type', $v))
            ->when($search, fn ($q, $v) => $q->where(function ($w) use ($v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%';
                $w->where('prompt', 'like', $like)
                    ->orWhere('topic', 'like', $like)
                    ->orWhere('subtopic', 'like', $like);
            }))
            ->orderByDesc('created_at')
            ->get();

        $questions = $rows->map(fn (BankQuestion $q) => [
            'id' => $q->id,
            'type' => $q->type,
            'language' => $q->language ?? '',
            'subject' => $q->subject ?? '',
            'topic' => $q->topic,
            'subtopic' => $q->subtopic,
            'difficulty' => $q->difficulty ?? 'understand',
            'tags' => is_array($q->tags) ? $q->tags : [],
            'prompt' => $q->prompt,
            'options' => is_array($q->options) ? $q->options : null,
            'points' => (float) $q->points,
            'correctAnswer' => $q->correct_answer,
            'explanationText' => $q->explanation_text ?? '',
            'createdByName' => $q->created_by_name ?? '(unknown)',
            'uploadedBy' => $q->uploaded_by,
            'uploadedByName' => $q->uploaded_by_name,
            'createdAt' => optional($q->created_at)->toIso8601String(),
            'sourceFileName' => $q->source_file_name,
            'mediaUrl' => $q->media_url,
            'mediaType' => $q->media_type,
            'canManage' => true, // admin can manage every row
        ])->values();

        return Inertia::render('admin/Bank', [
            'questions' => $questions,
            'topicOrder' => $this->bankTopicOrder(),
            'filterOptions' => $this->buildFilterOptions($base),
            'filters' => array_filter([
                ...$f,
                'search' => $search,
            ], fn ($v) => $v !== null && $v !== ''),
            'isAdmin' => true,
            'lockedSubject' => null,
            'subjectChoices' => Subjects::mergeWithExisting(
                BankQuestion::query()->whereNotNull('subject')->distinct()->pluck('subject')->all()
            ),
        ]);
    }

    // ---- Bank writes (delegate to the shared bank controller — admin
    // bypasses the uploaded_by ownership checks; back() lands on /admin/bank).

    /** POST /admin/bank — create a bank question (admin owns the database). */
    public function bankStore(Request $request)
    {
        return app(TeacherBankController::class)->store($request);
    }

    /** PUT /admin/bank/{id} — edit any bank question. */
    public function bankUpdate(Request $request, string $id)
    {
        return app(TeacherBankController::class)->update($request, $id);
    }

    /** DELETE /admin/bank/{id} — delete any bank question. */
    public function bankDestroy(Request $request, string $id)
    {
        return app(TeacherBankController::class)->destroy($request, $id);
    }

    /** POST /admin/bank/upload — bulk import (zip / xlsx). */
    public function bankUpload(Request $request)
    {
        return app(TeacherBankController::class)->upload($request);
    }

    // =================================================================
    // CURRICULUM — GET /admin/learning-objectives  (school-wide)
    // =================================================================

    /**
     * Whole-catalog Learning Objectives manager. Same four-curriculum
     * tabbed tree + inline add/edit/delete + Excel import preview as the
     * teacher page, unscoped (every uploaded LO across teachers).
     */
    public function learningObjectives(Request $request)
    {
        $user = $request->user();

        $rows = LearningObjective::query()
            ->orderBy('curriculum')
            ->orderBy('subject')
            ->orderBy('sort_order')
            ->get();

        $learningObjectives = $rows->map(fn ($r) => [
            'id' => $r->id,
            'curriculum' => $r->curriculum,
            'language' => $r->language,
            'subject' => $r->subject,
            'topic' => $r->topic,
            'subtopic' => $r->subtopic,
            'text' => $r->text,
            'uploadedBy' => $r->uploaded_by,
            'uploadedByName' => $r->uploaded_by_name,
            'sourceFileName' => $r->source_file_name,
            'createdAt' => optional($r->created_at)->toISOString(),
        ])->values();

        $existingSubjects = $rows->pluck('subject')->filter()->unique()->values()->all();

        return Inertia::render('admin/LearningObjectives', [
            'learningObjectives' => $learningObjectives,
            'subjectChoices' => Subjects::mergeWithExisting($existingSubjects),
            'accountSubject' => $user->subject,
            'isAdmin' => true,
            'preview' => $request->session()->get('lo_preview'),
        ]);
    }

    // ---- Curriculum writes (delegate to the shared LO controller — admin
    // bypasses authorizeCurriculum + ownership; back() lands on /admin/...).

    /** POST /admin/learning-objectives — add a single LO inline. */
    public function loStore(Request $request)
    {
        return app(TeacherLOController::class)->store($request);
    }

    /** PATCH /admin/learning-objectives/{id} — edit one LO. */
    public function loUpdate(Request $request, string $id)
    {
        return app(TeacherLOController::class)->update($request, $id);
    }

    /** DELETE /admin/learning-objectives/{id} — delete one LO. */
    public function loDestroy(Request $request, string $id)
    {
        return app(TeacherLOController::class)->destroy($request, $id);
    }

    /** POST /admin/learning-objectives/bulk-delete — delete many LOs. */
    public function loBulkDelete(Request $request)
    {
        return app(TeacherLOController::class)->bulkDelete($request);
    }

    /** POST /admin/learning-objectives/upload — two-phase Excel import. */
    public function loUpload(Request $request)
    {
        return app(TeacherLOController::class)->upload($request);
    }

    // =================================================================
    // AUTO SCORE — GET /admin/auto-score  (school-wide, scope picker)
    // =================================================================

    /** School-wide auto-graded portion tree, narrowable to one teacher. */
    public function autoScore(Request $request)
    {
        $teacherId = $this->trimOrNull($request->query('teacherId'));

        return Inertia::render('admin/AutoScore', [
            'groups' => $this->buildScoresTree($teacherId),
            'teachers' => $this->teacherChoices(),
            'teacherId' => $teacherId,
        ]);
    }

    // =================================================================
    // PENDING SCORE — GET /admin/pending-score  (school-wide, scope picker)
    // =================================================================

    /**
     * School-wide pending-essay tree + per-exam / per-submission AI export
     * bundles, narrowable to one teacher.
     */
    public function pendingScore(Request $request)
    {
        $teacherId = $this->trimOrNull($request->query('teacherId'));
        $groups = $this->buildScoresTree($teacherId);

        return Inertia::render('admin/PendingScore', [
            'groups' => $groups,
            'aiExports' => $this->buildAiExports($groups),
            'teachers' => $this->teacherChoices(),
            'teacherId' => $teacherId,
        ]);
    }

    // =================================================================
    // GRADE BULK — POST /admin/grade-bulk  (Import AI scores)
    // =================================================================

    /**
     * Apply many manual essay scores at once (the Pending Score "Import AI
     * scores" path). Delegates to the shared grader, which recomputes each
     * submission and only blocks teachers from foreign exams — an admin
     * may grade any teacher's submission. Returns the JSON
     * { applied, skipped, errors } the panel reads.
     */
    public function gradeBulk(Request $request)
    {
        return app(TeacherScoresController::class)->gradeBulk($request);
    }

    // =================================================================
    // Bank helpers (ports of Teacher\BankController, unscoped)
    // =================================================================

    /** Distinct filter option sets across the whole bank. */
    private function buildFilterOptions($base): array
    {
        $rows = (clone $base)
            ->get(['language', 'subject', 'topic', 'subtopic', 'difficulty', 'type']);

        $languages = [];
        $subjects = [];
        $topics = [];
        $subtopics = [];
        $difficulties = [];
        $types = [];
        foreach ($rows as $r) {
            if ($r->language) {
                $languages[$r->language] = true;
            }
            if ($r->subject) {
                $subjects[$r->subject] = true;
            }
            if ($r->topic) {
                $topics[$r->topic] = true;
            }
            if ($r->subtopic) {
                $subtopics[$r->subtopic] = true;
            }
            if ($r->difficulty) {
                $difficulties[$r->difficulty] = true;
            }
            $types[$r->type] = true;
        }

        $sortKeys = function (array $assoc): array {
            $keys = array_keys($assoc);
            sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

            return $keys;
        };

        return [
            'languages' => $sortKeys($languages),
            'subjects' => $sortKeys($subjects),
            'topics' => $sortKeys($topics),
            'subtopics' => $sortKeys($subtopics),
            'difficulties' => array_values(array_filter(self::DIFFICULTIES, fn ($d) => isset($difficulties[$d]))),
            'types' => array_values(array_filter(self::TYPES, fn ($t) => isset($types[$t]))),
        ];
    }

    /** Curriculum-aligned topic order from every uploaded LO (admin). */
    private function bankTopicOrder(): array
    {
        $loRows = LearningObjective::query()
            ->orderBy('subject')
            ->orderBy('sort_order')
            ->pluck('topic');

        $seen = [];
        $order = [];
        foreach ($loRows as $topic) {
            $key = mb_strtolower(trim((string) $topic));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $order[] = $topic;
        }

        return $order;
    }

    // =================================================================
    // Scores tree (port of Teacher\ScoresController::buildScoresTree, admin)
    // =================================================================

    /**
     * Build the Exam → Class → Students tree shared by Auto / Pending
     * Score. Admin scope: every exam (optionally narrowed to one teacher
     * via $teacherFilter). Same per-portion (auto vs essay) splits,
     * per-class + per-exam averages, and not-submitted roster diagnostics
     * as the teacher controller.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildScoresTree(?string $teacherFilter): array
    {
        $examQuery = Exam::query()->orderBy('name');
        if ($teacherFilter) {
            $examQuery->where('created_by', $teacherFilter);
        }
        $exams = $examQuery->get(['id', 'exam_code', 'name', 'passing_grade']);
        if ($exams->isEmpty()) {
            return [];
        }

        $examIds = $exams->pluck('id')->all();
        $submissions = ExamSubmission::whereIn('exam_id', $examIds)
            ->orderByDesc('submitted_at')->get();

        $essayQuestions = ExamQuestion::whereIn('exam_id', $examIds)
            ->where('type', 'essay')
            ->get(['id', 'exam_id', 'points']);
        $essaysByExam = [];
        foreach ($essayQuestions as $q) {
            $essaysByExam[$q->exam_id][] = ['id' => $q->id, 'points' => (float) $q->points];
        }

        $classQuery = StudentClass::query()->with('students:id,class_id,student_identifier,student_name')
            ->orderBy('name');
        if ($teacherFilter) {
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

            $byClass = [];
            $studentsByClass = [];
            foreach ($examSubs as $i => $sub) {
                $classId = $classIdByStudent[$sub->user_id] ?? null;
                $key = $classId ?? '__none__';
                $byClass[$key][] = $summaries[$i];
                $studentsByClass[$key][$sub->user_id] = true;
            }

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

    // =================================================================
    // AI exports (port of Teacher\ScoresController::buildAiExports)
    // =================================================================

    /**
     * Per-exam + per-submission AI-grading markdown bundles for the Pending
     * Score page. Keyed by exam database id and submission id.
     *
     * @param  array<int,array<string,mixed>>  $groups
     * @return array{byExam:array<string,string>,bySubmission:array<string,string>}
     */
    private function buildAiExports(array $groups): array
    {
        $byExam = [];
        $bySubmission = [];

        foreach ($groups as $group) {
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

            $byExam[$examDbId] = $this->renderAiBundle(
                $group['examId'],
                $group['examName'],
                $essayQuestions,
                $rows
            );

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

    /** Render one AI-grading markdown bundle for a set of submissions. */
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
                $lines[] = '> '.str_replace("\n", "\n> ", $q->prompt);
                $lines[] = '';
                $lines[] = '**Mark scheme**';
                $lines[] = '';
                $markScheme = $q->explanation_text ?: '_(no explicit mark scheme on this question — judge holistically)_';
                $lines[] = '> '.str_replace("\n", "\n> ", $markScheme);
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

    // =================================================================
    // Shared helpers
    // =================================================================

    /** Sorted teacher list for the auto / pending scope picker. */
    private function teacherChoices(): array
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
            ])->values()->all();
    }

    private function trimOrNull($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim((string) $v);

        return $t === '' ? null : $t;
    }

    private function oneOf($v, array $allowed): ?string
    {
        $t = $this->trimOrNull($v);

        return $t !== null && in_array($t, $allowed, true) ? $t : null;
    }
}
