<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\ExamManageController as TeacherExamManageController;
use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamAccessToken;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Models\LearningObjective;
use App\Models\User;
use App\Support\CryptoSecrets;
use App\Support\Scoring;
use App\Support\Tokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Admin exams oversight — school-wide. Faithful port of the original
 * Next.js admin exam surfaces:
 *
 *   - /admin/exams               (AdminExamsListClient → /api/admin/owned-exams)
 *   - /admin/all-exams           (AdminAllExamsClient  → /api/teacher/exams [admin])
 *   - /admin/exams/[examId]      (TeacherExamDetailClient, examsBasePath=/admin/exams)
 *   - /admin/exams/[examId]/edit (EditExamSettingsClient, examsBasePath=/admin/exams)
 *   - /admin/exams/[examId]/live (LiveMonitorClient,  basePath=/admin)
 *   - /admin/exams/[examId]/audit(AnswerAuditClient,  basePath=/admin)
 *
 * Admins are NEVER scoped by created_by — they see every teacher's exams,
 * questions, tokens, sessions and submissions. The detail + edit pages
 * REUSE the teacher Inertia pages (teacher/ExamDetail, teacher/ExamEdit):
 * the admin controller produces the same initial props the teacher
 * controllers do, so the rich authoring surface renders identically under
 * the /admin routes.
 */
class ExamController extends Controller
{
    private const DEFAULT_TYPE_DISTRIBUTION = [
        'single_choice' => 0, 'multi_select' => 0, 'short_text' => 0, 'numeric' => 0, 'essay' => 0,
    ];

    private const DEFAULT_DIFFICULTY_DISTRIBUTION = [
        'remember' => 15, 'understand' => 25, 'apply' => 25, 'analyze' => 15,
        'evaluate' => 10, 'create' => 7, 'olympiad' => 3,
    ];

    private const DEFAULT_MEDIA_TARGETS = ['images' => 0, 'tables' => 0];

    // =================================================================
    // LIST — GET /admin/exams  (school-wide, with owner + tokens column)
    // =================================================================

    /**
     * Every exam in the school. The original AdminExamsListClient is a
     * thin owner-only list, but the new admin console renders the SAME
     * rich list the teacher dashboard uses (owner column, decrypted token
     * pills, avg %, passed, submissions, delete + create/import) — admin
     * just isn't scoped to created_by.
     */
    public function index(Request $request)
    {
        return Inertia::render('admin/Exams', [
            'exams' => $this->examSummaries(null),
        ]);
    }

    // =================================================================
    // ALL-EXAMS — GET /admin/all-exams (live-monitor launchpad)
    // =================================================================

    /**
     * School-wide oversight launchpad: every teacher's exams with a
     * per-teacher scope picker and Live / Audit / Manage actions for each.
     * Mirrors AdminAllExamsClient (which read the admin-wide teacher-exams
     * list) plus the metric cards (exams / active / submissions).
     */
    public function allExams(Request $request)
    {
        $teacherId = $this->trimOrNull($request->query('teacherId'));
        $summaries = $this->examSummaries($teacherId);

        $activeCount = count(array_filter($summaries, fn ($e) => $e['active']));
        $totalSubmissions = array_sum(array_column($summaries, 'totalSubmissions'));

        return Inertia::render('admin/AllExams', [
            'exams' => $summaries,
            'teachers' => $this->teacherChoices(),
            'teacherId' => $teacherId,
            'metrics' => [
                'exams' => count($summaries),
                'active' => $activeCount,
                'submissions' => $totalSubmissions,
            ],
        ]);
    }

    /**
     * Build the per-exam summary rows shared by the list + all-exams pages.
     * No created_by scoping (admin sees all); $teacherId optionally narrows
     * to one teacher's exams (the all-exams scope picker).
     *
     * @return array<int,array<string,mixed>>
     */
    private function examSummaries(?string $teacherId): array
    {
        $query = Exam::query()->orderByDesc('created_at');
        if ($teacherId !== null) {
            $query->where('created_by', $teacherId);
        }
        $exams = $query->get([
            'id', 'exam_code', 'name', 'duration_minutes', 'passing_grade', 'active', 'created_by', 'created_by_name',
        ]);

        $examIds = $exams->pluck('id');
        if ($examIds->isEmpty()) {
            return [];
        }

        $submissionAgg = ExamSubmission::whereIn('exam_id', $examIds)
            ->selectRaw('exam_id, COUNT(*) as total, AVG(percent_score) as avg_pct, SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed')
            ->groupBy('exam_id')
            ->get()
            ->keyBy('exam_id');

        $tokens = ExamAccessToken::whereIn('exam_id', $examIds)
            ->where('active', true)
            ->orderByDesc('created_at')
            ->get(['id', 'exam_id', 'token_preview', 'used_count', 'max_uses', 'expires_at']);

        $tokensByExam = [];
        foreach ($tokens as $t) {
            $tokensByExam[$t->exam_id][] = [
                'id' => $t->id,
                'tokenPreview' => CryptoSecrets::decryptTokenPreview($t->token_preview) ?? $t->token_preview,
                'usedCount' => (int) $t->used_count,
                'maxUses' => (int) $t->max_uses,
                'expiresAt' => $t->expires_at?->toIso8601String(),
            ];
        }

        $summaries = [];
        foreach ($exams as $e) {
            $agg = $submissionAgg->get($e->id);
            $avg = $agg && $agg->avg_pct !== null ? round((float) $agg->avg_pct, 2) : null;
            $summaries[] = [
                'examDatabaseId' => $e->id,
                'examId' => $e->exam_code,
                'name' => $e->name,
                'durationMinutes' => (int) $e->duration_minutes,
                'passingGrade' => (int) $e->passing_grade,
                'active' => (bool) $e->active,
                'ownerTeacherName' => $e->created_by_name,
                'activeTokens' => $tokensByExam[$e->id] ?? [],
                'activeTokenCount' => count($tokensByExam[$e->id] ?? []),
                'totalSubmissions' => $agg ? (int) $agg->total : 0,
                'averagePercent' => $avg,
                'passedCount' => $agg ? (int) $agg->passed : 0,
            ];
        }

        return $summaries;
    }

    // =================================================================
    // CREATE — GET /admin/exams/new + POST /admin/exams
    // =================================================================

    /**
     * Renders the create form (reuses teacher/ExamCreate). Admins are never
     * capability-gated, so every field shows. NOTE: the shared create page
     * posts to /teacher/exams (hardcoded); see store() + the route notes.
     */
    public function create(Request $request)
    {
        return Inertia::render('teacher/ExamCreate', [
            'gates' => $this->adminFormGates(),
            'subjectChoices' => $this->subjectChoices(),
            'defaults' => [
                'durationMinutes' => 30,
                'passingGrade' => 70,
                'generalInstructions' => 'Answer every question. Your answers are saved automatically while the timer is running.',
                'examMode' => 'strict',
                'shuffleQuestions' => false,
                'shuffleOptions' => false,
                'language' => 'English',
                'subject' => '',
                'mediaBaseUrl' => '',
                'startTime' => null,
                'endTime' => null,
                'sebRequired' => false,
                'typeDistribution' => self::DEFAULT_TYPE_DISTRIBUTION,
                'difficultyDistribution' => self::DEFAULT_DIFFICULTY_DISTRIBUTION,
                'mediaTargets' => self::DEFAULT_MEDIA_TARGETS,
            ],
        ]);
    }

    /**
     * Creates a new (empty) exam owned by the admin. Delegates to the shared
     * create logic and rewrites the success redirect onto /admin/exams.
     */
    public function store(Request $request)
    {
        $response = app(TeacherExamManageController::class)->store($request);

        return $this->rewriteTeacherRedirect($response);
    }

    // =================================================================
    // IMPORT — POST /admin/exams/import  (school-wide)
    // =================================================================

    /**
     * Import an exam package (zip/json) for the admin. The parsing +
     * creation logic is identical to the teacher flow, so we delegate to
     * Teacher\ExamManageController::importPackage (admin bypasses ownership
     * there) and rewrite its success redirect from /teacher/exams/{code} to
     * the admin detail route so the admin stays in the /admin console.
     */
    public function importPackage(Request $request)
    {
        $response = app(TeacherExamManageController::class)->importPackage($request);

        return $this->rewriteTeacherRedirect($response);
    }

    // =================================================================
    // DELETE EXAM — DELETE /admin/exams/{examId}  (school-wide)
    // =================================================================

    /** Removes any exam (FK cascades take care of children). Admin sees all. */
    public function destroy(Request $request, string $examId)
    {
        $exam = $this->loadExam($examId);
        if (! $exam) {
            return back()->with('error', 'Exam not found.');
        }
        $name = $exam->name;
        $exam->delete();

        return redirect('/admin/exams')->with('success', "Deleted exam {$name}.");
    }

    // =================================================================
    // TOKEN actions (list pills) — school-wide
    // =================================================================

    /**
     * POST /admin/exams/tokens/{tokenId}/regenerate — deactivate the old
     * token + mint a fresh code carrying the REMAINING uses, same scope /
     * class / expiry. Admin may rotate any teacher's token.
     */
    public function regenerateToken(Request $request, string $tokenId)
    {
        $user = $request->user();
        $existing = ExamAccessToken::with('exam:id,exam_code,name,created_by')->find($tokenId);
        if (! $existing || ! $existing->exam) {
            abort(404, 'Token not found.');
        }

        $remainingUses = (int) $existing->max_uses - (int) $existing->used_count;
        if ($remainingUses <= 0) {
            return back()->with('error', 'This token has been fully used. Issue a new token instead of regenerating.');
        }

        [$newCode, $newDigest] = $this->mintUniqueToken();
        if ($newCode === null) {
            return back()->with('error', 'Unable to generate a unique token. Please try again.');
        }

        DB::transaction(function () use ($existing, $newCode, $newDigest, $remainingUses, $user) {
            ExamAccessToken::where('id', $existing->id)->update(['active' => false]);
            ExamAccessToken::create([
                'exam_id' => $existing->exam_id,
                'class_id' => $existing->class_id,
                'token_digest' => $newDigest,
                'token_preview' => CryptoSecrets::encryptTokenPreview($newCode),
                'max_uses' => $remainingUses,
                'used_count' => 0,
                'expires_at' => $existing->expires_at,
                'active' => true,
                'created_by' => $user->id,
                'created_by_name' => $user->full_name,
            ]);
        });

        return back()->with(
            'success',
            "Replaced token → {$newCode} ({$remainingUses} use".($remainingUses === 1 ? '' : 's').' left).'
        );
    }

    /** DELETE /admin/exams/tokens/{tokenId} — hard-delete any token (the X pill). */
    public function deleteToken(Request $request, string $tokenId)
    {
        $token = ExamAccessToken::find($tokenId);
        if (! $token) {
            abort(404, 'Token not found.');
        }
        $preview = CryptoSecrets::decryptTokenPreview($token->token_preview) ?? $token->token_preview;
        $token->delete();

        return back()->with('success', "Deleted token {$preview}.");
    }

    // =================================================================
    // DETAIL — GET /admin/exams/{examId}  (reuse teacher/ExamDetail)
    // =================================================================

    /**
     * Renders the full teacher authoring surface for any exam (admin sees
     * all). Produces the exact initial props teacher/ExamDetail expects so
     * the page renders identically under /admin/exams/{id}.
     */
    public function examDetail(Request $request, string $examId)
    {
        $exam = $this->loadExam($examId);
        if (! $exam) {
            return Inertia::render('teacher/ExamDetail', ['notFound' => true]);
        }

        $questionsPayload = $this->questionsPayload($exam);

        return Inertia::render('teacher/ExamDetail', [
            'detail' => $this->buildDetail($exam),
            'questions' => $questionsPayload['questions'],
            'topicOrder' => $questionsPayload['topicOrder'],
            'tokens' => $this->tokensPayload($exam),
        ]);
    }

    // =================================================================
    // EDIT — GET /admin/exams/{examId}/edit  (reuse teacher/ExamEdit)
    // =================================================================

    /** Renders the edit-settings form (teacher/ExamEdit) seeded from the exam. */
    public function editSettings(Request $request, string $examId)
    {
        $exam = $this->loadExam($examId);
        if (! $exam) {
            abort(404, 'Exam not found.');
        }

        return Inertia::render('teacher/ExamEdit', [
            'gates' => $this->adminFormGates(),
            'subjectChoices' => $this->subjectChoices(),
            'exam' => [
                'examDatabaseId' => $exam->id,
                'examId' => $exam->exam_code,
                'name' => $exam->name,
                'durationMinutes' => (int) $exam->duration_minutes,
                'passingGrade' => (int) $exam->passing_grade,
                'generalInstructions' => $exam->general_instructions ?? '',
                'startTime' => $exam->start_time?->toIso8601String(),
                'endTime' => $exam->end_time?->toIso8601String(),
                'active' => (bool) $exam->active,
                'examMode' => $exam->exam_mode,
                'shuffleQuestions' => (bool) $exam->shuffle_questions,
                'shuffleOptions' => (bool) $exam->shuffle_options,
                'language' => $exam->language,
                'subject' => $exam->subject ?? '',
                'mediaBaseUrl' => $exam->media_base_url ?? '',
                'sebRequired' => (bool) $exam->seb_required,
                'typeDistribution' => array_merge(self::DEFAULT_TYPE_DISTRIBUTION, is_array($exam->type_distribution) ? $exam->type_distribution : []),
                'difficultyDistribution' => array_merge(self::DEFAULT_DIFFICULTY_DISTRIBUTION, is_array($exam->difficulty_distribution) ? $exam->difficulty_distribution : []),
                'mediaTargets' => array_merge(self::DEFAULT_MEDIA_TARGETS, is_array($exam->media_targets) ? $exam->media_targets : []),
            ],
        ]);
    }

    // =================================================================
    // LIVE MONITOR — GET /admin/exams/{examId}/live  (page + JSON)
    // =================================================================

    /** Renders the live-monitor page (admin/ExamLive); data via liveScores(). */
    public function live(Request $request, string $examId)
    {
        $exam = $this->loadExam($examId);

        return Inertia::render('admin/ExamLive', [
            'examId' => $examId,
            'examName' => $exam?->name ?? $examId,
            'notFound' => ! $exam,
        ]);
    }

    /**
     * GET /admin/exams/{examId}/live-scores — live class monitor JSON.
     * Scores every student's CURRENT answers on the fly (drafts for
     * in-progress, snapshot for submitted). Nothing is written. Faithful
     * port of /api/teacher/exams/[examId]/live-scores (admin sees all).
     */
    public function liveScores(Request $request, string $examId)
    {
        $exam = $this->loadExam($examId);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $questions = ExamQuestion::where('exam_id', $exam->id)
            ->orderBy('position')
            ->get(['id', 'type', 'points', 'topic', 'correct_answer']);
        $totalQuestions = $questions->count();

        $scoreInput = $questions->map(fn ($q) => [
            'id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type,
        ])->all();
        $keys = $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all();
        $typeById = $questions->mapWithKeys(fn ($q) => [$q->id => $q->type])->all();

        $sessions = ExamSession::where('exam_id', $exam->id)
            ->with([
                'user:id,username,full_name',
                'drafts:id,session_id,question_id,value',
                'submission:id,session_id,answers_snapshot,manual_scores',
            ])
            ->orderBy('user_id')
            ->orderByDesc('created_at')
            ->get();

        $seen = [];
        $now = now()->getTimestamp();
        $rows = [];
        foreach ($sessions as $s) {
            if (isset($seen[$s->user_id])) {
                continue;
            }
            $seen[$s->user_id] = true;

            $submitted = $s->status === 'submitted';
            $answers = [];
            $manualScores = [];
            if ($submitted && $s->submission && is_array($s->submission->answers_snapshot)) {
                $answers = $s->submission->answers_snapshot;
                $manualScores = is_array($s->submission->manual_scores) ? $s->submission->manual_scores : [];
            } else {
                foreach ($s->drafts as $d) {
                    $answers[$d->question_id] = $d->value;
                }
            }

            $scoring = Scoring::scoreExam($scoreInput, $keys, $answers, $manualScores);
            $autoEarned = 0.0;
            $autoPossible = 0.0;
            $essayPossible = 0.0;
            $essayPending = 0;
            foreach ($scoring['itemResults'] as $item) {
                if (($typeById[$item['questionId']] ?? null) === 'essay') {
                    $essayPossible += $item['possible'];
                    if ($item['requiresGrading']) {
                        $essayPending++;
                    }
                } else {
                    $autoEarned += $item['awarded'];
                    $autoPossible += $item['possible'];
                }
            }
            $autoPct = $autoPossible > 0 ? round(($autoEarned / $autoPossible) * 100, 1) : 0;
            $answeredCount = $questions->filter(fn ($q) => $this->isAnswered($answers[$q->id] ?? null))->count();
            $elapsedSeconds = $s->started_at ? $now - $s->started_at->getTimestamp() : 0;
            $timeRemainingSeconds = $submitted ? 0 : max(0, (int) $exam->duration_minutes * 60 - $elapsedSeconds);
            $acEvents = is_array($s->anti_cheat_events) ? count($s->anti_cheat_events) : 0;

            $rows[] = [
                'userId' => $s->user_id,
                'username' => $s->user?->username ?? '?',
                'fullName' => $s->user?->full_name ?? '?',
                'status' => $submitted ? 'submitted' : $s->status,
                'answeredCount' => $answeredCount,
                'totalQuestions' => $totalQuestions,
                'autoEarned' => round($autoEarned, 2),
                'autoPossible' => round($autoPossible, 2),
                'autoPct' => $autoPct,
                'essayPossible' => round($essayPossible, 2),
                'essayPending' => $essayPending,
                'timeRemainingSeconds' => $timeRemainingSeconds,
                'lastSavedAt' => $s->last_saved_at?->toIso8601String(),
                'antiCheatEventCount' => $acEvents,
            ];
        }

        // In-progress first, then by descending auto %.
        $rank = fn (string $st) => $st === 'draft' ? 0 : ($st === 'submitted' ? 1 : 2);
        usort($rows, function ($a, $b) use ($rank) {
            if ($rank($a['status']) !== $rank($b['status'])) {
                return $rank($a['status']) <=> $rank($b['status']);
            }
            return $b['autoPct'] <=> $a['autoPct'];
        });

        return response()->json([
            'exam' => [
                'id' => $exam->id,
                'examCode' => $exam->exam_code,
                'name' => $exam->name,
                'passingGrade' => (int) $exam->passing_grade,
                'totalQuestions' => $totalQuestions,
            ],
            'students' => $rows,
            'totals' => [
                'students' => count($rows),
                'inProgress' => count(array_filter($rows, fn ($r) => $r['status'] === 'draft')),
                'submitted' => count(array_filter($rows, fn ($r) => $r['status'] === 'submitted')),
                'avgAutoPct' => count($rows) > 0
                    ? round(array_sum(array_column($rows, 'autoPct')) / count($rows), 1)
                    : 0,
            ],
        ]);
    }

    // =================================================================
    // ANSWER AUDIT — GET /admin/exams/{examId}/audit  (page + JSON)
    // =================================================================

    /** Renders the answer-audit page (admin/ExamAudit); data via answerAudit(). */
    public function audit(Request $request, string $examId)
    {
        $exam = $this->loadExam($examId);

        return Inertia::render('admin/ExamAudit', [
            'examId' => $examId,
            'examName' => $exam?->name ?? $examId,
            'notFound' => ! $exam,
        ]);
    }

    /**
     * GET /admin/exams/{examId}/answer-audit — integrity JSON. Lines up the
     * raw answer_drafts against the submission answersSnapshot per question
     * and flags any mismatch (a lost / phantom answer). Faithful port of
     * /api/teacher/exams/[examId]/answer-audit (admin sees all).
     */
    public function answerAudit(Request $request, string $examId)
    {
        $exam = $this->loadExam($examId);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $questions = ExamQuestion::where('exam_id', $exam->id)
            ->orderBy('position')
            ->get(['id', 'position', 'type', 'points', 'topic']);

        $sessions = ExamSession::where('exam_id', $exam->id)
            ->with([
                'user:id,username,full_name',
                'drafts:id,session_id,question_id,value,updated_at',
                'submission:id,session_id,final_score,possible_score,percent_score,pending_essay_count,answers_snapshot,submitted_at',
            ])
            ->orderBy('user_id')
            ->orderBy('attempt')
            ->get();

        $questionsOut = $questions->map(fn ($q) => [
            'id' => $q->id,
            'position' => (int) $q->position,
            'type' => $q->type,
            'points' => (float) $q->points,
            'topic' => $q->topic,
        ])->values();

        $studentRows = $sessions->map(function ($s) use ($questions) {
            $draftMap = [];
            foreach ($s->drafts as $d) {
                $draftMap[$d->question_id] = $d->value;
            }
            $snap = $s->submission && is_array($s->submission->answers_snapshot)
                ? $s->submission->answers_snapshot
                : [];

            $perQuestion = $questions->map(function ($q) use ($draftMap, $snap) {
                $hasDraft = array_key_exists($q->id, $draftMap);
                $hasSnap = array_key_exists($q->id, $snap);
                $draftVal = $hasDraft ? $draftMap[$q->id] : null;
                $snapVal = $hasSnap ? $snap[$q->id] : null;
                if (! $hasDraft && ! $hasSnap) {
                    $match = true;
                } elseif ($hasDraft !== $hasSnap) {
                    $match = false;
                } else {
                    $match = $this->sortedJson($draftVal) === $this->sortedJson($snapVal);
                }

                return [
                    'questionId' => $q->id,
                    'position' => (int) $q->position,
                    'type' => $q->type,
                    'points' => (float) $q->points,
                    'topic' => $q->topic,
                    'hasDraft' => $hasDraft,
                    'hasSnap' => $hasSnap,
                    'draftValue' => $draftVal,
                    'snapshotValue' => $snapVal,
                    'match' => $match,
                ];
            })->values();

            $mismatchCount = $perQuestion->filter(fn ($q) => ! $q['match'])->count();

            return [
                'sessionId' => $s->id,
                'attempt' => (int) $s->attempt,
                'status' => $s->status,
                'startedAt' => $s->started_at?->toIso8601String(),
                'lastSavedAt' => $s->last_saved_at?->toIso8601String(),
                'submittedAt' => $s->submitted_at?->toIso8601String(),
                'username' => $s->user?->username ?? '?',
                'fullName' => $s->user?->full_name ?? '?',
                'submissionId' => $s->submission?->id,
                'finalScore' => $s->submission?->final_score,
                'possibleScore' => $s->submission?->possible_score,
                'percentScore' => $s->submission?->percent_score,
                'pendingEssayCount' => $s->submission?->pending_essay_count,
                'draftCount' => $s->drafts->count(),
                'snapCount' => count($snap),
                'mismatchCount' => $mismatchCount,
                'perQuestion' => $perQuestion,
            ];
        })->values()->all();

        // Mismatched sessions first, then alphabetically by student.
        usort($studentRows, function ($a, $b) {
            $am = $a['mismatchCount'] > 0 ? 1 : 0;
            $bm = $b['mismatchCount'] > 0 ? 1 : 0;
            if ($am !== $bm) {
                return $bm <=> $am;
            }
            return strcmp($a['fullName'] ?? '', $b['fullName'] ?? '');
        });

        $totalMismatch = array_sum(array_column($studentRows, 'mismatchCount'));

        return response()->json([
            'exam' => [
                'id' => $exam->id,
                'examCode' => $exam->exam_code,
                'name' => $exam->name,
                'shuffleQuestions' => (bool) $exam->shuffle_questions,
                'shuffleOptions' => (bool) $exam->shuffle_options,
            ],
            'questions' => $questionsOut,
            'students' => $studentRows,
            'totals' => [
                'sessions' => count($studentRows),
                'students' => count(array_unique(array_column($studentRows, 'username'))),
                'totalDrafts' => array_sum(array_column($studentRows, 'draftCount')),
                'totalSnap' => array_sum(array_column($studentRows, 'snapCount')),
                'totalMismatch' => $totalMismatch,
            ],
        ]);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** Load an exam by uuid or exam_code (no ownership scope — admin sees all). */
    private function loadExam(string $identifier): ?Exam
    {
        return Exam::where('id', $identifier)->orWhere('exam_code', $identifier)->first();
    }

    /**
     * Rewrite a teacher-scoped redirect target (/teacher/exams…) onto the
     * /admin equivalent so a delegated teacher action lands the admin back
     * inside the admin console. `back()` redirects (errors) pass through.
     */
    private function rewriteTeacherRedirect($response)
    {
        if ($response instanceof RedirectResponse) {
            $target = $response->getTargetUrl();
            if (str_contains($target, '/teacher/exams')) {
                $response->setTargetUrl(str_replace('/teacher/exams', '/admin/exams', $target));
            }
        }

        return $response;
    }

    /** @return array{0:?string,1:?string} [plainCode, digest] or [null,null] on collision exhaustion */
    private function mintUniqueToken(): array
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = Tokens::generatePlain();
            $digest = Tokens::digest($candidate);
            if (! ExamAccessToken::where('token_digest', $digest)->exists()) {
                return [$candidate, $digest];
            }
        }

        return [null, null];
    }

    private function isAnswered(mixed $v): bool
    {
        if ($v === null) {
            return false;
        }
        if (is_array($v)) {
            return count($v) > 0;
        }
        if (is_string($v)) {
            return trim($v) !== '';
        }

        return true;
    }

    /**
     * Stable JSON for deep-equality of answer values. Arrays are sorted as
     * strings (answer values are at most arrays of strings), mirroring the
     * original sortedJson() so order differences never read as a mismatch.
     */
    private function sortedJson(mixed $v): string
    {
        if (is_array($v)) {
            $strs = array_map(fn ($x) => (string) $x, $v);
            sort($strs, SORT_STRING);

            return json_encode($strs);
        }

        return json_encode($v);
    }

    private function buildDetail(Exam $exam): array
    {
        $questionCount = ExamQuestion::where('exam_id', $exam->id)->count();
        $totalSubmissions = ExamSubmission::where('exam_id', $exam->id)->count();
        $avgRaw = ExamSubmission::where('exam_id', $exam->id)->avg('percent_score');
        $passedCount = ExamSubmission::where('exam_id', $exam->id)->where('passed', true)->count();
        $activeTokenCount = ExamAccessToken::where('exam_id', $exam->id)->where('active', true)->count();

        return [
            'examDatabaseId' => $exam->id,
            'examId' => $exam->exam_code,
            'name' => $exam->name,
            'durationMinutes' => (int) $exam->duration_minutes,
            'passingGrade' => (int) $exam->passing_grade,
            'generalInstructions' => $exam->general_instructions ?? '',
            'startTime' => $exam->start_time?->toIso8601String(),
            'endTime' => $exam->end_time?->toIso8601String(),
            'active' => (bool) $exam->active,
            'examMode' => $exam->exam_mode,
            'shuffleQuestions' => (bool) $exam->shuffle_questions,
            'shuffleOptions' => (bool) $exam->shuffle_options,
            'language' => $exam->language,
            'subject' => $exam->subject ?? '',
            'typeDistribution' => array_merge(self::DEFAULT_TYPE_DISTRIBUTION, is_array($exam->type_distribution) ? $exam->type_distribution : []),
            'difficultyDistribution' => array_merge(self::DEFAULT_DIFFICULTY_DISTRIBUTION, is_array($exam->difficulty_distribution) ? $exam->difficulty_distribution : []),
            'mediaTargets' => array_merge(self::DEFAULT_MEDIA_TARGETS, is_array($exam->media_targets) ? $exam->media_targets : []),
            'sebRequired' => (bool) $exam->seb_required,
            'sebSecret' => $exam->seb_secret,
            'questionCount' => $questionCount,
            'totalSubmissions' => $totalSubmissions,
            'averagePercent' => $avgRaw !== null ? round((float) $avgRaw, 2) : null,
            'passedCount' => $passedCount,
            'activeTokenCount' => $activeTokenCount,
        ];
    }

    /**
     * Questions list + curriculum topicOrder for the detail page. Subtopic
     * + difficulty fall back to the source bank row when not set locally.
     *
     * @return array{questions: array<int,array<string,mixed>>, topicOrder: array<int,string>}
     */
    private function questionsPayload(Exam $exam): array
    {
        $rows = ExamQuestion::with(['media' => fn ($q) => $q->orderBy('sort_order')])
            ->where('exam_id', $exam->id)
            ->orderBy('position')
            ->get();

        $bankIds = $rows->pluck('source_bank_question_id')->filter()->unique()->values();
        $bankMap = [];
        if ($bankIds->isNotEmpty()) {
            foreach (BankQuestion::whereIn('id', $bankIds->all())->get(['id', 'subtopic', 'difficulty']) as $b) {
                $bankMap[$b->id] = ['subtopic' => $b->subtopic, 'difficulty' => $b->difficulty];
            }
        }

        $questions = $rows->map(function ($q) use ($bankMap) {
            $src = $q->source_bank_question_id ? ($bankMap[$q->source_bank_question_id] ?? null) : null;

            return [
                'id' => $q->id,
                'position' => (int) $q->position,
                'type' => $q->type,
                'topic' => $q->topic,
                'subtopic' => $src['subtopic'] ?? null,
                'difficulty' => $q->difficulty ?? ($src['difficulty'] ?? null),
                'prompt' => $q->prompt,
                'points' => $q->points,
                'options' => is_array($q->options) ? $q->options : null,
                'correctAnswer' => $q->correct_answer,
                'explanationText' => $q->explanation_text ?? '',
                'sourceBankQuestionId' => $q->source_bank_question_id,
                'media' => $q->media->map(fn ($m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'url' => $m->url,
                    'altText' => $m->alt_text,
                    'caption' => $m->caption,
                ])->values(),
            ];
        })->values();

        return [
            'questions' => $questions,
            'topicOrder' => $this->curriculumTopicOrder(),
        ];
    }

    /** Token list payload with decrypted previews, newest first. */
    private function tokensPayload(Exam $exam): array
    {
        return ExamAccessToken::where('exam_id', $exam->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'code' => CryptoSecrets::decryptTokenPreview($t->token_preview) ?? $t->token_preview,
                'examDatabaseId' => $exam->id,
                'examId' => $exam->exam_code,
                'examName' => $exam->name,
                'createdByName' => $t->created_by_name ?? '(unknown)',
                'createdAt' => $t->created_at->toIso8601String(),
                'expiresAt' => $t->expires_at?->toIso8601String(),
                'maxUses' => (int) $t->max_uses,
                'usedCount' => (int) $t->used_count,
                'active' => (bool) $t->active,
            ])->values()->all();
    }

    /** Curriculum topic order (admin: every uploaded LO). */
    private function curriculumTopicOrder(): array
    {
        $loRows = LearningObjective::query()
            ->orderBy('subject')
            ->orderBy('sort_order')
            ->get(['topic']);

        $seen = [];
        $order = [];
        foreach ($loRows as $lo) {
            $key = mb_strtolower(trim($lo->topic));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $order[] = $lo->topic;
        }

        return $order;
    }

    /** Curated bilingual subjects merged with subjects already in use (exams + bank). */
    private function subjectChoices(): array
    {
        $existing = Exam::query()->whereNotNull('subject')->distinct()->pluck('subject')->all();
        $bank = BankQuestion::query()->whereNotNull('subject')->distinct()->pluck('subject')->all();

        return \App\Support\Subjects::mergeWithExisting([...$existing, ...$bank]);
    }

    /** Sorted teacher list for the all-exams scope picker. */
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

    /**
     * Admin form gates — admins are never capability-gated, so every exam
     * config field is shown. Mirrors the gate map shape the ExamForm reads.
     */
    private function adminFormGates(): array
    {
        return [
            'showDuration' => true,
            'showPassing' => true,
            'showMode' => true,
            'showShuffleQuestions' => true,
            'showShuffleOptions' => true,
            'showLanguage' => true,
            'showSeb' => true,
            'showTypeSingle' => true,
            'showTypeMulti' => true,
            'showTypeShortText' => true,
            'showTypeNumeric' => true,
            'showTypeEssay' => true,
            'showDifficultyEasy' => true,
            'showDifficultyMedium' => true,
            'showDifficultyHard' => true,
            'showDifficultyHots' => true,
            'showDifficultyOlympiad' => true,
            'showMediaImage' => true,
            'showMediaTable' => true,
            'showSchedulingRow' => true,
            'showShuffleGroup' => true,
            'showModeRow' => true,
            'showTypeRow' => true,
            'showDifficultyRow' => true,
            'showMediaRow' => true,
            'showCompositionFieldset' => true,
        ];
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim($value);

        return $t === '' ? null : $t;
    }
}
