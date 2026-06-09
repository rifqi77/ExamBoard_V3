<?php

namespace App\Http\Controllers;

use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\LearningObjective;
use App\Models\User;
use App\Services\AiProviders;
use App\Support\AiPrompt;
use App\Support\Capabilities;
use App\Support\Subjects;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * AI exam generation — port of the original teacher AiGenerateClient +
 * /api/teacher/ai-generate/{run,status} routes. Serves BOTH the teacher
 * page (/teacher/ai-generate) and the admin page (/admin/ai-generate); the
 * teacher path is gated by the `ai.generate` capability, admin bypasses.
 *
 * run() builds the exam-generation prompt (App\Support\AiPrompt — verbatim
 * port), calls the active provider (App\Services\AiProviders), parses the
 * returned JSON, and:
 *   - returns the sanitized questions for the React preview, AND
 *   - auto-inserts them into bank_questions (admin-owned, uploaded_by =
 *     caller), mirroring the original run route's bank auto-insert, AND
 *   - optionally appends them to an exam (when examId is supplied).
 */
class AiGenerateController extends Controller
{
    private const VALID_TYPES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

    // Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
    private const VALID_DIFFICULTIES = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'];

    private const VALID_CURRICULA = ['kurikulum_merdeka', 'as_a_level', 'ib', 'olympiad'];

    // ─────────────────────────────────────────────────────────────────────
    // Pages
    // ─────────────────────────────────────────────────────────────────────

    /** GET /teacher/ai-generate */
    public function showTeacher(Request $request)
    {
        $this->authorizeGenerate($request);

        return Inertia::render('teacher/AiGenerate', $this->payload($request, '/teacher'));
    }

    /** GET /admin/ai-generate */
    public function showAdmin(Request $request)
    {
        // Admin route group is already role:admin-gated; no capability check.
        return Inertia::render('admin/AiGenerate', $this->payload($request, '/admin'));
    }

    /**
     * GET /{teacher|admin}/ai-generate/status — JSON.
     * Tells the page whether the server can call the AI directly
     * (autoEnabled=true) or the teacher should fall back to manual
     * copy-prompt mode (no API key for the active text provider).
     */
    public function status(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            $this->authorizeGenerate($request);
        }

        $settings = AiProviders::activeSettings();
        $keys = AiProviders::keyStatus();
        $provider = $settings['textProvider'];

        return response()->json([
            'autoEnabled' => ($keys[$provider] ?? false) === true,
            'provider' => $provider,
            'model' => $settings['textModel'],
        ]);
    }

    /**
     * GET /{teacher|admin}/ai-generate/learning-objectives — JSON.
     * Curriculum + subject filtered LO catalog, used by the page to refresh
     * the picker when the teacher switches curriculum (parity with the
     * original fetch to /api/teacher/learning-objectives).
     */
    public function learningObjectives(Request $request)
    {
        $isAdmin = $request->user()->role === 'admin';
        if (! $isAdmin) {
            $this->authorizeCurriculum($request);
        }

        return response()->json([
            'learningObjectives' => $this->loCatalog($request),
        ]);
    }

    /**
     * POST /{teacher|admin}/ai-generate/prompt — JSON.
     * Builds the Phase-1 question prompt + Phase-2 image prompt from the
     * current form body and returns them (plus the normalised config the
     * client embeds in the downloaded JSON envelope). The prompt builder is
     * the single source of truth (App\Support\AiPrompt) so the manual flow
     * and the auto flow always agree.
     */
    public function prompt(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            $this->authorizeGenerate($request);
        }

        $totalCount = max(1, (int) $request->input('totalCount', 1));
        $promptInput = $this->buildPromptInput($request, $totalCount);

        return response()->json([
            'questionPrompt' => AiPrompt::buildQuestionPrompt($promptInput),
            'imagePrompt' => AiPrompt::buildImagePrompt($promptInput),
            'config' => $promptInput,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Run
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /{teacher|admin}/ai-generate/run
     *   body: AiPromptInput (+ optional examId)
     *
     * Returns JSON:
     *   200 { questions, count, bankInserted, examInserted, imageCount,
     *         imageFailures, provider, model }
     *   503 { error } — active provider's key missing (page flips to manual)
     *   400 { error } — bad totalCount
     *   502 { error } — AI returned unparseable JSON / no valid questions
     *   500 { error } — any other provider error (key shapes redacted)
     */
    public function run(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';
        if (! $isAdmin) {
            $this->authorizeGenerate($request);
        }

        $settings = AiProviders::activeSettings();
        $keys = AiProviders::keyStatus();
        // Pollinations always reports true; this only 503s for keyed providers.
        if (($keys[$settings['textProvider']] ?? false) !== true) {
            return response()->json([
                'error' => "No API key configured for {$settings['textProvider']}. Ask your admin to either paste a key under Admin → AI settings, or switch the active provider to Pollinations.ai (works with no key).",
            ], 503);
        }

        $totalCount = $request->input('totalCount');
        if (! is_numeric($totalCount) || (int) $totalCount < 1 || (int) $totalCount > 100) {
            return response()->json(['error' => 'totalCount must be between 1 and 100.'], 400);
        }
        $totalCount = (int) $totalCount;

        $promptInput = $this->buildPromptInput($request, $totalCount);
        $prompt = AiPrompt::buildQuestionPrompt($promptInput);

        // ----- Text generation -----
        try {
            $result = AiProviders::generateText(['prompt' => $prompt, 'json' => true], $settings);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->redactKeys($e->getMessage() ?: 'AI text request failed.')], 500);
        }

        $arr = $this->tryParseJsonArray($result['text']);
        if ($arr === null) {
            return response()->json([
                'error' => 'The AI didn\'t return parseable JSON. First 200 chars of response: '.mb_substr($result['text'], 0, 200),
            ], 502);
        }

        $questions = [];
        foreach ($arr as $raw) {
            $q = $this->sanitizeQuestion($raw);
            if ($q !== null) {
                $questions[] = $q;
            }
        }
        if (count($questions) === 0) {
            return response()->json(['error' => 'AI returned no valid questions.'], 502);
        }

        // ----- Image generation (optional) -----
        // Attempt only when the teacher asked for images, the admin enabled
        // an image provider, and that provider's key is set. Faithful to the
        // original: jobs filter on mediaFile + imagePrompt set by the AI.
        $requestedImageCount = min(max(0, (int) ($promptInput['mediaImageCount'] ?? 0)), $totalCount);
        $imageProvider = $settings['imageProvider'];
        $wantImages = $requestedImageCount > 0
            && $imageProvider !== 'off'
            && ($keys[$imageProvider] ?? false) === true;

        $imageInfoByFilename = [];
        $imageCount = 0;
        $imageFailures = 0;

        if ($wantImages) {
            foreach ($questions as &$q) {
                $mediaFile = $q['mediaFile'] ?? null;
                $imgPrompt = $q['imagePrompt'] ?? null;
                if (! is_string($mediaFile) || $mediaFile === '' || ! is_string($imgPrompt) || $imgPrompt === '') {
                    continue;
                }
                try {
                    $img = AiProviders::generateImage(['prompt' => $imgPrompt], $settings);
                    $imageInfoByFilename[$mediaFile] = [
                        'dataUrl' => 'data:'.$img['mimeType'].';base64,'.$img['base64'],
                    ];
                    $imageCount++;
                } catch (\Throwable) {
                    $imageFailures++;
                }
            }
            unset($q);
            // Strip mediaFile/imagePrompt for questions whose image gen failed.
            foreach ($questions as &$q) {
                if (($q['mediaFile'] ?? null) && ! isset($imageInfoByFilename[$q['mediaFile']])) {
                    $q['mediaFile'] = null;
                    $q['imagePrompt'] = null;
                }
            }
            unset($q);
        } else {
            // No image generation this run — drop the suggestion fields so the
            // preview + bank rows don't reference files that don't exist.
            foreach ($questions as &$q) {
                $q['mediaFile'] = null;
                $q['imagePrompt'] = null;
            }
            unset($q);
        }

        // ----- Auto-insert into the bank (admin-owned, uploaded_by = caller) -----
        [$ownerId, $ownerName] = $this->bankOwner($user);
        $sourceFileName = 'ai-questions-'.now()->timestamp.'.json';
        $bankInserted = 0;
        foreach ($questions as $q) {
            if (! in_array($q['type'], self::VALID_TYPES, true)) {
                continue;
            }
            $difficulty = in_array($q['difficulty'], self::VALID_DIFFICULTIES, true) ? $q['difficulty'] : 'understand';
            $info = ($q['mediaFile'] ?? null) ? ($imageInfoByFilename[$q['mediaFile']] ?? null) : null;
            $mediaUrl = $info['dataUrl'] ?? null;
            try {
                BankQuestion::create([
                    'type' => $q['type'],
                    'language' => $q['language'] !== '' ? $q['language'] : 'English',
                    'subject' => Subjects::canonical($q['subject']) ?: 'General',
                    'topic' => $q['topic'] !== '' ? $q['topic'] : 'General',
                    'subtopic' => $q['subtopic'] ?? null,
                    'difficulty' => $difficulty,
                    'tags' => [],
                    'prompt' => $q['prompt'],
                    'options' => $q['options'],
                    'points' => $q['points'],
                    'correct_answer' => $q['correctAnswer'],
                    'explanation_text' => $q['explanationText'],
                    'created_by' => $ownerId,
                    'created_by_name' => $ownerName,
                    'uploaded_by' => $user->id,
                    'uploaded_by_name' => $user->full_name,
                    'source_file_name' => $sourceFileName,
                    'media_url' => $mediaUrl,
                    'media_type' => $mediaUrl ? 'image' : null,
                ]);
                $bankInserted++;
            } catch (\Throwable) {
                // Per-row failure (exotic options shape) shouldn't kill the batch.
            }
        }

        // ----- Optional: append straight into an exam the caller owns -----
        $examInserted = 0;
        $examId = $request->input('examId');
        if (is_string($examId) && $examId !== '') {
            $examInserted = $this->appendToExam($examId, $questions, $user, $isAdmin);
        }

        return response()->json([
            'questions' => $questions,
            'count' => count($questions),
            'bankInserted' => $bankInserted,
            'examInserted' => $examInserted,
            'imageCount' => $imageCount,
            'imageFailures' => $imageFailures,
            'provider' => $settings['textProvider'],
            'model' => $settings['textModel'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Prompt input assembly
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build the AiPrompt input array from the request body. The client masks
     * locked params to 0, but we re-clamp the media counts to totalCount and
     * resolve selected LO ids → {topic, subtopic, text} server-side so the
     * prompt scope is authoritative (parity with the client's promptInput).
     *
     * @return array<string,mixed>
     */
    private function buildPromptInput(Request $request, int $totalCount): array
    {
        $diffRaw = $request->input('difficultyCounts', []);
        $typeRaw = $request->input('typeCounts', []);
        $intInRange = fn ($v) => max(0, (int) (is_numeric($v) ? $v : 0));

        $difficultyCounts = [
            // Bloom's revised taxonomy (replaces easy/medium/hard/hots) + olympiad.
            'remember' => $intInRange($diffRaw['remember'] ?? 0),
            'understand' => $intInRange($diffRaw['understand'] ?? 0),
            'apply' => $intInRange($diffRaw['apply'] ?? 0),
            'analyze' => $intInRange($diffRaw['analyze'] ?? 0),
            'evaluate' => $intInRange($diffRaw['evaluate'] ?? 0),
            'create' => $intInRange($diffRaw['create'] ?? 0),
            'olympiad' => $intInRange($diffRaw['olympiad'] ?? 0),
        ];
        $typeCounts = [
            'single_choice' => $intInRange($typeRaw['single_choice'] ?? 0),
            'multi_select' => $intInRange($typeRaw['multi_select'] ?? 0),
            'short_text' => $intInRange($typeRaw['short_text'] ?? 0),
            'numeric' => $intInRange($typeRaw['numeric'] ?? 0),
            'essay' => $intInRange($typeRaw['essay'] ?? 0),
        ];

        // Resolve selected LO ids against the (curriculum+subject) catalog.
        $selectedLos = [];
        $selectedIds = $request->input('selectedLoIds', []);
        if (is_array($selectedIds) && count($selectedIds) > 0) {
            $rows = LearningObjective::query()
                ->whereIn('id', array_values(array_filter($selectedIds, 'is_string')))
                ->get(['id', 'topic', 'subtopic', 'text']);
            // Preserve the client's selection order.
            $byId = $rows->keyBy('id');
            foreach ($selectedIds as $id) {
                $row = is_string($id) ? $byId->get($id) : null;
                if ($row) {
                    $selectedLos[] = [
                        'topic' => $row->topic,
                        'subtopic' => $row->subtopic,
                        'text' => $row->text,
                    ];
                }
            }
        }

        $sourceUrls = $request->input('sourceUrls', []);
        if (! is_array($sourceUrls)) {
            $sourceUrls = [];
        }

        $olympiadIntensity = (string) $request->input('olympiadIntensity', 'moderate');
        if (! in_array($olympiadIntensity, ['intro', 'moderate', 'extreme'], true)) {
            $olympiadIntensity = 'moderate';
        }

        return [
            'language' => (string) $request->input('language', 'English'),
            'subject' => (string) $request->input('subject', ''),
            'topic' => (string) $request->input('topic', ''),
            'subtopic' => (string) $request->input('subtopic', ''),
            'gradeLevel' => (string) $request->input('gradeLevel', ''),
            'totalCount' => $totalCount,
            'difficultyCounts' => $difficultyCounts,
            'typeCounts' => $typeCounts,
            'mediaImageCount' => min(max(0, (int) $request->input('mediaImageCount', 0)), $totalCount),
            'mediaTableCount' => min(max(0, (int) $request->input('mediaTableCount', 0)), $totalCount),
            'selectedLearningObjectives' => $selectedLos,
            'extraInstructions' => (string) $request->input('extraInstructions', ''),
            'sourceUrls' => array_values(array_filter(array_map(fn ($u) => (string) $u, $sourceUrls))),
            'olympiadIntensity' => $olympiadIntensity,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // JSON parsing + sanitisation (parity with the run route)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Extract the first top-level JSON array from a possibly fenced /
     * prose-wrapped model reply.
     *
     * @return array<int,mixed>|null
     */
    private function tryParseJsonArray(string $text): ?array
    {
        $candidate = $text;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $m)) {
            $candidate = $m[1];
        }
        $start = strpos($candidate, '[');
        $end = strrpos($candidate, ']');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $slice = substr($candidate, $start, $end - $start + 1);
        $parsed = json_decode($slice, true);

        return is_array($parsed) && array_is_list($parsed) ? $parsed : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeQuestion(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        if (! isset($raw['type']) || ! is_string($raw['type']) || ! isset($raw['prompt']) || ! is_string($raw['prompt'])) {
            return null;
        }

        return [
            'type' => $raw['type'],
            'language' => is_string($raw['language'] ?? null) ? $raw['language'] : '',
            'subject' => is_string($raw['subject'] ?? null) ? $raw['subject'] : '',
            'topic' => is_string($raw['topic'] ?? null) ? $raw['topic'] : '',
            'subtopic' => is_string($raw['subtopic'] ?? null) ? $raw['subtopic'] : null,
            'difficulty' => is_string($raw['difficulty'] ?? null) ? $raw['difficulty'] : 'understand',
            'points' => is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0,
            'prompt' => $raw['prompt'],
            'options' => is_array($raw['options'] ?? null) ? $raw['options'] : null,
            'correctAnswer' => $raw['correctAnswer'] ?? null,
            'explanationText' => is_string($raw['explanationText'] ?? null) ? $raw['explanationText'] : '',
            // The AI fills `mediaPrompt`; the original run route reads
            // `mediaFile` + `imagePrompt` for image generation. Keep all three
            // so behaviour matches the source exactly.
            'mediaPrompt' => is_string($raw['mediaPrompt'] ?? null) ? $raw['mediaPrompt'] : null,
            'mediaFile' => is_string($raw['mediaFile'] ?? null) ? $raw['mediaFile'] : null,
            'imagePrompt' => is_string($raw['imagePrompt'] ?? null) ? $raw['imagePrompt'] : null,
        ];
    }

    /**
     * Strip common API-key shapes from provider error text before surfacing
     * it to a less-privileged role (parity with redactKeys()).
     */
    private function redactKeys(string $text): string
    {
        $text = preg_replace('/sk-ant-[A-Za-z0-9_-]{16,}/', 'sk-ant-[redacted]', $text);
        $text = preg_replace('/sk-[A-Za-z0-9_-]{16,}/', 'sk-[redacted]', $text);
        $text = preg_replace('/AIza[A-Za-z0-9_-]{20,}/', 'AIza[redacted]', $text);
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/', 'Bearer [redacted]', $text);

        return $text;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Exam append
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Append the generated questions to an exam the caller owns (admin: any).
     * Returns the number of rows inserted. Positions continue from the exam's
     * current max position.
     *
     * @param  array<int,array<string,mixed>>  $questions
     */
    private function appendToExam(string $examId, array $questions, User $user, bool $isAdmin): int
    {
        $exam = Exam::find($examId);
        if (! $exam) {
            return 0;
        }
        if (! $isAdmin && $exam->created_by !== $user->id) {
            return 0;
        }

        $position = (int) (ExamQuestion::where('exam_id', $exam->id)->max('position') ?? -1) + 1;
        $inserted = 0;
        foreach ($questions as $q) {
            if (! in_array($q['type'], self::VALID_TYPES, true)) {
                continue;
            }
            $difficulty = in_array($q['difficulty'], self::VALID_DIFFICULTIES, true) ? $q['difficulty'] : 'understand';
            try {
                ExamQuestion::create([
                    'exam_id' => $exam->id,
                    'position' => $position,
                    'type' => $q['type'],
                    'topic' => $q['topic'] !== '' ? $q['topic'] : 'General',
                    'tags' => [],
                    'prompt' => $q['prompt'],
                    'options' => $q['options'],
                    'points' => $q['points'],
                    'source_bank_question_id' => null,
                    'correct_answer' => $q['correctAnswer'],
                    'explanation_text' => $q['explanationText'],
                    'explanation_media' => null,
                    'language' => $q['language'] !== '' ? $q['language'] : null,
                    'difficulty' => $difficulty,
                    'media_file' => $q['mediaFile'] ?? null,
                ]);
                $position++;
                $inserted++;
            } catch (\Throwable) {
                // skip bad row
            }
        }

        return $inserted;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Page payload + LO catalog
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request, string $basePath): array
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        $settings = AiProviders::activeSettings();
        $keys = AiProviders::keyStatus();
        $provider = $settings['textProvider'];

        // Admin can always use every parameter; teachers are gated per-cap.
        $caps = $isAdmin
            ? array_fill_keys(Capabilities::KEYS, true)
            : Capabilities::fill($user->capabilities);

        return [
            'basePath' => $basePath,
            'isAdmin' => $isAdmin,
            'capabilities' => $caps,
            'accountSubject' => $user->subject,
            'autoMode' => [
                'autoEnabled' => ($keys[$provider] ?? false) === true,
                'provider' => $provider,
                'model' => $settings['textModel'],
            ],
            // Initial LO catalog (empty until a curriculum is chosen — the page
            // refetches via learningObjectives() when the picker changes).
            'learningObjectives' => $this->loCatalog($request),
        ];
    }

    /**
     * Curriculum + subject filtered LO rows for the picker. Teachers see only
     * LOs they uploaded; admins see all. When no/invalid curriculum is given,
     * returns an empty list (parity: the picker is hidden in free-text mode).
     *
     * @return array<int,array<string,mixed>>
     */
    private function loCatalog(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        $curriculum = (string) $request->query('curriculum', '');
        if (! in_array($curriculum, self::VALID_CURRICULA, true)) {
            return [];
        }

        $subject = trim((string) $request->query('subject', ''));

        return LearningObjective::query()
            ->where('curriculum', $curriculum)
            ->when(! $isAdmin, fn ($q) => $q->where('uploaded_by', $user->id))
            ->when($subject !== '', fn ($q) => $q->where('subject', Subjects::canonical($subject)))
            ->orderBy('subject')
            ->orderBy('sort_order')
            ->get(['id', 'curriculum', 'topic', 'subtopic', 'text', 'subject'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'curriculum' => $r->curriculum,
                'topic' => $r->topic,
                'subtopic' => $r->subtopic,
                'text' => $r->text,
                'subject' => $r->subject,
            ])
            ->values()
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Authorization (parity with requireCap)
    // ─────────────────────────────────────────────────────────────────────

    /** Gate on `ai.generate`; admins bypass, non-teachers rejected. */
    private function authorizeGenerate(Request $request): void
    {
        $user = $request->user();
        if ($user && $user->role === 'admin') {
            return;
        }
        if (! $user || $user->role !== 'teacher') {
            abort(403, 'This action requires a teacher account.');
        }
        if (! Capabilities::has($user->capabilities, 'ai.generate')) {
            abort(403, 'This feature ("ai.generate") is disabled for your account. Ask your administrator to enable it.');
        }
    }

    /** Gate on `curriculum.manage`; admins bypass. */
    private function authorizeCurriculum(Request $request): void
    {
        $user = $request->user();
        if ($user && $user->role === 'admin') {
            return;
        }
        if (! $user || $user->role !== 'teacher') {
            abort(403, 'This action requires a teacher account.');
        }
        if (! Capabilities::has($user->capabilities, 'curriculum.manage')) {
            abort(403, 'This feature ("curriculum.manage") is disabled for your account. Ask your administrator to enable it.');
        }
    }

    /** Bank owner = first admin (centralised DB). Falls back to the caller. */
    private function bankOwner(User $user): array
    {
        $admin = User::where('role', 'admin')->orderBy('created_at')->first(['id', 'full_name']);

        return [$admin?->id ?? $user->id, $admin?->full_name ?? $user->full_name];
    }
}
