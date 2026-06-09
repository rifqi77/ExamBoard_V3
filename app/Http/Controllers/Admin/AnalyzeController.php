<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Admin Analyze — faithful port of /api/admin/analyze + AdminAnalyzeClient.
 *
 * The original shipped a single system dashboard with 9 sections:
 *   1. counts (teachers/active/disabled/students/exams/submissions/bank)
 *   2. 30-day submissions-per-day histogram (per-bar tooltips)
 *   3. pass-rate-per-exam table with inline progress bars + averages
 *   4. top-10 scorers (per-student graded-only average)
 *   5. bottom-10 scorers
 *   6. 10-bucket score-distribution histogram
 *   7. strongest / weakest topics (aggregated across every graded
 *      submission's topicBreakdown JSON)
 *   8. bank composition by subject / type / difficulty (proportional bars)
 *      + most-used questions + unused count
 *   9. pending-essays-per-teacher table + oldest-10-pending submissions
 *      (age in days)
 *
 * This port renders both the system dashboard ("Dashboard" tab) and a new
 * per-exam Item analysis tab. Item analysis recomputes, per question of
 * every exam that has submissions, the difficulty index (mean awarded /
 * possible) and the answer/option distribution — the school-wide companion
 * to the score breakdown. Everything is computed server-side and handed to
 * the page; the page is a pure renderer (matching the teacher console).
 *
 * Admin sees ALL data. A ?teacherId= query narrows every section to one
 * teacher's exams (Exam.created_by = teacherId) — the same scoping the
 * Scores / Reports pages use via the shared teacher picker.
 */
class AnalyzeController extends Controller
{
    private const TYPE_LABELS = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

    // Bloom's revised taxonomy + olympiad. Used to group bank composition stats.
    private const DIFFICULTY_LABELS = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'];

    private const DAILY_WINDOW_DAYS = 30;

    private const SCORE_BUCKETS = [
        '0-10', '10-20', '20-30', '30-40', '40-50',
        '50-60', '60-70', '70-80', '80-90', '90-100',
    ];

    public function index(Request $request)
    {
        // Optional per-teacher scope. Admin only — there is no teacher path
        // to this controller, so any teacherId is honoured when present.
        $teacherFilter = $request->query('teacherId');
        $teacherFilter = is_string($teacherFilter) && $teacherFilter !== '' ? $teacherFilter : null;

        return Inertia::render('admin/Analyze', [
            'analyze' => $this->buildAnalyze($teacherFilter),
            'itemAnalysis' => $this->buildItemAnalysis($teacherFilter),
            'teachers' => $this->teacherOptions(),
            'teacherId' => $teacherFilter,
        ]);
    }

    // ===============================================================
    // Section 1-9: the system dashboard
    // ===============================================================

    /**
     * @return array<string,mixed>
     */
    private function buildAnalyze(?string $teacherFilter): array
    {
        // Daily-chart window starts 30 days ago at local midnight.
        $today = Carbon::now()->startOfDay();
        $windowStart = $today->copy()->subDays(self::DAILY_WINDOW_DAYS - 1);

        // --- Exam scope (optionally one teacher) ---
        $exams = Exam::query()
            ->when($teacherFilter, fn ($q) => $q->where('created_by', $teacherFilter))
            ->get(['id', 'exam_code', 'name', 'created_by']);
        $examIds = $exams->pluck('id')->all();
        $examById = $exams->keyBy('id');
        $hasScope = $teacherFilter !== null;

        // --- Users ---
        $users = User::query()->get(['id', 'username', 'full_name', 'role', 'active']);
        $teachers = $users->where('role', 'teacher');
        // When scoped to one teacher, the workload table is just that teacher.
        if ($hasScope) {
            $teachers = $teachers->where('id', $teacherFilter)->values();
        }
        $activeTeachers = $teachers->where('active', true);
        $students = $users->where('role', 'student')->values();

        // --- Submissions in scope ---
        // Unscoped: every submission (admin sees all). Scoped: only the
        // selected teacher's exams.
        $subQuery = ExamSubmission::query();
        if ($hasScope) {
            $subQuery->whereIn('exam_id', $examIds);
        }
        // Pull just the columns each aggregate needs; topicBreakdown is a JSON
        // column so it must be aggregated in PHP (can't groupBy through it).
        $submissions = (count($examIds) === 0 && $hasScope)
            ? collect()
            : $subQuery->get([
                'id', 'exam_id', 'user_id', 'full_name',
                'final_score', 'possible_score', 'percent_score',
                'passed', 'pending_essay_count', 'topic_breakdown',
                'submitted_at',
            ]);

        $examCount = $hasScope ? count($examIds) : Exam::count();
        $submissionCount = $hasScope ? $submissions->count() : ExamSubmission::count();
        $bankQuestionCount = BankQuestion::query()
            ->when($teacherFilter, fn ($q) => $q->where('created_by', $teacherFilter))
            ->count();

        // --- Section 2: submissions per day (last 30) ---
        $submissionsByDay = [];
        for ($i = self::DAILY_WINDOW_DAYS - 1; $i >= 0; $i--) {
            $submissionsByDay[$today->copy()->subDays($i)->toDateString()] = 0;
        }
        foreach ($submissions as $s) {
            if (! $s->submitted_at) {
                continue;
            }
            $key = $s->submitted_at->copy()->startOfDay()->toDateString();
            if (array_key_exists($key, $submissionsByDay)) {
                $submissionsByDay[$key]++;
            }
        }
        $submissionsByDayOut = [];
        foreach ($submissionsByDay as $date => $count) {
            $submissionsByDayOut[] = ['date' => $date, 'count' => $count];
        }

        // --- Section 6: score distribution (10 buckets, graded only) ---
        // Mirrors the original: derived from the same 30-day recent window so
        // the histogram lines up with the daily chart above.
        $distribution = [];
        foreach (self::SCORE_BUCKETS as $bucket) {
            $distribution[$bucket] = 0;
        }
        foreach ($submissions as $s) {
            if (! $s->submitted_at || $s->submitted_at->lt($windowStart)) {
                continue;
            }
            if ((int) $s->pending_essay_count > 0) {
                continue;
            }
            $idx = (int) min(9, floor(((float) $s->percent_score) / 10));
            $bucket = self::SCORE_BUCKETS[$idx];
            $distribution[$bucket]++;
        }
        $scoreDistribution = [];
        foreach ($distribution as $bucket => $count) {
            $scoreDistribution[] = ['bucket' => $bucket, 'count' => $count];
        }

        // --- Section 3: per-exam pass rate + averages ---
        $perExamAcc = [];
        foreach ($submissions as $s) {
            $eid = $s->exam_id;
            if (! isset($perExamAcc[$eid])) {
                $perExamAcc[$eid] = ['total' => 0, 'graded' => 0, 'sumPercent' => 0.0, 'passed' => 0];
            }
            $perExamAcc[$eid]['total']++;
            if ((int) $s->pending_essay_count === 0) {
                $perExamAcc[$eid]['graded']++;
                $perExamAcc[$eid]['sumPercent'] += (float) $s->percent_score;
                if ($s->passed) {
                    $perExamAcc[$eid]['passed']++;
                }
            }
        }
        $perExam = [];
        foreach ($perExamAcc as $eid => $acc) {
            $graded = $acc['graded'];
            $perExam[] = [
                'examId' => $examById[$eid]->exam_code ?? $eid,
                'examName' => $examById[$eid]->name ?? $eid,
                'submissionCount' => $acc['total'],
                'passedCount' => $acc['passed'],
                'passRate' => $graded === 0 ? 0.0 : round(($acc['passed'] / $graded) * 100, 1),
                'averagePercent' => $graded === 0 ? null : round($acc['sumPercent'] / $graded, 2),
            ];
        }
        usort($perExam, fn ($a, $b) => $b['submissionCount'] <=> $a['submissionCount']);

        // --- Sections 4 + 5: top / bottom scorers (graded-only average) ---
        $studentMeta = [];
        foreach ($students as $st) {
            $studentMeta[$st->id] = ['fullName' => $st->full_name, 'username' => $st->username];
        }
        $perStudentAcc = [];
        foreach ($submissions as $s) {
            if ((int) $s->pending_essay_count > 0) {
                continue;
            }
            $uid = $s->user_id;
            if (! isset($perStudentAcc[$uid])) {
                $perStudentAcc[$uid] = ['count' => 0, 'sumPercent' => 0.0];
            }
            $perStudentAcc[$uid]['count']++;
            $perStudentAcc[$uid]['sumPercent'] += (float) $s->percent_score;
        }
        $scorers = [];
        foreach ($perStudentAcc as $uid => $acc) {
            if ($acc['count'] === 0) {
                continue;
            }
            $meta = $studentMeta[$uid] ?? ['fullName' => '(unknown)', 'username' => '(unknown)'];
            $scorers[] = [
                'studentName' => $meta['fullName'],
                'username' => $meta['username'],
                'averagePercent' => round($acc['sumPercent'] / $acc['count'], 2),
                'examsTaken' => $acc['count'],
            ];
        }
        $topScorers = $scorers;
        usort($topScorers, fn ($a, $b) => $b['averagePercent'] <=> $a['averagePercent']);
        $topScorers = array_slice($topScorers, 0, 10);
        $bottomScorers = $scorers;
        usort($bottomScorers, fn ($a, $b) => $a['averagePercent'] <=> $b['averagePercent']);
        $bottomScorers = array_slice($bottomScorers, 0, 10);

        // --- Section 7: strongest / weakest topics (cross-teacher) ---
        $topicAcc = [];
        foreach ($submissions as $s) {
            if ((int) $s->pending_essay_count > 0) {
                continue;
            }
            $rows = is_array($s->topic_breakdown) ? $s->topic_breakdown : [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['topic']) || ! is_string($row['topic'])) {
                    continue;
                }
                $t = $row['topic'];
                if (! isset($topicAcc[$t])) {
                    $topicAcc[$t] = ['earned' => 0.0, 'possible' => 0.0, 'submissionCount' => 0];
                }
                $topicAcc[$t]['earned'] += (float) ($row['earned'] ?? 0);
                $topicAcc[$t]['possible'] += (float) ($row['possible'] ?? 0);
                $topicAcc[$t]['submissionCount']++;
            }
        }
        $topicEntries = [];
        foreach ($topicAcc as $topic => $agg) {
            $topicEntries[] = [
                'topic' => $topic,
                'percent' => $agg['possible'] == 0.0 ? 0.0 : round(($agg['earned'] / $agg['possible']) * 100, 2),
                'submissionCount' => $agg['submissionCount'],
            ];
        }
        $strongest = $topicEntries;
        usort($strongest, fn ($a, $b) => $b['percent'] <=> $a['percent']);
        $strongest = array_slice($strongest, 0, 10);
        $weakest = $topicEntries;
        usort($weakest, fn ($a, $b) => $a['percent'] <=> $b['percent']);
        $weakest = array_slice($weakest, 0, 10);

        // --- Section 8: bank composition + usage ---
        $bankRows = BankQuestion::query()
            ->when($teacherFilter, fn ($q) => $q->where('created_by', $teacherFilter))
            ->get(['id', 'subject', 'type', 'difficulty']);
        $bySubject = $this->foldCounts($bankRows, fn ($r) => $r->subject);
        $byType = $this->foldCounts($bankRows, fn ($r) => $r->type, self::TYPE_LABELS);
        $byDifficulty = $this->foldCounts($bankRows, fn ($r) => $r->difficulty, self::DIFFICULTY_LABELS);

        // Usage: how often each bank question is referenced from any exam
        // question. Scoped to the teacher's exams when filtered.
        $usageRows = ExamQuestion::query()
            ->whereNotNull('source_bank_question_id')
            ->when($hasScope, fn ($q) => $q->whereIn('exam_id', $examIds))
            ->get(['source_bank_question_id']);
        $usageCounts = [];
        foreach ($usageRows as $row) {
            $sid = $row->source_bank_question_id;
            if (! is_string($sid) || $sid === '') {
                continue;
            }
            $usageCounts[$sid] = ($usageCounts[$sid] ?? 0) + 1;
        }
        arsort($usageCounts);
        $topUsedIds = array_slice(array_keys($usageCounts), 0, 10);
        $promptById = [];
        if (count($topUsedIds) > 0) {
            $promptById = BankQuestion::whereIn('id', $topUsedIds)
                ->pluck('prompt', 'id')->all();
        }
        $mostUsed = [];
        foreach ($topUsedIds as $sid) {
            $mostUsed[] = [
                'bankQuestionId' => $sid,
                'prompt' => mb_substr((string) ($promptById[$sid] ?? ''), 0, 80),
                'usageCount' => $usageCounts[$sid],
            ];
        }
        $unusedCount = $bankQuestionCount - count($usageCounts);
        if ($unusedCount < 0) {
            $unusedCount = 0;
        }

        // --- Section 9: grading workload ---
        $examOwner = [];
        foreach ($exams as $e) {
            $examOwner[$e->id] = $e->created_by;
        }
        $teacherIds = $teachers->pluck('id')->all();
        $teacherNameById = $teachers->pluck('full_name', 'id')->all();
        $pendingByTeacher = [];
        foreach ($teacherIds as $tid) {
            $pendingByTeacher[$tid] = 0;
        }
        $pendingSubmissions = $submissions->filter(fn ($s) => (int) $s->pending_essay_count > 0)->values();
        foreach ($pendingSubmissions as $s) {
            $owner = $examOwner[$s->exam_id] ?? null;
            if ($owner === null || ! array_key_exists($owner, $pendingByTeacher)) {
                continue;
            }
            $pendingByTeacher[$owner]++;
        }
        $perTeacher = [];
        foreach ($teacherIds as $tid) {
            $perTeacher[] = [
                'teacherId' => $tid,
                'teacherName' => $teacherNameById[$tid] ?? '',
                'pendingCount' => $pendingByTeacher[$tid] ?? 0,
            ];
        }
        usort($perTeacher, fn ($a, $b) => $b['pendingCount'] <=> $a['pendingCount']);

        $now = Carbon::now();
        $oldestPending = $pendingSubmissions->map(function ($s) use ($examById, $now) {
            $submittedAt = $s->submitted_at;

            return [
                'submissionId' => $s->id,
                'studentName' => $s->full_name,
                'examName' => $examById[$s->exam_id]->name ?? $s->exam_id,
                'submittedAt' => $submittedAt?->toIso8601String(),
                'daysOld' => $submittedAt
                    ? max(0, (int) floor($submittedAt->diffInSeconds($now) / 86400))
                    : 0,
            ];
        })->sort(function ($a, $b) {
            // Oldest first; nulls sink to the end.
            $av = $a['submittedAt'] ?? '~';
            $bv = $b['submittedAt'] ?? '~';

            return strcmp($av, $bv);
        })->values()->take(10)->all();

        return [
            'system' => [
                'teacherCount' => $teachers->count(),
                'activeTeacherCount' => $activeTeachers->count(),
                'disabledTeacherCount' => $teachers->count() - $activeTeachers->count(),
                'studentCount' => $students->count(),
                'examCount' => $examCount,
                'submissionCount' => $submissionCount,
                'bankQuestionCount' => $bankQuestionCount,
                'submissionsByDay' => $submissionsByDayOut,
            ],
            'performance' => [
                'perExam' => $perExam,
                'topScorers' => $topScorers,
                'bottomScorers' => $bottomScorers,
                'scoreDistribution' => $scoreDistribution,
            ],
            'topics' => ['strongest' => $strongest, 'weakest' => $weakest],
            'bank' => [
                'bySubject' => $bySubject,
                'byType' => $byType,
                'byDifficulty' => $byDifficulty,
                'mostUsed' => $mostUsed,
                'unusedCount' => $unusedCount,
            ],
            'workload' => ['perTeacher' => $perTeacher, 'oldestPending' => $oldestPending],
        ];
    }

    // ===============================================================
    // Item analysis tab — per-exam, per-question difficulty + answer mix
    // ===============================================================

    /**
     * For every exam (optionally one teacher) that has at least one
     * submission, recompute per-question item statistics straight from each
     * submission's answers_snapshot:
     *   - responses: how many submissions answered the item at all
     *   - correctCount + correctRate: auto-graded correctness (essays use the
     *     stored manual score via the same Scoring engine semantics)
     *   - difficultyIndex: mean awarded / possible (0..1), the classic p-value
     *   - averagePercent: difficultyIndex as a 0..100 figure for the bar
     *   - optionCounts: choice distribution for single/multi questions so the
     *     teacher can spot non-functioning distractors
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildItemAnalysis(?string $teacherFilter): array
    {
        $exams = Exam::query()
            ->when($teacherFilter, fn ($q) => $q->where('created_by', $teacherFilter))
            ->orderBy('name')
            ->get(['id', 'exam_code', 'name', 'passing_grade']);
        if ($exams->isEmpty()) {
            return [];
        }

        $examIds = $exams->pluck('id')->all();

        // Submission count per exam — drop exams with no data (matches the
        // original perExam "only exams with submissions" rule).
        $subCountByExam = ExamSubmission::query()
            ->whereIn('exam_id', $examIds)
            ->selectRaw('exam_id, COUNT(*) as c')
            ->groupBy('exam_id')
            ->pluck('c', 'exam_id')->all();
        $examsWithSubs = $exams->filter(fn ($e) => ($subCountByExam[$e->id] ?? 0) > 0)->values();
        if ($examsWithSubs->isEmpty()) {
            return [];
        }
        $liveExamIds = $examsWithSubs->pluck('id')->all();

        // Questions for those exams.
        $questionsByExam = ExamQuestion::query()
            ->whereIn('exam_id', $liveExamIds)
            ->orderBy('position')
            ->get(['id', 'exam_id', 'position', 'type', 'topic', 'prompt', 'options', 'correct_answer', 'points'])
            ->groupBy('exam_id');

        // Submissions (snapshots + manual scores) for those exams.
        $submissionsByExam = ExamSubmission::query()
            ->whereIn('exam_id', $liveExamIds)
            ->get(['id', 'exam_id', 'answers_snapshot', 'manual_scores', 'pending_essay_count'])
            ->groupBy('exam_id');

        $out = [];
        foreach ($examsWithSubs as $exam) {
            $questions = $questionsByExam->get($exam->id) ?? collect();
            $subs = $submissionsByExam->get($exam->id) ?? collect();
            if ($questions->isEmpty()) {
                continue;
            }

            $items = [];
            foreach ($questions as $q) {
                $isEssay = $q->type === 'essay';
                $points = (float) $q->points;
                $correct = $q->correct_answer;

                $responses = 0;
                $correctCount = 0;
                $sumAwarded = 0.0;
                $gradedResponses = 0; // submissions that contribute to difficulty
                $optionCounts = [];

                // Seed option labels (so a 0-count distractor still shows).
                $options = is_array($q->options) ? $q->options : [];
                foreach ($options as $opt) {
                    if (is_array($opt) && isset($opt['id'])) {
                        $optionCounts[(string) $opt['id']] = 0;
                    }
                }

                foreach ($subs as $sub) {
                    $snap = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];
                    $answer = $snap[$q->id] ?? null;
                    $answered = $this->isAnswered($answer);
                    if ($answered) {
                        $responses++;
                        // Tally option picks for choice questions.
                        if (in_array($q->type, ['single_choice', 'multi_select'], true)) {
                            foreach ($this->answerLabels($answer) as $label) {
                                $optionCounts[$label] = ($optionCounts[$label] ?? 0) + 1;
                            }
                        }
                    }

                    if ($isEssay) {
                        $manual = is_array($sub->manual_scores) ? ($sub->manual_scores[$q->id] ?? null) : null;
                        if (is_numeric($manual)) {
                            $awarded = max(0.0, min($points, (float) $manual));
                            $sumAwarded += $awarded;
                            $gradedResponses++;
                            if ($points > 0 && $awarded >= $points) {
                                $correctCount++;
                            }
                        }
                        // Ungraded essays don't contribute to difficulty.

                        continue;
                    }

                    // Auto-graded: award via the shared scoring semantics.
                    $awarded = $this->awardedFor($q->type, $answer, $correct, $points);
                    $sumAwarded += $awarded;
                    $gradedResponses++;
                    if ($points > 0 && $awarded >= $points) {
                        $correctCount++;
                    }
                }

                $difficultyIndex = ($gradedResponses === 0 || $points == 0.0)
                    ? null
                    : round(($sumAwarded / $gradedResponses) / $points, 3);

                $optionList = [];
                foreach ($optionCounts as $label => $count) {
                    $isCorrect = $this->labelIsCorrect($label, $correct);
                    $optionList[] = [
                        'label' => $label,
                        'count' => $count,
                        'isCorrect' => $isCorrect,
                    ];
                }

                $items[] = [
                    'questionId' => $q->id,
                    'position' => (int) $q->position,
                    'type' => $q->type,
                    'topic' => $q->topic,
                    'prompt' => mb_substr((string) $q->prompt, 0, 160),
                    'points' => $points,
                    'responses' => $responses,
                    'gradedResponses' => $gradedResponses,
                    'correctCount' => $correctCount,
                    'correctRate' => $gradedResponses === 0
                        ? null
                        : round(($correctCount / $gradedResponses) * 100, 1),
                    'difficultyIndex' => $difficultyIndex,
                    'averagePercent' => $difficultyIndex === null ? null : round($difficultyIndex * 100, 1),
                    'isEssay' => $isEssay,
                    'optionCounts' => $optionList,
                ];
            }

            $out[] = [
                'examDatabaseId' => $exam->id,
                'examId' => $exam->exam_code,
                'examName' => $exam->name,
                'passingGrade' => (int) $exam->passing_grade,
                'submissionCount' => (int) ($subCountByExam[$exam->id] ?? 0),
                'questionCount' => count($items),
                'items' => $items,
            ];
        }

        return $out;
    }

    // ===============================================================
    // Helpers
    // ===============================================================

    /**
     * Fold groupBy-style counts: bucket null / unknown / out-of-vocab values
     * into "—" and sort by count desc. Mirrors the original foldGroupCounts.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @param  callable  $getValue
     * @param  array<int,string>|null  $valid
     * @return array<int,array{label:string,count:int}>
     */
    private function foldCounts($rows, callable $getValue, ?array $valid = null): array
    {
        $acc = [];
        foreach ($rows as $row) {
            $raw = $getValue($row);
            $label = is_string($raw) && $raw !== '' && ($valid === null || in_array($raw, $valid, true))
                ? $raw
                : '—';
            $acc[$label] = ($acc[$label] ?? 0) + 1;
        }
        $out = [];
        foreach ($acc as $label => $count) {
            $out[] = ['label' => $label, 'count' => $count];
        }
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /** True when a submitted answer is a real (non-empty) response. */
    private function isAnswered(mixed $answer): bool
    {
        if ($answer === null) {
            return false;
        }
        if (is_string($answer)) {
            return trim($answer) !== '';
        }
        if (is_array($answer)) {
            return count($answer) > 0;
        }

        return true;
    }

    /**
     * Choice labels picked by one submission (uppercased), for the option
     * distribution. Single-choice yields one label; multi-select many.
     *
     * @return array<int,string>
     */
    private function answerLabels(mixed $answer): array
    {
        if (is_array($answer)) {
            $out = [];
            foreach ($answer as $v) {
                $s = strtoupper(trim((string) $v));
                if ($s !== '') {
                    $out[] = $s;
                }
            }

            return $out;
        }
        if (is_string($answer) && trim($answer) !== '') {
            return [strtoupper(trim($answer))];
        }

        return [];
    }

    /** Whether an option label is part of the correct answer set. */
    private function labelIsCorrect(string $label, mixed $correct): bool
    {
        $needle = strtoupper(trim($label));
        if (is_array($correct)) {
            foreach ($correct as $c) {
                if (strtoupper(trim((string) $c)) === $needle) {
                    return true;
                }
            }

            return false;
        }
        if (is_string($correct)) {
            return strtoupper(trim($correct)) === $needle;
        }

        return false;
    }

    /**
     * Awarded points for an auto-graded answer, reusing the same partial-
     * credit rules as the live scoring engine (numeric tolerance bands,
     * multi-select (correct-wrong)/total) so item stats match real grades.
     */
    private function awardedFor(string $type, mixed $answer, mixed $correct, float $points): float
    {
        if ($type === 'numeric') {
            return round($points * \App\Support\Scoring::numericCreditRatio($answer, $correct), 2);
        }
        if ($type === 'multi_select') {
            return round($points * \App\Support\Scoring::multiSelectCreditRatio($answer, $correct), 2);
        }

        // single_choice / short_text: exact match (case-insensitive scalar).
        $norm = function (mixed $v): string {
            return strtolower(trim((string) $v));
        };
        if ($answer === null) {
            return 0.0;
        }

        return $norm($answer) === $norm($correct) ? $points : 0.0;
    }

    /**
     * Teacher options for the scope picker. value = user id (matches
     * createdBy), label fields mirror the original AdminTeacherSummary.
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
