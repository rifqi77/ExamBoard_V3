<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\BankQuestion;
use App\Models\LearningObjective;
use App\Models\User;
use App\Support\Subjects;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Teacher Question Bank — port of the original Next.js
 * /teacher/bank page + /api/teacher/bank{,/options,/[id]} routes.
 *
 * Visibility model (mirrors the original):
 *   - teacher: WHERE uploaded_by = self  (only questions THEY contributed)
 *   - admin:   no uploaded_by filter      (the whole admin database)
 *
 * The bank is "admin-owned": every row's created_by points at the first
 * admin (the centralised database), while uploaded_by tracks the actual
 * contributor so the uploader keeps read/edit/delete rights on their own
 * rows. Subjects are canonicalised via Subjects::canonical at every write
 * so the tree never accumulates "Physics" / "fisika" drift.
 */
class BankController extends Controller
{
    // Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
    private const DIFFICULTIES = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'];

    private const TYPES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    private const AUDIO_EXTS = ['mp3', 'wav', 'ogg', 'm4a', 'aac'];

    private const VIDEO_EXTS = ['mp4', 'webm', 'mov', 'ogv'];

    private const MIME_BY_EXT = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'ogv' => 'video/ogg',
    ];

    /**
     * GET /teacher/bank
     * Renders the bank page: the filtered question list (subject→topic→
     * subtopic→difficulty→media tree on the client), the 6 filter
     * dropdown option sets, and the curriculum topic order.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        // --- Read + sanitise the 6 filters from query params ---
        $f = [
            'language' => $this->trimOrNull($request->query('language')),
            'subject' => $this->trimOrNull($request->query('subject')),
            'topic' => $this->trimOrNull($request->query('topic')),
            'subtopic' => $this->trimOrNull($request->query('subtopic')),
            'difficulty' => $this->oneOf($request->query('difficulty'), self::DIFFICULTIES),
            'type' => $this->oneOf($request->query('type'), self::TYPES),
        ];
        $search = $this->trimOrNull($request->query('search'));

        $base = BankQuestion::query()
            ->when(! $isAdmin, fn ($q) => $q->where('uploaded_by', $user->id));

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

        $myUid = $user->id;
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
            // Server-side flag: can the current user edit/delete this row?
            'canManage' => $isAdmin || ($q->uploaded_by !== null && $q->uploaded_by === $myUid),
        ])->values();

        return Inertia::render('teacher/Bank', [
            'questions' => $questions,
            'topicOrder' => $this->topicOrder($user, $isAdmin),
            'filterOptions' => $this->buildFilterOptions($base),
            'filters' => array_filter([
                ...$f,
                'search' => $search,
            ], fn ($v) => $v !== null && $v !== ''),
            'isAdmin' => $isAdmin,
            'lockedSubject' => $isAdmin ? null : ($user->subject ?: null),
            'subjectChoices' => Subjects::mergeWithExisting(
                BankQuestion::query()->whereNotNull('subject')->distinct()->pluck('subject')->all()
            ),
        ]);
    }

    /**
     * POST /teacher/bank
     * Create a single bank question from the inline create form.
     * Owner = admin database; uploaded_by = the signed-in user.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validateQuestion($request, isCreate: true);

        [$ownerId, $ownerName] = $this->bankOwner($user);

        $normalised = $this->normaliseForType(
            $data['type'],
            $data['options'] ?? null,
            $data['correctAnswer'] ?? null
        );

        BankQuestion::create([
            'type' => $data['type'],
            'language' => ($data['language'] ?? '') !== '' ? trim($data['language']) : 'English',
            'subject' => Subjects::canonical($data['subject'] ?? '') ?: 'General',
            'topic' => trim($data['topic']) !== '' ? trim($data['topic']) : 'General',
            'subtopic' => isset($data['subtopic']) && trim((string) $data['subtopic']) !== '' ? trim($data['subtopic']) : null,
            'difficulty' => $data['difficulty'] ?? 'understand',
            'tags' => [],
            'prompt' => trim($data['prompt']),
            'options' => $normalised['options'],
            'points' => $data['points'] ?? 1,
            'correct_answer' => $normalised['correct'],
            'explanation_text' => trim((string) ($data['explanationText'] ?? '')),
            'created_by' => $ownerId,
            'created_by_name' => $ownerName,
            'uploaded_by' => $user->id,
            'uploaded_by_name' => $user->full_name,
            'source_file_name' => null,
            'media_url' => null,
            'media_type' => null,
        ]);

        return back()->with('success', 'Question added to the bank.');
    }

    /**
     * PUT /teacher/bank/{id}
     * In-place update. Same permission as destroy: admin edits any row,
     * teacher edits only their own uploads. type + media are immutable
     * (changing type would invalidate the answer key).
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $target = BankQuestion::find($id);
        if (! $target) {
            return back()->with('error', 'Bank question not found.');
        }
        if (! $this->canManage($user, $target)) {
            return back()->with('error', 'This bank question is in the admin database and you didn\'t upload it. Only the original uploader or admin can edit it.');
        }

        $data = $this->validateQuestion($request, isCreate: false, type: $target->type);

        $normalised = $this->normaliseForType(
            $target->type,
            array_key_exists('options', $data) ? $data['options'] : ($target->options ?? null),
            array_key_exists('correctAnswer', $data) ? $data['correctAnswer'] : $target->correct_answer
        );

        $target->update([
            'prompt' => trim($data['prompt']),
            'explanation_text' => trim((string) ($data['explanationText'] ?? '')),
            'points' => $data['points'] ?? $target->points,
            'topic' => trim($data['topic']),
            'subtopic' => isset($data['subtopic']) && trim((string) $data['subtopic']) !== '' ? trim($data['subtopic']) : null,
            'difficulty' => $data['difficulty'] ?? $target->difficulty,
            'language' => ($data['language'] ?? '') !== '' ? trim($data['language']) : null,
            'subject' => Subjects::canonical($data['subject'] ?? '') ?: null,
            'options' => $normalised['options'],
            'correct_answer' => $normalised['correct'],
        ]);

        return back()->with('success', 'Saved.');
    }

    /**
     * DELETE /teacher/bank/{id}
     * Admin deletes any row; teacher deletes only their own uploads.
     * Does NOT cascade to exam questions — each exam keeps its own copy.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $target = BankQuestion::find($id);
        if (! $target) {
            return back()->with('error', 'Bank question not found.');
        }
        if (! $this->canManage($user, $target)) {
            return back()->with('error', 'This bank question belongs to the admin database. Only the original uploader or admin can delete it.');
        }

        $target->delete();

        return back()->with('success', 'Bank question deleted.');
    }

    /**
     * POST /teacher/bank/upload
     * Bulk import. Accepts EITHER:
     *   - a .zip containing questions.json (+ optional media folder), or
     *   - a .zip containing a Questions .xlsx (+ optional media folder), or
     *   - a standalone .xlsx (Questions sheet, no media).
     * Media is matched by basename and stored inline as a base64 data URL
     * in media_url (mirrors the original client-side import).
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200'], // 50 MB
        ]);

        $user = $request->user();
        $upload = $request->file('file');
        $originalName = $upload->getClientOriginalName();
        $ext = strtolower($upload->getClientOriginalExtension());

        try {
            if ($ext === 'zip') {
                [$parsedQuestions, $media, $warnings] = $this->parseZip($upload->getRealPath());
            } elseif (in_array($ext, ['xlsx', 'xls'], true)) {
                $parsedQuestions = $this->parseQuestionsSpreadsheet($upload->getRealPath());
                $media = [];
                $warnings = [];
            } else {
                throw ValidationException::withMessages([
                    'file' => 'Upload a .zip (questions.json or Questions.xlsx + media) or a .xlsx file.',
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not read the upload: '.$e->getMessage());
        }

        if (count($parsedQuestions) === 0) {
            return back()->with('error', 'No questions found in the upload.');
        }

        [$ownerId, $ownerName] = $this->bankOwner($user);

        $mediaByFile = [];
        foreach ($media as $m) {
            $mediaByFile[$m['fileName']] = $m;
        }
        $usedMedia = [];
        $added = 0;

        $rows = [];
        foreach ($parsedQuestions as $q) {
            $prompt = trim((string) ($q['prompt'] ?? ''));
            if (mb_strlen($prompt) < 2) {
                $warnings[] = 'Question with empty prompt skipped.';

                continue;
            }
            $points = is_numeric($q['points'] ?? null) ? (float) $q['points'] : 1.0;
            if ($points <= 0 || $points > 100) {
                $warnings[] = 'Question "'.mb_substr($prompt, 0, 30).'…": invalid points, skipped.';

                continue;
            }
            if (! in_array($q['type'] ?? '', self::TYPES, true)) {
                $warnings[] = 'Question "'.mb_substr($prompt, 0, 30).'…": invalid type, skipped.';

                continue;
            }

            $mediaFile = isset($q['mediaFile']) && trim((string) $q['mediaFile']) !== '' ? trim((string) $q['mediaFile']) : null;
            $mediaEntry = $mediaFile !== null ? ($mediaByFile[$mediaFile] ?? null) : null;
            if ($mediaFile !== null && $mediaEntry === null) {
                $warnings[] = 'Media "'.$mediaFile.'" not found; question added without media.';
            }
            if ($mediaEntry !== null && $mediaFile !== null) {
                $usedMedia[$mediaFile] = true;
            }

            $difficulty = in_array($q['difficulty'] ?? '', self::DIFFICULTIES, true) ? $q['difficulty'] : 'understand';
            $normalised = $this->normaliseForType($q['type'], $q['options'] ?? null, $q['correctAnswer'] ?? null);

            $rows[] = [
                'type' => $q['type'],
                'language' => trim((string) ($q['language'] ?? '')) !== '' ? trim((string) $q['language']) : 'English',
                'subject' => Subjects::canonical($q['subject'] ?? '') ?: 'General',
                'topic' => trim((string) ($q['topic'] ?? '')) !== '' ? trim((string) $q['topic']) : 'General',
                'subtopic' => isset($q['subtopic']) && trim((string) $q['subtopic']) !== '' ? trim((string) $q['subtopic']) : null,
                'difficulty' => $difficulty,
                'tags' => [],
                'prompt' => $prompt,
                'options' => $normalised['options'],
                'points' => $points,
                'correct_answer' => $normalised['correct'],
                'explanation_text' => trim((string) ($q['explanationText'] ?? '')),
                'created_by' => $ownerId,
                'created_by_name' => $ownerName,
                'uploaded_by' => $user->id,
                'uploaded_by_name' => $user->full_name,
                'source_file_name' => $originalName,
                'media_url' => $mediaEntry['dataUrl'] ?? null,
                'media_type' => $mediaEntry['type'] ?? null,
            ];
        }

        foreach (array_keys($mediaByFile) as $fileName) {
            if (! isset($usedMedia[$fileName])) {
                $warnings[] = 'Media "'.$fileName.'" wasn\'t referenced by any question.';
            }
        }

        try {
            DB::transaction(function () use ($rows, &$added) {
                foreach ($rows as $row) {
                    BankQuestion::create($row);
                    $added++;
                }
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Import failed: '.$e->getMessage());
        }

        $msg = "Added {$added} question(s) to the bank.";
        if (count($warnings) > 0) {
            $msg .= ' '.count($warnings).' warning(s) — first: '.$warnings[0];
        }

        return back()
            ->with('success', $msg)
            ->with('importResult', [
                'added' => $added,
                'fileName' => $originalName,
                'warnings' => array_values($warnings),
            ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Distinct filter option sets, scoped to the current user's visible rows. */
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
            // difficulties + types follow the canonical pedagogical order
            'difficulties' => array_values(array_filter(self::DIFFICULTIES, fn ($d) => isset($difficulties[$d]))),
            'types' => array_values(array_filter(self::TYPES, fn ($t) => isset($types[$t]))),
        ];
    }

    /**
     * Curriculum-aligned topic order from learning_objectives.sort_order.
     * First-seen wins on duplicate topic names. Teacher sees their own LOs;
     * admin sees all.
     */
    private function topicOrder(User $user, bool $isAdmin): array
    {
        $loRows = LearningObjective::query()
            ->when(! $isAdmin, fn ($q) => $q->where('uploaded_by', $user->id))
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

    /** Bank owner = first admin (the centralised database). Falls back to the uploader. */
    private function bankOwner(User $user): array
    {
        $admin = User::where('role', 'admin')->orderBy('created_at')->first(['id', 'full_name']);

        return [$admin?->id ?? $user->id, $admin?->full_name ?? $user->full_name];
    }

    private function canManage(User $user, BankQuestion $q): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $q->uploaded_by !== null && $q->uploaded_by === $user->id;
    }

    /**
     * Validate the create/edit form payload. On create, type is required
     * and validated; on update, type is immutable (passed in).
     *
     * @return array<string,mixed>
     */
    private function validateQuestion(Request $request, bool $isCreate, ?string $type = null): array
    {
        $rules = [
            'prompt' => ['required', 'string', 'min:2'],
            'explanationText' => ['nullable', 'string'],
            'points' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'topic' => ['required', 'string', 'min:1'],
            'subtopic' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'in:'.implode(',', self::DIFFICULTIES)],
            'language' => ['nullable', 'string'],
            'subject' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
            'options.*.id' => ['required_with:options', 'string'],
            'options.*.text' => ['required_with:options', 'string'],
            'correctAnswer' => ['nullable'],
        ];
        if ($isCreate) {
            $rules['type'] = ['required', 'in:'.implode(',', self::TYPES)];
        }

        $data = $request->validate($rules);

        $effectiveType = $isCreate ? $data['type'] : $type;
        if (in_array($effectiveType, ['single_choice', 'multi_select'], true)) {
            $opts = $data['options'] ?? [];
            $filled = array_filter($opts, fn ($o) => trim((string) ($o['text'] ?? '')) !== '');
            if (count($filled) < 2) {
                throw ValidationException::withMessages([
                    'options' => 'Choice questions need at least 2 options.',
                ]);
            }
        }

        return $data;
    }

    /**
     * Normalise options + correctAnswer to the canonical shape per type.
     * Returns ['options' => array|null, 'correct' => mixed].
     */
    private function normaliseForType(string $type, $options, $correct): array
    {
        // Options only meaningful for choice types.
        $normOptions = null;
        if (in_array($type, ['single_choice', 'multi_select'], true) && is_array($options)) {
            $normOptions = [];
            foreach ($options as $o) {
                $oid = strtoupper(trim((string) ($o['id'] ?? '')));
                $text = trim((string) ($o['text'] ?? ''));
                if ($oid === '' || $text === '') {
                    continue;
                }
                $normOptions[] = ['id' => $oid, 'text' => $text];
            }
        }

        $normCorrect = $this->normaliseCorrect($type, $correct);

        return ['options' => $normOptions, 'correct' => $normCorrect];
    }

    private function normaliseCorrect(string $type, $raw)
    {
        if ($type === 'single_choice') {
            return strtoupper(trim((string) ($raw ?? '')));
        }
        if ($type === 'multi_select') {
            if (! is_array($raw)) {
                return [];
            }
            $vals = array_values(array_unique(array_map(
                fn ($v) => strtoupper(trim((string) $v)),
                $raw
            )));
            sort($vals);

            return $vals;
        }
        if ($type === 'short_text') {
            return trim((string) ($raw ?? ''));
        }
        if ($type === 'numeric') {
            return is_numeric($raw) ? (float) $raw : (float) (is_string($raw) ? (float) $raw : 0);
        }

        // essay — no key
        return null;
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

    // ------------------------------------------------------------------
    // Zip + spreadsheet parsing (PHP ports of zip-parser.ts / excel-parser.ts)
    // ------------------------------------------------------------------

    /**
     * Parse a bank .zip. Prefers a questions.json at the root (matching the
     * original parseBankZipJson); if no .json is present, falls back to a
     * Questions .xlsx inside the zip. Media files (any folder) are matched
     * by basename and returned as base64 data URLs.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>, 2: array<int,string>}
     */
    private function parseZip(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The file is not a readable .zip archive.');
        }

        try {
            // Locate the JSON entry (prefer one at the root) and the xlsx entry.
            $jsonName = null;
            $xlsxName = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }
                $lower = strtolower($name);
                if (str_ends_with($lower, '.json')) {
                    if ($jsonName === null || ! str_contains($name, '/')) {
                        $jsonName = $name;
                    }
                } elseif (str_ends_with($lower, '.xlsx') || str_ends_with($lower, '.xls')) {
                    if ($xlsxName === null || ! str_contains($name, '/')) {
                        $xlsxName = $name;
                    }
                }
            }

            $media = $this->readMediaFromZip($zip);

            if ($jsonName !== null) {
                $jsonText = $zip->getFromName($jsonName);
                if ($jsonText === false) {
                    throw new \RuntimeException('Could not read the JSON entry in the zip.');
                }
                $questions = $this->parseBankQuestionsJson($jsonText);

                return [$questions, $media, []];
            }

            if ($xlsxName !== null) {
                // Extract the xlsx to a temp file for PhpSpreadsheet.
                $tmp = tempnam(sys_get_temp_dir(), 'bankxlsx_').'.xlsx';
                $bytes = $zip->getFromName($xlsxName);
                if ($bytes === false) {
                    throw new \RuntimeException('Could not read the .xlsx entry in the zip.');
                }
                file_put_contents($tmp, $bytes);
                try {
                    $questions = $this->parseQuestionsSpreadsheet($tmp);
                } finally {
                    @unlink($tmp);
                }

                return [$questions, $media, []];
            }

            throw new \RuntimeException('No questions.json or Questions.xlsx found in the zip.');
        } finally {
            $zip->close();
        }
    }

    /**
     * Read every media file from the zip, keyed by basename (deduped,
     * first-seen wins). Returns [['fileName','type','dataUrl'], ...].
     *
     * @return array<int,array<string,string>>
     */
    private function readMediaFromZip(\ZipArchive $zip): array
    {
        $media = [];
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || str_ends_with($name, '/')) {
                continue;
            }
            $basename = basename($name);
            if ($basename === '') {
                continue;
            }
            $type = $this->detectMediaType($basename);
            if ($type === null) {
                continue;
            }
            $key = strtolower($basename);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $bytes = $zip->getFromName($name);
            if ($bytes === false) {
                continue;
            }
            $mime = $this->mimeFromExtension($basename);
            $dataUrl = 'data:'.$mime.';base64,'.base64_encode($bytes);
            $media[] = ['fileName' => $basename, 'type' => $type, 'dataUrl' => $dataUrl];
        }

        return $media;
    }

    /**
     * Port of parseBankQuestionFromJson — validates each question object.
     * Root may be { questions: [...] } or a bare array.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseBankQuestionsJson(string $jsonText): array
    {
        $payload = json_decode($jsonText, true);
        if (! is_array($payload)) {
            throw new \RuntimeException('The JSON file is not valid JSON.');
        }
        $rawQuestions = null;
        if (isset($payload['questions']) && is_array($payload['questions'])) {
            $rawQuestions = $payload['questions'];
        } elseif (array_is_list($payload)) {
            $rawQuestions = $payload;
        }
        if (! is_array($rawQuestions) || count($rawQuestions) === 0) {
            throw new \RuntimeException("'questions' must be a non-empty array.");
        }

        $out = [];
        foreach ($rawQuestions as $index => $raw) {
            $out[] = $this->parseOneBankQuestionJson($raw, $index);
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseOneBankQuestionJson($raw, int $index): array
    {
        if (! is_array($raw)) {
            throw new \RuntimeException("questions[{$index}] must be an object.");
        }

        $type = is_string($raw['type'] ?? null) ? strtolower($raw['type']) : '';
        if (! in_array($type, self::TYPES, true)) {
            throw new \RuntimeException("questions[{$index}].type must be one of ".implode(', ', self::TYPES).'.');
        }

        $prompt = is_string($raw['prompt'] ?? null) ? trim($raw['prompt']) : '';
        if ($prompt === '') {
            throw new \RuntimeException("questions[{$index}].prompt is required.");
        }

        $explanation = '';
        if (is_string($raw['explanation'] ?? null)) {
            $explanation = trim($raw['explanation']);
        } elseif (is_string($raw['explanationText'] ?? null)) {
            $explanation = trim($raw['explanationText']);
        }
        if ($explanation === '') {
            throw new \RuntimeException("questions[{$index}].explanation is required.");
        }

        $language = (is_string($raw['language'] ?? null) && trim($raw['language']) !== '') ? trim($raw['language']) : 'English';
        $subject = (is_string($raw['subject'] ?? null) && trim($raw['subject']) !== '') ? trim($raw['subject']) : 'General';
        $topic = (is_string($raw['topic'] ?? null) && trim($raw['topic']) !== '') ? trim($raw['topic']) : 'General';
        $subtopic = (is_string($raw['subtopic'] ?? null) && trim($raw['subtopic']) !== '') ? trim($raw['subtopic']) : null;
        $difficulty = $this->parseDifficulty($raw['difficulty'] ?? null);
        $points = (is_numeric($raw['points'] ?? null) && $raw['points'] > 0) ? (float) $raw['points'] : 1.0;
        $mediaFile = (is_string($raw['mediaFile'] ?? null) && trim($raw['mediaFile']) !== '') ? trim($raw['mediaFile']) : null;

        $options = null;
        $correct = null;

        if ($type === 'single_choice' || $type === 'multi_select') {
            if (! is_array($raw['options'] ?? null) || count($raw['options']) < 2) {
                throw new \RuntimeException("questions[{$index}]: choice questions need at least 2 options.");
            }
            $options = [];
            foreach ($raw['options'] as $i => $opt) {
                if (! is_array($opt)) {
                    throw new \RuntimeException("questions[{$index}].options[{$i}] must be an object.");
                }
                $oid = is_string($opt['id'] ?? null) ? strtoupper(trim($opt['id'])) : '';
                $text = is_string($opt['text'] ?? null) ? trim($opt['text']) : '';
                if ($oid === '' || $text === '') {
                    throw new \RuntimeException("questions[{$index}].options[{$i}] needs id and text.");
                }
                $options[] = ['id' => $oid, 'text' => $text];
            }
            if ($type === 'single_choice') {
                if (! is_string($raw['correctAnswer'] ?? null) || trim($raw['correctAnswer']) === '') {
                    throw new \RuntimeException("questions[{$index}].correctAnswer must be a letter.");
                }
                $correct = strtoupper(trim($raw['correctAnswer']));
            } else {
                if (! is_array($raw['correctAnswer'] ?? null) || count($raw['correctAnswer']) === 0) {
                    throw new \RuntimeException("questions[{$index}].correctAnswer must be a non-empty array for multi_select.");
                }
                $correct = array_map(fn ($v) => strtoupper(trim((string) $v)), $raw['correctAnswer']);
                sort($correct);
            }
        } elseif ($type === 'short_text') {
            if (! is_string($raw['correctAnswer'] ?? null) || trim($raw['correctAnswer']) === '') {
                throw new \RuntimeException("questions[{$index}].correctAnswer must be a string.");
            }
            $correct = trim($raw['correctAnswer']);
        } elseif ($type === 'numeric') {
            if (! is_numeric($raw['correctAnswer'] ?? null)) {
                throw new \RuntimeException("questions[{$index}].correctAnswer must be a number.");
            }
            $correct = (float) $raw['correctAnswer'];
        } else {
            $correct = '';
        }

        return [
            'type' => $type,
            'language' => $language,
            'subject' => $subject,
            'topic' => $topic,
            'subtopic' => $subtopic,
            'difficulty' => $difficulty,
            'points' => $points,
            'prompt' => $prompt,
            'options' => $options,
            'correctAnswer' => $correct,
            'explanationText' => $explanation,
            'mediaFile' => $mediaFile,
        ];
    }

    /**
     * Port of readQuestionsSheet — reads the "Questions" sheet of an xlsx.
     * Header matching is case-insensitive with the same candidate names as
     * the original (Type, Prompt/Question, Correct Answer/Answer, Explanation,
     * Option A–D, Topic, Points, Subject, Subtopic, Language, Difficulty,
     * Media File).
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseQuestionsSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Questions')
            ?? $spreadsheet->getSheetByName('questions')
            ?? $spreadsheet->getActiveSheet();
        if ($sheet === null) {
            throw new \RuntimeException("Missing 'Questions' sheet in the xlsx file.");
        }

        $rowsRaw = $sheet->toArray(null, true, false, false); // 0-indexed rows/cols, formatted values
        if (count($rowsRaw) === 0) {
            throw new \RuntimeException('The Questions sheet is empty.');
        }

        // Build header => column-index map from the first row.
        $headers = [];
        foreach ($rowsRaw[0] as $colIdx => $cell) {
            $key = strtolower(trim((string) ($cell ?? '')));
            if ($key !== '') {
                $headers[$key] = $colIdx;
            }
        }
        $col = function (array $candidates) use ($headers): ?int {
            foreach ($candidates as $c) {
                if (array_key_exists($c, $headers)) {
                    return $headers[$c];
                }
            }

            return null;
        };

        $colPosition = $col(['position', '#', 'no']);
        $colType = $col(['type']);
        $colTopic = $col(['topic']);
        $colSubject = $col(['subject']);
        $colSubtopic = $col(['subtopic', 'sub topic']);
        $colLanguage = $col(['language']);
        $colDifficulty = $col(['difficulty']);
        $colPoints = $col(['points']);
        $colPrompt = $col(['prompt', 'question']);
        $colA = $col(['option a', 'a']);
        $colB = $col(['option b', 'b']);
        $colC = $col(['option c', 'c']);
        $colD = $col(['option d', 'd']);
        $colCorrect = $col(['correct answer', 'correct', 'answer']);
        $colExplanation = $col(['explanation']);
        $colMedia = $col(['media file', 'media']);

        if ($colType === null || $colPrompt === null || $colCorrect === null || $colExplanation === null) {
            throw new \RuntimeException('Questions header row must include at least: Type, Prompt, Correct Answer, Explanation.');
        }

        $cell = function (array $row, ?int $idx): string {
            if ($idx === null) {
                return '';
            }

            return trim((string) ($row[$idx] ?? ''));
        };

        $questions = [];
        foreach ($rowsRaw as $rowNumber => $row) {
            if ($rowNumber === 0) {
                continue; // header
            }
            $prompt = $cell($row, $colPrompt);
            if ($prompt === '') {
                continue;
            }
            $human = $rowNumber + 1; // 1-based for messages (header = row 1)
            $typeText = strtolower($cell($row, $colType));
            if (! in_array($typeText, self::TYPES, true)) {
                throw new \RuntimeException("Row {$human}: unknown type \"{$typeText}\". Use one of ".implode(', ', self::TYPES).'.');
            }
            $type = $typeText;

            $topic = $colTopic !== null ? ($cell($row, $colTopic) ?: 'General') : 'General';
            $subject = $colSubject !== null ? ($cell($row, $colSubject) ?: 'General') : 'General';
            $subtopic = $colSubtopic !== null && $cell($row, $colSubtopic) !== '' ? $cell($row, $colSubtopic) : null;
            $language = $colLanguage !== null && $cell($row, $colLanguage) !== '' ? $cell($row, $colLanguage) : 'English';
            $difficulty = $colDifficulty !== null ? $this->parseDifficulty($cell($row, $colDifficulty)) : 'understand';
            $points = $colPoints !== null && is_numeric($cell($row, $colPoints)) ? (float) $cell($row, $colPoints) : 1.0;
            $explanation = $cell($row, $colExplanation);
            $mediaFile = $colMedia !== null && $cell($row, $colMedia) !== '' ? $cell($row, $colMedia) : null;

            $options = null;
            $correct = null;
            if ($type === 'single_choice' || $type === 'multi_select') {
                $texts = [
                    $cell($row, $colA),
                    $cell($row, $colB),
                    $cell($row, $colC),
                    $cell($row, $colD),
                ];
                $filled = [];
                foreach ($texts as $i => $text) {
                    if ($text !== '') {
                        $filled[] = ['id' => chr(ord('A') + $i), 'text' => $text];
                    }
                }
                if (count($filled) < 2) {
                    throw new \RuntimeException("Row {$human}: choice questions need at least 2 options.");
                }
                $options = $filled;
                $correctRaw = strtoupper($cell($row, $colCorrect));
                if ($correctRaw === '') {
                    throw new \RuntimeException("Row {$human}: Correct Answer is required.");
                }
                if ($type === 'single_choice') {
                    $correct = $correctRaw;
                } else {
                    $correct = array_values(array_filter(
                        array_map('trim', preg_split('/[,\s]+/', $correctRaw) ?: []),
                        fn ($s) => $s !== ''
                    ));
                }
            } elseif ($type === 'short_text') {
                $text = $cell($row, $colCorrect);
                if ($text === '') {
                    throw new \RuntimeException("Row {$human}: Correct Answer is required.");
                }
                $correct = $text;
            } elseif ($type === 'numeric') {
                $numStr = $cell($row, $colCorrect);
                if ($numStr === '') {
                    throw new \RuntimeException("Row {$human}: Correct Answer is required.");
                }
                if (! is_numeric($numStr)) {
                    throw new \RuntimeException("Row {$human}: Correct Answer must be a number.");
                }
                $correct = (float) $numStr;
            } else {
                $correct = '';
            }

            if ($explanation === '') {
                throw new \RuntimeException("Row {$human}: Explanation is required.");
            }

            $questions[] = [
                'type' => $type,
                'language' => $language,
                'subject' => $subject,
                'topic' => $topic,
                'subtopic' => $subtopic,
                'difficulty' => $difficulty,
                'points' => $points,
                'prompt' => $prompt,
                'options' => $options,
                'correctAnswer' => $correct,
                'explanationText' => $explanation,
                'mediaFile' => $mediaFile,
                'position' => $colPosition !== null && is_numeric($cell($row, $colPosition)) ? (int) $cell($row, $colPosition) : $rowNumber,
            ];
        }

        return $questions;
    }

    /**
     * Parse a difficulty value from an Excel cell. Accepts the new Bloom's
     * vocabulary (remember/understand/apply/analyze/evaluate/create) AND the
     * legacy easy/medium/hard/hots labels so old roster files still import
     * cleanly (mapped to the closest Bloom's equivalent).
     */
    private function parseDifficulty($value): string
    {
        if (! is_string($value)) {
            return 'understand';
        }
        $lower = preg_replace('/[\s_-]+/', '', strtolower(trim($value)));

        // Bloom's revised taxonomy — preferred.
        if ($lower === 'remember' || $lower === 'recall' || $lower === 'know') return 'remember';
        if ($lower === 'understand' || $lower === 'comprehend' || $lower === 'explain') return 'understand';
        if ($lower === 'apply' || $lower === 'use' || $lower === 'implement') return 'apply';
        if ($lower === 'analyze' || $lower === 'analyse' || $lower === 'decompose' || $lower === 'compare') return 'analyze';
        if ($lower === 'evaluate' || $lower === 'judge' || $lower === 'critique' || $lower === 'justify') return 'evaluate';
        if ($lower === 'create' || $lower === 'design' || $lower === 'construct' || $lower === 'produce') return 'create';
        if ($lower === 'olympiad' || $lower === 'olympic' || $lower === 'competition') return 'olympiad';

        // Legacy easy/medium/hard/hots → Bloom's equivalent (same mapping the
        // migration applied to existing rows).
        if ($lower === 'easy' || $lower === 'e') return 'remember';
        if ($lower === 'medium' || $lower === 'm') return 'understand';
        if ($lower === 'hard' || $lower === 'h' || $lower === 'difficult') return 'analyze';
        if ($lower === 'hots' || $lower === 'higherorderthinkingskills') return 'evaluate';

        return 'understand';
    }

    private function detectMediaType(string $fileName): ?string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($ext, self::IMAGE_EXTS, true)) {
            return 'image';
        }
        if (in_array($ext, self::AUDIO_EXTS, true)) {
            return 'audio';
        }
        if (in_array($ext, self::VIDEO_EXTS, true)) {
            return 'video';
        }

        return null;
    }

    private function mimeFromExtension(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return self::MIME_BY_EXT[$ext] ?? 'application/octet-stream';
    }
}
