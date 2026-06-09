<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\AnswerDraft;
use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamAccessToken;
use App\Models\ExamMedia;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Models\LearningObjective;
use App\Support\CryptoSecrets;
use App\Support\Scoring;
use App\Support\Shuffle;
use App\Support\Tokens;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Teacher → Exam detail. Faithful port of the original Next.js
 * TeacherExamDetailClient + its API routes:
 *
 *   - GET    /api/teacher/exams/[examId]                       (detail)
 *   - GET    /api/teacher/exams/[examId]/questions            (questions + topicOrder)
 *   - POST   /api/teacher/exams/[examId]/questions            (add)
 *   - PATCH  /api/teacher/exams/[examId]/questions/[qId]      (update, incl. type)
 *   - DELETE /api/teacher/exams/[examId]/questions/[qId]      (delete + densify)
 *   - POST   /api/teacher/exams/[examId]/questions/from-bank  (add from bank)
 *   - POST   /api/teacher/exams/[examId]/questions/[qId]/replace (auto / manual)
 *   - POST   /api/teacher/exams/[examId]/auto-fill            (composition auto-fill)
 *   - GET    /api/teacher/exams/[examId]/tokens               (list)
 *   - POST   /api/teacher/exams/[examId]/tokens               (create)
 *   - PATCH  /api/teacher/tokens/[tokenId]                    (activate/deactivate — unused by UI)
 *   - DELETE /api/teacher/tokens/[tokenId]                    (delete)
 *   - POST   /api/teacher/tokens/[tokenId]/regenerate         (rotate)
 *   - PATCH  /api/teacher/exams/[examId]/seb                  (SEB toggle / rotate)
 *   - POST   /api/teacher/exams/[examId]/finalize-drafts      (recover abandoned)
 *   - POST   /api/teacher/exams/[examId]/reset-session        (reset did-not-attempt)
 *   - GET    /api/teacher/exams/[examId]/submissions          (per-exam list)
 *   - GET    /api/teacher/bank                                (bank picker list)
 *   - GET    /api/teacher/bank/options                        (bank picker filters)
 *
 * Teacher owns the exam via created_by = $user->id; admin sees all.
 */
class ExamDetailController extends Controller
{
    private const TYPE_VALUES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

    // Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
    private const DIFFICULTY_VALUES = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'];

    private const TYPE_KEYS = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

    private const DIFFICULTY_KEYS = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'];

    private const DEFAULT_TYPE_DISTRIBUTION = [
        'single_choice' => 0, 'multi_select' => 0, 'short_text' => 0, 'numeric' => 0, 'essay' => 0,
    ];

    // Default % split across Bloom's levels (sums to 100). Front-weighted toward
    // understand+apply where most standard exam content lives.
    private const DEFAULT_DIFFICULTY_DISTRIBUTION = [
        'remember' => 15, 'understand' => 25, 'apply' => 25, 'analyze' => 15,
        'evaluate' => 10, 'create' => 7, 'olympiad' => 3,
    ];

    private const DEFAULT_MEDIA_TARGETS = ['images' => 0, 'tables' => 0];

    // =====================================================================
    // Page
    // =====================================================================

    /**
     * GET /teacher/exams/{examId} — the full authoring surface as a single
     * Inertia render: exam detail + metrics + questions category tree data +
     * tokens (decrypted preview) + composition targets + submissions count.
     */
    public function show(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return Inertia::render('teacher/ExamDetail', ['notFound' => true]);
        }

        $detail = $this->buildDetail($exam);
        $questionsPayload = $this->questionsPayload($exam, $user);

        return Inertia::render('teacher/ExamDetail', [
            'detail' => $detail,
            'questions' => $questionsPayload['questions'],
            'topicOrder' => $questionsPayload['topicOrder'],
            'tokens' => $this->tokensPayload($exam),
        ]);
    }

    /** GET /teacher/exams/{examId}/detail — exam detail + stats (JSON, used by the page refresh()). */
    public function detail(Request $request, string $examId)
    {
        $exam = $this->loadOwnedExam($examId, $request->user());
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        return response()->json(['exam' => $this->buildDetail($exam)]);
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
            'typeDistribution' => $this->withDefaults($exam->type_distribution, self::DEFAULT_TYPE_DISTRIBUTION),
            'difficultyDistribution' => $this->withDefaults($exam->difficulty_distribution, self::DEFAULT_DIFFICULTY_DISTRIBUTION),
            'mediaTargets' => $this->withDefaults($exam->media_targets, self::DEFAULT_MEDIA_TARGETS),
            'sebRequired' => (bool) $exam->seb_required,
            'sebSecret' => $exam->seb_secret,
            'questionCount' => $questionCount,
            'totalSubmissions' => $totalSubmissions,
            'averagePercent' => $avgRaw !== null ? round((float) $avgRaw, 2) : null,
            'passedCount' => $passedCount,
            'activeTokenCount' => $activeTokenCount,
        ];
    }

    // =====================================================================
    // Questions
    // =====================================================================

    /** GET /teacher/exams/{examId}/questions — questions + curriculum topicOrder (JSON). */
    public function questions(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        return response()->json($this->questionsPayload($exam, $user));
    }

    /** POST /teacher/exams/{examId}/questions — append a question at N+1 (JSON). */
    public function addQuestion(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        try {
            $shaped = $this->validateAndShapeQuestion($request->all());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $position = ExamQuestion::where('exam_id', $exam->id)->count() + 1;

        $created = ExamQuestion::create([
            'exam_id' => $exam->id,
            'position' => $position,
            'type' => $shaped['type'],
            'topic' => $shaped['topic'],
            'tags' => [],
            'prompt' => $shaped['prompt'],
            'options' => $shaped['options'],
            'points' => $shaped['points'],
            'correct_answer' => $shaped['correctAnswer'],
            'explanation_text' => $shaped['explanationText'],
        ]);

        return response()->json(['questionId' => $created->id]);
    }

    /** PATCH /teacher/exams/{examId}/questions/{qId} — full replace of writable fields (JSON). */
    public function updateQuestion(Request $request, string $examId, string $qId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        try {
            $shaped = $this->validateAndShapeQuestion($request->all());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $existing = ExamQuestion::where('id', $qId)->where('exam_id', $exam->id)->first();
        if (! $existing) {
            return response()->json(['error' => 'Question not found.'], 404);
        }

        $existing->update([
            'type' => $shaped['type'],
            'topic' => $shaped['topic'],
            'prompt' => $shaped['prompt'],
            'options' => $shaped['options'],
            'points' => $shaped['points'],
            'correct_answer' => $shaped['correctAnswer'],
            'explanation_text' => $shaped['explanationText'],
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /teacher/exams/{examId}/questions/{qId} — remove + densify
     * positions to 1..N. If sourced from the bank and no other exam uses
     * that bank row (and the caller may delete it), the bank row goes too.
     */
    public function deleteQuestion(Request $request, string $examId, string $qId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $target = ExamQuestion::where('id', $qId)->where('exam_id', $exam->id)->first();
        if (! $target) {
            return response()->json(['error' => 'Question not found.'], 404);
        }

        $higher = ExamQuestion::where('exam_id', $exam->id)
            ->where('position', '>', $target->position)
            ->orderBy('position')
            ->get(['id', 'position']);

        // Cascade-delete the source bank row only when no OTHER exam still
        // references it and the caller owns it (teacher: uploaded_by self).
        $bankIdToDelete = null;
        if ($target->source_bank_question_id) {
            $otherExamRefs = ExamQuestion::where('source_bank_question_id', $target->source_bank_question_id)
                ->where('exam_id', '!=', $exam->id)
                ->count();
            if ($otherExamRefs === 0) {
                $bankOwned = BankQuestion::where('id', $target->source_bank_question_id)
                    ->when($user->role === 'teacher', fn ($q) => $q->where('uploaded_by', $user->id))
                    ->first(['id']);
                if ($bankOwned) {
                    $bankIdToDelete = $bankOwned->id;
                }
            }
        }

        DB::transaction(function () use ($target, $higher, $bankIdToDelete) {
            $target->delete();
            foreach ($higher as $q) {
                ExamQuestion::where('id', $q->id)->update(['position' => $q->position - 1]);
            }
            if ($bankIdToDelete) {
                BankQuestion::where('id', $bankIdToDelete)->delete();
            }
        });

        return response()->json(['ok' => true, 'bankDeleted' => $bankIdToDelete !== null]);
    }

    /** POST /teacher/exams/{examId}/questions/from-bank — copy bank rows in at N+1..N+M (JSON). */
    public function addFromBank(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $bankIds = collect($request->input('bankIds', []))
            ->filter(fn ($v) => is_string($v))
            ->values()
            ->all();
        if (count($bankIds) === 0) {
            return response()->json(['added' => 0]);
        }

        $bankRows = BankQuestion::whereIn('id', $bankIds)
            ->when($user->role === 'teacher', fn ($q) => $q->where('uploaded_by', $user->id))
            ->get();

        $alreadyAdded = ExamQuestion::where('exam_id', $exam->id)
            ->whereIn('source_bank_question_id', $bankIds)
            ->pluck('source_bank_question_id')
            ->filter()
            ->flip();

        $toAdd = $bankRows->reject(fn ($b) => $alreadyAdded->has($b->id))->values();
        if ($toAdd->isEmpty()) {
            return response()->json(['added' => 0]);
        }

        $currentCount = ExamQuestion::where('exam_id', $exam->id)->count();
        $added = 0;

        DB::transaction(function () use ($toAdd, $exam, $currentCount, &$added) {
            foreach ($toAdd as $bank) {
                $added++;
                $position = $currentCount + $added;
                $q = ExamQuestion::create([
                    'exam_id' => $exam->id,
                    'position' => $position,
                    'type' => $bank->type,
                    'topic' => $bank->topic,
                    'tags' => $bank->tags ?? [],
                    'prompt' => $bank->prompt,
                    'options' => $bank->options,
                    'points' => $bank->points,
                    'source_bank_question_id' => $bank->id,
                    'correct_answer' => $bank->correct_answer,
                    'explanation_text' => $bank->explanation_text ?? '',
                ]);
                if ($bank->media_url && $bank->media_type) {
                    ExamMedia::create([
                        'question_id' => $q->id,
                        'type' => $bank->media_type,
                        'url' => $bank->media_url,
                    ]);
                }
            }
        });

        return response()->json(['added' => $added]);
    }

    /**
     * POST /teacher/exams/{examId}/questions/{qId}/replace — in-place swap.
     *   { mode: "auto" }                        → server cascade
     *   { mode: "manual", bankId: <bankRowId> } → explicit pick
     * Keeps the ExamQuestion row (id + position) and overwrites its content
     * + media from the chosen bank source.
     */
    public function replaceFromBank(Request $request, string $examId, string $qId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $mode = $request->input('mode');
        if ($mode !== 'auto' && $mode !== 'manual') {
            return response()->json(['error' => "mode is required ('auto' or 'manual')."], 400);
        }

        $target = ExamQuestion::where('id', $qId)->where('exam_id', $exam->id)->first();
        if (! $target) {
            return response()->json(['error' => 'Question not found in this exam.'], 404);
        }

        // Every bank id already referenced by some question in this exam.
        $usedIds = ExamQuestion::where('exam_id', $exam->id)
            ->pluck('source_bank_question_id')
            ->filter()
            ->values();

        $candidate = null;

        if ($mode === 'manual') {
            $bankId = $request->input('bankId');
            if (! is_string($bankId) || $bankId === '') {
                return response()->json(['error' => 'bankId is required for manual mode.'], 400);
            }
            if ($usedIds->contains($bankId) && $bankId !== $target->source_bank_question_id) {
                return response()->json(['error' => 'That bank question is already in this exam.'], 400);
            }
            $candidate = BankQuestion::where('id', $bankId)
                ->when($user->role === 'teacher', fn ($q) => $q->where('uploaded_by', $user->id))
                ->first();
            if (! $candidate) {
                return response()->json(['error' => 'Bank question not found.'], 404);
            }
        } else {
            // auto — look up the source's difficulty + subtopic to cascade.
            $baseDifficulty = null;
            $baseSubtopic = null;
            if ($target->source_bank_question_id) {
                $src = BankQuestion::where('id', $target->source_bank_question_id)
                    ->first(['difficulty', 'subtopic']);
                $baseDifficulty = $src?->difficulty;
                $baseSubtopic = $src?->subtopic;
            }

            // Build the cascade (most-specific → broadest), per original spec.
            $cascade = [];
            if ($baseDifficulty && $baseSubtopic) {
                $cascade[] = ['difficulty' => $baseDifficulty, 'topic' => $target->topic, 'subtopic' => $baseSubtopic];
                $cascade[] = ['difficulty' => $baseDifficulty, 'subtopic' => $baseSubtopic];
            }
            if ($baseDifficulty) {
                $cascade[] = ['difficulty' => $baseDifficulty, 'topic' => $target->topic];
            }
            $cascade[] = ['topic' => $target->topic];

            $applyScope = function ($q) use ($user, $usedIds) {
                if ($user->role === 'teacher') {
                    $q->where('uploaded_by', $user->id);
                }
                if ($usedIds->isNotEmpty()) {
                    $q->whereNotIn('id', $usedIds->all());
                }

                return $q;
            };

            // Pass 1 — same type as the question being replaced.
            foreach ($cascade as $filter) {
                $candidate = BankQuestion::query()
                    ->tap($applyScope)
                    ->where($filter)
                    ->where('type', $target->type)
                    ->orderBy('created_at')
                    ->first();
                if ($candidate) {
                    break;
                }
            }

            // Pass 2 — relax the type constraint only if pass 1 found nothing.
            if (! $candidate) {
                foreach ($cascade as $filter) {
                    $candidate = BankQuestion::query()
                        ->tap($applyScope)
                        ->where($filter)
                        ->orderBy('created_at')
                        ->first();
                    if ($candidate) {
                        break;
                    }
                }
            }

            if (! $candidate) {
                return response()->json([
                    'error' => 'No replacement candidate found in the bank (no question with matching topic / subtopic / difficulty remains unused).',
                ], 404);
            }
        }

        $updated = DB::transaction(function () use ($target, $candidate) {
            ExamMedia::where('question_id', $target->id)->delete();
            $target->update([
                'type' => $candidate->type,
                'topic' => $candidate->topic,
                'tags' => $candidate->tags ?? [],
                'prompt' => $candidate->prompt,
                'options' => $candidate->options,
                'points' => $candidate->points,
                'source_bank_question_id' => $candidate->id,
                'correct_answer' => $candidate->correct_answer,
                'explanation_text' => $candidate->explanation_text ?? '',
                'difficulty' => $candidate->difficulty,
                'language' => $candidate->language,
            ]);
            if ($candidate->media_url && $candidate->media_type) {
                ExamMedia::create([
                    'question_id' => $target->id,
                    'type' => $candidate->media_type,
                    'url' => $candidate->media_url,
                ]);
            }

            return $target->fresh();
        });

        return response()->json([
            'replacedWith' => [
                'id' => $updated->id,
                'position' => $updated->position,
                'sourceBankQuestionId' => $updated->source_bank_question_id,
                'topic' => $updated->topic,
                'prompt' => $updated->prompt,
            ],
        ]);
    }

    /**
     * POST /teacher/exams/{examId}/auto-fill — top-up the exam toward its
     * stored type + difficulty composition from the bank. Returns
     * { added, warnings, matrix }.
     */
    public function autoFill(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $typeDistribution = $this->withDefaults($exam->type_distribution, self::DEFAULT_TYPE_DISTRIBUTION);
        $difficultyDistribution = $this->withDefaults($exam->difficulty_distribution, self::DEFAULT_DIFFICULTY_DISTRIBUTION);

        $totalTarget = 0;
        foreach (self::TYPE_KEYS as $k) {
            $totalTarget += (int) ($typeDistribution[$k] ?? 0);
        }
        if ($totalTarget === 0) {
            return response()->json([
                'added' => 0,
                'warnings' => ["No type targets set — fill in 'Total of each type of question' first."],
                'matrix' => [],
            ]);
        }

        $examLanguage = trim((string) ($exam->language ?? ''));
        $examSubject = trim((string) ($exam->subject ?? ''));

        $candidates = BankQuestion::query()
            ->when($user->role === 'teacher', fn ($q) => $q->where('uploaded_by', $user->id))
            ->when($examLanguage !== '', fn ($q) => $q->where('language', $examLanguage))
            ->when($examSubject !== '', fn ($q) => $q->where('subject', $examSubject))
            ->get();

        $alreadyCopied = ExamQuestion::where('exam_id', $exam->id)
            ->whereNotNull('source_bank_question_id')
            ->pluck('source_bank_question_id')
            ->filter()
            ->flip();
        $inScope = $candidates->reject(fn ($q) => $alreadyCopied->has($q->id))->values();

        $warnings = [];
        if ($inScope->isEmpty()) {
            $ownedCount = BankQuestion::query()
                ->when($user->role === 'teacher', fn ($q) => $q->where('uploaded_by', $user->id))
                ->count();
            if ($ownedCount > 0) {
                $scope = $examSubject !== ''
                    ? "language \"{$exam->language}\" and subject \"{$exam->subject}\""
                    : "language \"{$exam->language}\"";
                $warnings[] = "No bank questions match the exam {$scope}.";
            }
        }

        // Bucket the pool by (type, difficulty).
        $bankByKey = [];
        foreach ($inScope as $q) {
            $diff = $q->difficulty ?? 'understand';
            $bankByKey["{$q->type}|{$diff}"][] = $q;
        }

        // Count CURRENT exam questions per (type, difficulty), with the same
        // null-difficulty fallback to the source bank row the original uses.
        $existingRows = ExamQuestion::where('exam_id', $exam->id)
            ->get(['type', 'difficulty', 'source_bank_question_id']);
        $fallbackBankIds = $existingRows
            ->filter(fn ($r) => ! $r->difficulty && $r->source_bank_question_id)
            ->pluck('source_bank_question_id')->unique()->values();
        $fallbackDiffByBankId = [];
        if ($fallbackBankIds->isNotEmpty()) {
            foreach (BankQuestion::whereIn('id', $fallbackBankIds->all())->get(['id', 'difficulty']) as $b) {
                if ($b->difficulty) {
                    $fallbackDiffByBankId[$b->id] = $b->difficulty;
                }
            }
        }
        $existingCounts = [];
        foreach ($existingRows as $r) {
            $diff = $r->difficulty
                ?? ($r->source_bank_question_id ? ($fallbackDiffByBankId[$r->source_bank_question_id] ?? 'understand') : 'understand');
            $key = "{$r->type}|{$diff}";
            $existingCounts[$key] = ($existingCounts[$key] ?? 0) + 1;
        }

        $matrix = [];
        $toCopy = [];

        foreach (self::TYPE_KEYS as $type) {
            $typeTarget = (int) ($typeDistribution[$type] ?? 0);
            if ($typeTarget === 0) {
                continue;
            }
            $cellTargets = $this->distributeByDifficulty($typeTarget, $difficultyDistribution);
            foreach (self::DIFFICULTY_KEYS as $difficulty) {
                $wanted = $cellTargets[$difficulty];
                if ($wanted === 0) {
                    continue;
                }
                $existingHere = $existingCounts["{$type}|{$difficulty}"] ?? 0;
                $remaining = max(0, $wanted - $existingHere);
                if ($remaining === 0) {
                    $matrix[] = ['type' => $type, 'difficulty' => $difficulty, 'wanted' => $wanted, 'got' => 0];

                    continue;
                }
                $basePool = $bankByKey["{$type}|{$difficulty}"] ?? [];
                $shuffled = Shuffle::withSeed($basePool, "{$exam->exam_code}_autofill_{$type}_{$difficulty}");
                $got = min($remaining, count($shuffled));
                if ($existingHere + $got < $wanted) {
                    $warnings[] = "Wanted {$wanted} {$type} ({$difficulty}) but exam will have "
                        .($existingHere + $got)." ({$existingHere} already in exam + ".count($shuffled).' available in bank).';
                }
                $matrix[] = ['type' => $type, 'difficulty' => $difficulty, 'wanted' => $wanted, 'got' => $got];
                for ($i = 0; $i < $got; $i++) {
                    $toCopy[] = $shuffled[$i];
                }
            }
        }

        if (count($toCopy) === 0) {
            return response()->json([
                'added' => 0,
                'warnings' => count($warnings) > 0 ? $warnings : ['Bank has no matching questions.'],
                'matrix' => $matrix,
            ]);
        }

        // Sort picks into curriculum-topic order before assigning positions.
        $topicOrderIdx = $this->curriculumTopicIndex($user);
        $topicRank = function (string $topic) use ($topicOrderIdx) {
            $idx = $topicOrderIdx[mb_strtolower(trim($topic))] ?? null;

            return $idx === null ? PHP_INT_MAX : $idx;
        };
        usort($toCopy, function ($a, $b) use ($topicRank) {
            $ra = $topicRank($a->topic);
            $rb = $topicRank($b->topic);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $topicCmp = strcmp($a->topic, $b->topic);
            if ($topicCmp !== 0) {
                return $topicCmp;
            }
            $typeCmp = array_search($a->type, self::TYPE_KEYS) <=> array_search($b->type, self::TYPE_KEYS);
            if ($typeCmp !== 0) {
                return $typeCmp;
            }
            $aDiff = $a->difficulty ?? 'understand';
            $bDiff = $b->difficulty ?? 'understand';

            return array_search($aDiff, self::DIFFICULTY_KEYS) <=> array_search($bDiff, self::DIFFICULTY_KEYS);
        });

        $currentCount = ExamQuestion::where('exam_id', $exam->id)->count();
        $added = 0;

        DB::transaction(function () use ($toCopy, $exam, $currentCount, &$added) {
            foreach ($toCopy as $bank) {
                $added++;
                $position = $currentCount + $added;
                $q = ExamQuestion::create([
                    'exam_id' => $exam->id,
                    'position' => $position,
                    'type' => $bank->type,
                    'topic' => $bank->topic,
                    'tags' => $bank->tags ?? [],
                    'prompt' => $bank->prompt,
                    'options' => $bank->options,
                    'points' => $bank->points,
                    'source_bank_question_id' => $bank->id,
                    'correct_answer' => $bank->correct_answer,
                    'explanation_text' => $bank->explanation_text ?? '',
                    'difficulty' => $bank->difficulty,
                    'language' => $bank->language,
                ]);
                if ($bank->media_url && $bank->media_type) {
                    ExamMedia::create([
                        'question_id' => $q->id,
                        'type' => $bank->media_type,
                        'url' => $bank->media_url,
                    ]);
                }
            }
        });

        return response()->json(['added' => $added, 'warnings' => $warnings, 'matrix' => $matrix]);
    }

    // =====================================================================
    // Tokens
    // =====================================================================

    /** GET /teacher/exams/{examId}/tokens — list (decrypted preview) (JSON). */
    public function tokens(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        return response()->json(['tokens' => $this->tokensPayload($exam)]);
    }

    /**
     * POST /teacher/exams/{examId}/tokens — mint a token.
     *   body: { maxUses: 1..5000, expiresInDays: 0..3650 (0 = never) }
     * Returns the summary with `code` = plaintext (only time it's surfaced
     * inline; tokenPreview stores the AES-GCM ciphertext for later reads).
     */
    public function createToken(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $maxUses = (int) $request->input('maxUses');
        if ($maxUses < 1 || $maxUses > 5000) {
            return response()->json(['error' => 'Max uses must be between 1 and 5000.'], 400);
        }

        // The original API takes an absolute expiresAt; the UI converts
        // expiresInDays → ISO. We accept expiresInDays here (per the task
        // contract) and compute the date server-side.
        $expiresInDays = $request->input('expiresInDays', 0);
        $expiresAt = null;
        if (is_numeric($expiresInDays) && (int) $expiresInDays > 0) {
            $days = (int) $expiresInDays;
            if ($days > 3650) {
                return response()->json(['error' => 'Expiry must be 3650 days or fewer.'], 400);
            }
            $expiresAt = now()->addDays($days);
        }

        // Generate a unique 6-char code (collision retry).
        $code = '';
        $digest = '';
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = Tokens::generatePlain();
            $candidateDigest = Tokens::digest($candidate);
            if (! ExamAccessToken::where('token_digest', $candidateDigest)->exists()) {
                $code = $candidate;
                $digest = $candidateDigest;
                break;
            }
        }
        if ($code === '') {
            return response()->json(['error' => 'Unable to generate a unique token. Please try again.'], 500);
        }

        $created = ExamAccessToken::create([
            'exam_id' => $exam->id,
            'token_digest' => $digest,
            'token_preview' => CryptoSecrets::encryptTokenPreview($code),
            'max_uses' => $maxUses,
            'used_count' => 0,
            'expires_at' => $expiresAt,
            'active' => true,
            'created_by' => $user->id,
            'created_by_name' => $user->full_name,
        ]);

        return response()->json([
            'token' => [
                'id' => $created->id,
                'code' => $code,
                'examDatabaseId' => $exam->id,
                'examId' => $exam->exam_code,
                'examName' => $exam->name,
                'createdByName' => $user->full_name,
                'createdAt' => $created->created_at->toIso8601String(),
                'expiresAt' => $created->expires_at?->toIso8601String(),
                'maxUses' => (int) $created->max_uses,
                'usedCount' => 0,
                'active' => true,
            ],
        ]);
    }

    /** DELETE /teacher/tokens/{tokenId} — hard-delete a token (JSON). */
    public function deleteToken(Request $request, string $tokenId)
    {
        $user = $request->user();
        $token = ExamAccessToken::with('exam:id,created_by')->find($tokenId);
        if (! $token) {
            return response()->json(['error' => 'Token not found.'], 404);
        }
        if ($user->role === 'teacher' && $token->exam->created_by !== $user->id) {
            return response()->json(['error' => 'This token is not for one of your exams.'], 403);
        }

        $token->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /teacher/tokens/{tokenId}/regenerate — deactivate the old row and
     * mint a fresh code carrying forward the REMAINING uses (not maxUses),
     * same exam scope, class, and expiry. Returns the new code as plaintext.
     */
    public function regenerateToken(Request $request, string $tokenId)
    {
        $user = $request->user();
        $existing = ExamAccessToken::with('exam:id,exam_code,name,created_by')->find($tokenId);
        if (! $existing) {
            return response()->json(['error' => 'Token not found.'], 404);
        }
        if ($user->role === 'teacher' && $existing->exam->created_by !== $user->id) {
            return response()->json(['error' => 'This token is not for one of your exams.'], 403);
        }

        $newCode = '';
        $newDigest = '';
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = Tokens::generatePlain();
            $candidateDigest = Tokens::digest($candidate);
            if (! ExamAccessToken::where('token_digest', $candidateDigest)->exists()) {
                $newCode = $candidate;
                $newDigest = $candidateDigest;
                break;
            }
        }
        if ($newCode === '') {
            return response()->json(['error' => 'Unable to generate a unique token. Please try again.'], 500);
        }

        $remainingUses = (int) $existing->max_uses - (int) $existing->used_count;
        if ($remainingUses <= 0) {
            return response()->json([
                'error' => 'This token has been fully used. Issue a new token instead of regenerating.',
            ], 400);
        }

        $created = DB::transaction(function () use ($existing, $newDigest, $newCode, $remainingUses, $user) {
            $existing->update(['active' => false]);

            return ExamAccessToken::create([
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

        return response()->json([
            'token' => [
                'id' => $created->id,
                'code' => $newCode,
                'examDatabaseId' => $existing->exam->id,
                'examId' => $existing->exam->exam_code,
                'examName' => $existing->exam->name,
                'createdByName' => $user->full_name,
                'createdAt' => $created->created_at->toIso8601String(),
                'expiresAt' => $created->expires_at?->toIso8601String(),
                'maxUses' => (int) $created->max_uses,
                'usedCount' => 0,
                'active' => true,
            ],
        ]);
    }

    // =====================================================================
    // Anti-cheating (SEB)
    // =====================================================================

    /**
     * PATCH /teacher/exams/{examId}/seb — toggle SEB protection.
     *   body: { enabled: bool, rotate?: bool }
     * Mints a fresh secret on first enable or when rotate=true; keeps the
     * secret on disable so re-enable can reuse it.
     */
    public function setSeb(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        if (! is_bool($request->input('enabled'))) {
            return response()->json(['error' => 'enabled (boolean) is required.'], 400);
        }
        $enabled = (bool) $request->input('enabled');
        $rotate = $request->input('rotate') === true;

        $sebSecret = $exam->seb_secret;
        if ($enabled && ($rotate || ! $sebSecret)) {
            $sebSecret = $this->newSebSecret();
        }

        $exam->update([
            'seb_required' => $enabled,
            'seb_secret' => $sebSecret,
        ]);

        return response()->json([
            'sebRequired' => (bool) $exam->seb_required,
            'sebSecret' => $exam->seb_secret,
        ]);
    }

    // =====================================================================
    // Session recovery
    // =====================================================================

    /**
     * POST /teacher/exams/{examId}/finalize-drafts — recover abandoned
     * sessions: for every draft/expired session with saved drafts and no
     * submission, score the live key, copy anti-cheat events, flip to
     * submitted. Idempotent; empty sessions skipped.
     */
    public function finalizeDrafts(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $questions = ExamQuestion::where('exam_id', $exam->id)
            ->orderBy('position')
            ->get(['id', 'topic', 'points', 'type', 'correct_answer']);
        if ($questions->isEmpty()) {
            return response()->json(['error' => 'This exam has no questions; nothing to finalize.'], 400);
        }

        $questionsForScoring = $questions->map(fn ($q) => [
            'id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type,
        ])->all();
        $keysByQuestionId = $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all();

        $sessions = ExamSession::where('exam_id', $exam->id)
            ->whereIn('status', ['draft', 'expired'])
            ->whereDoesntHave('submission')
            ->with(['user:id,username,full_name', 'drafts:id,session_id,question_id,value'])
            ->get();

        $created = 0;
        $skippedEmpty = 0;
        $errors = 0;
        $errorDetails = [];

        foreach ($sessions as $s) {
            if ($s->drafts->isEmpty()) {
                $skippedEmpty++;

                continue;
            }
            $answersSnapshot = [];
            foreach ($s->drafts as $d) {
                $answersSnapshot[$d->question_id] = $d->value;
            }
            $scoring = Scoring::scoreExam($questionsForScoring, $keysByQuestionId, $answersSnapshot);
            $passed = $scoring['percentScore'] >= $exam->passing_grade;
            $acEvents = is_array($s->anti_cheat_events) ? $s->anti_cheat_events : [];

            try {
                DB::transaction(function () use ($s, $exam, $answersSnapshot, $scoring, $passed, $acEvents) {
                    ExamSubmission::create([
                        'exam_id' => $exam->id,
                        'user_id' => $s->user_id,
                        'session_id' => $s->id,
                        'attempt' => $s->attempt,
                        'username' => $s->user->username,
                        'full_name' => $s->user->full_name,
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
                        'anti_cheat_events' => count($acEvents) > 0 ? $acEvents : null,
                    ]);
                    ExamSession::where('id', $s->id)->update([
                        'status' => 'submitted',
                        'submitted_at' => $s->submitted_at ?? now(),
                        'last_saved_at' => $s->last_saved_at ?? now(),
                    ]);
                });
                $created++;
            } catch (\Throwable $e) {
                $errors++;
                $errorDetails[] = [
                    'username' => $s->user->username ?? '?',
                    'error' => substr($e->getMessage(), 0, 160),
                ];
            }
        }

        return response()->json([
            'examCode' => $exam->exam_code,
            'candidates' => $sessions->count(),
            'recovered' => $created,
            'skippedEmpty' => $skippedEmpty,
            'errors' => $errors,
            'errorDetails' => array_slice($errorDetails, 0, 10),
        ]);
    }

    /**
     * POST /teacher/exams/{examId}/reset-session — wipe a "did not attempt"
     * student's sessions + drafts + (empty) submissions for this exam so a
     * fresh attempt starts cleanly. Refuses if any real work exists.
     *   body: { studentIdentifier: string }
     */
    public function resetSession(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $studentId = is_string($request->input('studentIdentifier'))
            ? trim($request->input('studentIdentifier'))
            : '';
        if ($studentId === '') {
            return response()->json(['error' => 'studentIdentifier is required.'], 400);
        }

        // Refuse if the student has any scored / non-empty submission.
        $subs = ExamSubmission::where('exam_id', $exam->id)
            ->where('user_id', $studentId)
            ->get(['id', 'final_score', 'answers_snapshot']);
        foreach ($subs as $s) {
            $snapCount = is_array($s->answers_snapshot) ? count($s->answers_snapshot) : 0;
            if ($s->final_score > 0 || $snapCount > 0) {
                return response()->json([
                    'error' => 'Refusing to reset: this student already has scored submissions. Delete those first if intended.',
                ], 409);
            }
        }

        // Refuse if any session has saved drafts.
        $sessions = ExamSession::where('exam_id', $exam->id)
            ->where('user_id', $studentId)
            ->withCount('drafts')
            ->get();
        foreach ($sessions as $sess) {
            if ($sess->drafts_count > 0) {
                return response()->json([
                    'error' => 'Refusing to reset: session '.substr($sess->id, 0, 8).' has '.$sess->drafts_count
                        .' saved drafts — use "Recover abandoned" instead.',
                ], 409);
            }
        }

        $result = DB::transaction(function () use ($exam, $studentId) {
            $deletedSubmissions = ExamSubmission::where('exam_id', $exam->id)->where('user_id', $studentId)->delete();
            $deletedSessions = ExamSession::where('exam_id', $exam->id)->where('user_id', $studentId)->delete();

            return ['deletedSubmissions' => $deletedSubmissions, 'deletedSessions' => $deletedSessions];
        });

        return response()->json([
            'examCode' => $exam->exam_code,
            'examName' => $exam->name,
            'deletedSubmissions' => $result['deletedSubmissions'],
            'deletedSessions' => $result['deletedSessions'],
        ]);
    }

    // =====================================================================
    // Submissions
    // =====================================================================

    /** GET /teacher/exams/{examId}/submissions — per-exam list, newest first (JSON). */
    public function submissions(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $rows = ExamSubmission::where('exam_id', $exam->id)
            ->orderByDesc('submitted_at')
            ->get();

        $summaries = $rows->map(function ($s) use ($exam) {
            $pending = (int) $s->pending_essay_count;

            return [
                'id' => $s->id,
                'examId' => $exam->exam_code,
                'examName' => $s->exam_name,
                'studentName' => $s->full_name,
                'username' => $s->username,
                'finalScore' => $s->final_score,
                'possibleScore' => $s->possible_score,
                'percentScore' => $s->percent_score,
                'passed' => (bool) $s->passed,
                'pendingEssayCount' => $pending,
                'gradingStatus' => $pending > 0 ? 'pending_grading' : 'graded',
                'submittedAt' => $s->submitted_at->toIso8601String(),
            ];
        })->values();

        return response()->json(['submissions' => $summaries]);
    }

    // =====================================================================
    // Bank picker (list + filters + in-exam ids)
    // =====================================================================

    /**
     * GET /teacher/bank — bank list for the picker.
     * Query: language, subject, topic, subtopic, difficulty, type (all
     * optional equality). Teacher sees only their own uploads.
     */
    public function bankQuestions(Request $request)
    {
        $user = $request->user();

        $language = $this->trimOrNull($request->query('language'));
        $subject = $this->trimOrNull($request->query('subject'));
        $topic = $this->trimOrNull($request->query('topic'));
        $subtopic = $this->trimOrNull($request->query('subtopic'));
        $difficulty = $this->parseEnum($request->query('difficulty'), self::DIFFICULTY_VALUES);
        $type = $this->parseEnum($request->query('type'), self::TYPE_VALUES);

        $rows = BankQuestion::query()
            ->when($user->role === 'teacher', fn ($q) => $q->where('uploaded_by', $user->id))
            ->when($language !== null, fn ($q) => $q->where('language', $language))
            ->when($subject !== null, fn ($q) => $q->where('subject', $subject))
            ->when($topic !== null, fn ($q) => $q->where('topic', $topic))
            ->when($subtopic !== null, fn ($q) => $q->where('subtopic', $subtopic))
            ->when($difficulty !== null, fn ($q) => $q->where('difficulty', $difficulty))
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->get();

        $questions = $rows->map(fn ($q) => [
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
            'points' => $q->points,
            'correctAnswer' => $q->correct_answer,
            'explanationText' => $q->explanation_text ?? '',
            'createdByName' => $q->created_by_name ?? '(unknown)',
            'uploadedBy' => $q->uploaded_by,
            'uploadedByName' => $q->uploaded_by_name,
            'createdAt' => $q->created_at->toIso8601String(),
            'sourceFileName' => $q->source_file_name,
            'mediaUrl' => $q->media_url,
            'mediaType' => $q->media_type,
        ])->values();

        return response()->json([
            'questions' => $questions,
            'topicOrder' => $this->curriculumTopicOrder($user),
        ]);
    }

    /** GET /teacher/bank/options — distinct filter values for the picker (JSON). */
    public function bankOptions(Request $request)
    {
        $user = $request->user();

        $rows = BankQuestion::query()
            ->when($user->role === 'teacher', fn ($q) => $q->where('uploaded_by', $user->id))
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

        $sortedKeys = function (array $assoc) {
            $keys = array_keys($assoc);
            sort($keys);

            return $keys;
        };

        return response()->json([
            'languages' => $sortedKeys($languages),
            'subjects' => $sortedKeys($subjects),
            'topics' => $sortedKeys($topics),
            'subtopics' => $sortedKeys($subtopics),
            'difficulties' => array_keys($difficulties),
            'types' => array_keys($types),
        ]);
    }

    /** GET /teacher/exams/{examId}/bank-in-exam — bank ids already used here (JSON). */
    public function bankInExam(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadOwnedExam($examId, $user);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $ids = ExamQuestion::where('exam_id', $exam->id)
            ->whereNotNull('source_bank_question_id')
            ->pluck('source_bank_question_id')
            ->unique()
            ->values();

        return response()->json(['ids' => $ids]);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Load an exam by uuid or exam_code, scoped to the requester (admin: any). */
    private function loadOwnedExam(string $identifier, $user): ?Exam
    {
        $exam = Exam::where('id', $identifier)->orWhere('exam_code', $identifier)->first();
        if (! $exam) {
            return null;
        }
        if ($user->role !== 'admin' && $exam->created_by !== $user->id) {
            return null;
        }

        return $exam;
    }

    /** Merge a stored JSON map over a defaults map (defaults fill missing keys). */
    private function withDefaults($stored, array $defaults): array
    {
        $stored = is_array($stored) ? $stored : [];

        return array_merge($defaults, $stored);
    }

    /**
     * Build the questions list payload + curriculum topicOrder, mirroring
     * GET /api/teacher/exams/[examId]/questions. Subtopic + difficulty are
     * derived from the source bank row when not set locally.
     *
     * @return array{questions: array<int,array<string,mixed>>, topicOrder: array<int,string>}
     */
    private function questionsPayload(Exam $exam, $user): array
    {
        $rows = ExamQuestion::with(['media' => fn ($q) => $q->orderBy('sort_order')])
            ->where('exam_id', $exam->id)
            ->orderBy('position')
            ->get();

        // Look up subtopic + difficulty for bank-sourced rows.
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
            'topicOrder' => $this->curriculumTopicOrder($user),
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

    /** Curriculum topic order (first-seen, sortOrder sequence) from learning_objectives. */
    private function curriculumTopicOrder($user): array
    {
        $loRows = LearningObjective::query()
            ->when($user->role === 'teacher', fn ($q) => $q->where('uploaded_by', $user->id))
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

    /** Curriculum topic → index lookup (lowercased) for sorting auto-fill picks. */
    private function curriculumTopicIndex($user): array
    {
        $idx = [];
        $i = 0;
        foreach ($this->curriculumTopicOrder($user) as $topic) {
            $idx[mb_strtolower(trim($topic))] = $i++;
        }

        return $idx;
    }

    /**
     * Validate + shape a question create/update body. Throws
     * InvalidArgumentException with the user-facing message on failure.
     * Mirrors validateAndShape() in the original POST/PATCH routes.
     *
     * @return array{type:string,topic:string,prompt:string,points:float,options:?array,correctAnswer:mixed,explanationText:string}
     */
    private function validateAndShapeQuestion(array $body): array
    {
        $type = $body['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::TYPE_VALUES, true)) {
            throw new \InvalidArgumentException('Invalid question type.');
        }

        $prompt = is_string($body['prompt'] ?? null) ? trim($body['prompt']) : '';
        if (mb_strlen($prompt) < 2) {
            throw new \InvalidArgumentException('Question prompt is required.');
        }

        $points = is_numeric($body['points'] ?? null) ? (float) $body['points'] : NAN;
        if (! is_finite($points) || $points <= 0 || $points > 100) {
            throw new \InvalidArgumentException('Points must be between 1 and 100.');
        }

        $topic = is_string($body['topic'] ?? null) ? trim($body['topic']) : '';
        if (mb_strlen($topic) < 1) {
            throw new \InvalidArgumentException('Topic is required.');
        }

        $explanationText = is_string($body['explanationText'] ?? null) ? trim($body['explanationText']) : '';
        if (mb_strlen($explanationText) < 1) {
            throw new \InvalidArgumentException('Explanation is required.');
        }

        $options = null;
        if ($type === 'single_choice' || $type === 'multi_select') {
            $rawOptions = $body['options'] ?? null;
            if (! is_array($rawOptions) || count($rawOptions) < 2) {
                throw new \InvalidArgumentException('Choice questions need at least 2 options.');
            }
            $cap = $type === 'multi_select' ? 6 : 5;
            if (count($rawOptions) > $cap) {
                $label = $type === 'multi_select' ? 'multi-select' : 'single-choice';
                throw new \InvalidArgumentException("Maximum {$cap} options for {$label} questions.");
            }
            $options = $this->normalizeOptions($type, $rawOptions);
            $seen = [];
            foreach ($options as $o) {
                if (($o['id'] ?? '') === '' || ($o['text'] ?? '') === '') {
                    throw new \InvalidArgumentException('Each option needs both an ID and text.');
                }
                if (isset($seen[$o['id']])) {
                    throw new \InvalidArgumentException("Duplicate option ID \"{$o['id']}\".");
                }
                $seen[$o['id']] = true;
            }
        }

        $rawCorrect = $body['correctAnswer'] ?? null;
        if ($type === 'single_choice') {
            if (! is_string($rawCorrect) || trim($rawCorrect) === '') {
                throw new \InvalidArgumentException('Pick the correct option.');
            }
            $correctId = strtoupper(trim($rawCorrect));
            $ids = array_column($options ?? [], 'id');
            if (! in_array($correctId, $ids, true)) {
                throw new \InvalidArgumentException('Correct option must match one of the options.');
            }
        }
        if ($type === 'multi_select') {
            if (! is_array($rawCorrect) || count($rawCorrect) === 0) {
                throw new \InvalidArgumentException('Pick at least one correct option.');
            }
            $validIds = array_flip(array_column($options ?? [], 'id'));
            foreach ($rawCorrect as $id) {
                if (! isset($validIds[strtoupper((string) $id)])) {
                    throw new \InvalidArgumentException('Correct options must match the listed options.');
                }
            }
        }
        if ($type === 'short_text') {
            if (! is_string($rawCorrect) || trim($rawCorrect) === '') {
                throw new \InvalidArgumentException('Provide the expected text answer.');
            }
        }
        if ($type === 'numeric') {
            if (! is_numeric($rawCorrect)) {
                throw new \InvalidArgumentException('Provide a numeric correct answer.');
            }
        }

        $correctAnswer = $type === 'essay' ? '' : $this->normalizeCorrectAnswer($type, $rawCorrect);

        return [
            'type' => $type,
            'topic' => $topic,
            'prompt' => $prompt,
            'points' => $points,
            'options' => $options,
            'correctAnswer' => $correctAnswer,
            'explanationText' => $explanationText,
        ];
    }

    /** Uppercase+trim option IDs, trim text. Non-choice types → null. */
    private function normalizeOptions(string $type, ?array $options): ?array
    {
        if ($type !== 'single_choice' && $type !== 'multi_select') {
            return null;
        }
        if ($options === null) {
            return null;
        }

        return array_map(fn ($o) => [
            'id' => strtoupper(trim((string) ($o['id'] ?? ''))),
            'text' => trim((string) ($o['text'] ?? '')),
        ], $options);
    }

    /** Normalize a correct answer to its canonical stored shape per type. */
    private function normalizeCorrectAnswer(string $type, mixed $raw): mixed
    {
        if ($type === 'single_choice') {
            return is_string($raw) ? strtoupper(trim($raw)) : '';
        }
        if ($type === 'multi_select') {
            if (! is_array($raw)) {
                return [];
            }
            $out = array_map(fn ($v) => strtoupper(trim((string) $v)), $raw);
            sort($out, SORT_STRING);

            return $out;
        }
        if ($type === 'short_text') {
            return is_string($raw) ? trim($raw) : (string) ($raw ?? '');
        }
        if ($type === 'numeric') {
            return is_numeric($raw) ? 0 + $raw : (float) $raw;
        }

        return $raw;
    }

    /**
     * Hare-Niemeyer split of a per-type target across the difficulty buckets
     * so percentages turn into integer counts summing exactly to typeTarget.
     *
     * @return array<string,int>
     */
    private function distributeByDifficulty(int $typeTarget, array $difficultyPercents): array
    {
        $result = array_fill_keys(self::DIFFICULTY_KEYS, 0);
        if ($typeTarget <= 0) {
            return $result;
        }
        $raw = [];
        $sumFloor = 0;
        foreach (self::DIFFICULTY_KEYS as $key) {
            $raw[$key] = ($typeTarget * (float) ($difficultyPercents[$key] ?? 0)) / 100;
            $result[$key] = (int) floor($raw[$key]);
            $sumFloor += $result[$key];
        }
        $leftover = $typeTarget - $sumFloor;
        $sortedByRemainder = array_map(
            fn ($key) => ['key' => $key, 'r' => $raw[$key] - floor($raw[$key])],
            self::DIFFICULTY_KEYS
        );
        usort($sortedByRemainder, fn ($a, $b) => $b['r'] <=> $a['r']);
        $i = 0;
        while ($leftover > 0 && $i < count($sortedByRemainder)) {
            $result[$sortedByRemainder[$i]['key']] += 1;
            $leftover--;
            $i++;
        }

        return $result;
    }

    /** Fresh 24-char SEB Browser Exam Key from the ambiguity-free alphabet. */
    private function newSebSecret(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 24; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim($value);

        return $t === '' ? null : $t;
    }

    private function parseEnum(?string $value, array $allowed): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim($value);

        return in_array($t, $allowed, true) ? $t : null;
    }
}
