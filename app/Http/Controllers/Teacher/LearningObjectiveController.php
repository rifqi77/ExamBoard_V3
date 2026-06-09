<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\LearningObjective;
use App\Models\User;
use App\Support\Capabilities;
use App\Support\Subjects;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/**
 * Teacher curriculum (Learning Objectives) — port of the original
 * /api/teacher/learning-objectives routes + excel-lo-parser.ts.
 *
 * Four-curriculum LO manager (kurikulum_merdeka / as_a_level / ib /
 * olympiad). Gated by the `curriculum.manage` capability; admins bypass
 * and see every uploaded LO, teachers see only what they uploaded
 * (uploadedBy = self), mirroring the bank visibility rule.
 */
class LearningObjectiveController extends Controller
{
    private const VALID_CURRICULA = ['kurikulum_merdeka', 'as_a_level', 'ib', 'olympiad'];

    private const TOPIC_ALIASES = ['topic', 'topik', 'bab', 'chapter', 'materi'];

    private const SUBTOPIC_ALIASES = [
        'subtopic', 'subtopik', 'sub topik', 'sub-topik', 'sub topic',
        'subbab', 'sub bab', 'sub-bab', 'subchapter', 'subbab/topik',
    ];

    private const LO_ALIASES = [
        'learning objective', 'learning objectives', 'objective', 'objectives',
        'tujuan pembelajaran', 'tujuan', 'indikator', 'indikator pembelajaran',
        'capaian pembelajaran', 'kompetensi dasar', 'kd', 'lo',
    ];

    /** GET /teacher/learning-objectives — full curriculum catalog. */
    public function index(Request $request)
    {
        $this->authorizeCurriculum($request);
        $user = $request->user();

        $rows = LearningObjective::query()
            ->when($user->role === 'teacher', fn ($q) => $q->where('uploaded_by', $user->id))
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

        // Distinct subjects already in the catalog, merged with the canonical
        // list, so the inline "Subject" picker offers custom values too.
        $existingSubjects = $rows->pluck('subject')->filter()->unique()->values()->all();

        return Inertia::render('teacher/LearningObjectives', [
            'learningObjectives' => $learningObjectives,
            'subjectChoices' => Subjects::mergeWithExisting($existingSubjects),
            'accountSubject' => $user->subject,
            'isAdmin' => $user->role === 'admin',
            // Surfaced after a phase-1 Excel parse (upload() flashes it) so the
            // page can render the import preview before the teacher commits.
            // HandleInertiaRequests only shares flash.success / flash.error, so
            // we pass the parsed payload through as an explicit page prop here.
            'preview' => $request->session()->get('lo_preview'),
        ]);
    }

    /**
     * POST /teacher/learning-objectives — add a single LO inline.
     * (Inline add complements the Excel import; same ownership rules.)
     */
    public function store(Request $request)
    {
        $this->authorizeCurriculum($request);
        $user = $request->user();

        $data = $request->validate([
            'curriculum' => ['required', 'string'],
            'language' => ['nullable', 'string'],
            'subject' => ['required', 'string'],
            'topic' => ['required', 'string'],
            'subtopic' => ['nullable', 'string'],
            'text' => ['required', 'string'],
        ]);

        $curriculum = $this->parseCurriculum($data['curriculum']);
        if (! $curriculum) {
            return back()->with('error', 'Invalid curriculum. Must be one of: '.implode(', ', self::VALID_CURRICULA).'.');
        }
        $subject = Subjects::canonical($data['subject']);
        if ($subject === '') {
            return back()->with('error', 'Subject is required.');
        }
        $topic = trim($data['topic']);
        $text = trim($data['text']);
        if ($topic === '' || mb_strlen($text) < 3) {
            return back()->with('error', 'Topic and a learning objective (3+ chars) are required.');
        }
        $subtopic = isset($data['subtopic']) && trim($data['subtopic']) !== '' ? trim($data['subtopic']) : null;
        $language = isset($data['language']) && trim($data['language']) !== '' ? trim($data['language']) : 'English';

        [$ownerId, $ownerName] = $this->bankOwner($user);
        $startOrder = (int) (LearningObjective::query()
            ->where('curriculum', $curriculum)
            ->where('subject', $subject)
            ->max('sort_order') ?? -1) + 1;

        LearningObjective::create([
            'curriculum' => $curriculum,
            'language' => $language,
            'subject' => $subject,
            'topic' => $topic,
            'subtopic' => $subtopic,
            'text' => $text,
            'sort_order' => $startOrder,
            'created_by' => $ownerId,
            'created_by_name' => $ownerName,
            'uploaded_by' => $user->id,
            'uploaded_by_name' => $user->full_name,
            'source_file_name' => null,
        ]);

        return back()->with('success', 'Learning objective added.');
    }

    /** PATCH /teacher/learning-objectives/{id} — edit one LO inline. */
    public function update(Request $request, string $id)
    {
        $this->authorizeCurriculum($request);
        $user = $request->user();

        $row = LearningObjective::find($id);
        if (! $row) {
            return back()->with('error', 'Not found.');
        }
        if ($user->role !== 'admin' && $row->uploaded_by !== $user->id) {
            return back()->with('error', 'You can only edit learning objectives you uploaded yourself.');
        }

        $data = $request->validate([
            'topic' => ['sometimes', 'string'],
            'subtopic' => ['sometimes', 'nullable', 'string'],
            'text' => ['sometimes', 'string'],
            'subject' => ['sometimes', 'string'],
            'language' => ['sometimes', 'string'],
        ]);

        if (array_key_exists('topic', $data)) {
            $topic = trim((string) $data['topic']);
            if ($topic === '') {
                return back()->with('error', 'Topic cannot be empty.');
            }
            $row->topic = $topic;
        }
        if (array_key_exists('subtopic', $data)) {
            $sub = $data['subtopic'] !== null ? trim((string) $data['subtopic']) : '';
            $row->subtopic = $sub !== '' ? $sub : null;
        }
        if (array_key_exists('text', $data)) {
            $text = trim((string) $data['text']);
            if (mb_strlen($text) < 3) {
                return back()->with('error', 'Learning objective text must be at least 3 characters.');
            }
            $row->text = $text;
        }
        if (array_key_exists('subject', $data)) {
            $subject = Subjects::canonical((string) $data['subject']);
            if ($subject === '') {
                return back()->with('error', 'Subject cannot be empty.');
            }
            $row->subject = $subject;
        }
        if (array_key_exists('language', $data) && trim((string) $data['language']) !== '') {
            $row->language = trim((string) $data['language']);
        }

        $row->save();

        return back()->with('success', 'Learning objective updated.');
    }

    /** DELETE /teacher/learning-objectives/{id} — delete one LO. */
    public function destroy(Request $request, string $id)
    {
        $this->authorizeCurriculum($request);
        $user = $request->user();

        $row = LearningObjective::find($id);
        if (! $row) {
            return back()->with('error', 'Not found.');
        }
        if ($user->role !== 'admin' && $row->uploaded_by !== $user->id) {
            return back()->with('error', 'You can only delete learning objectives you uploaded yourself.');
        }
        $row->delete();

        return back()->with('success', 'Learning objective deleted.');
    }

    /**
     * POST /teacher/learning-objectives/bulk-delete — delete many LOs.
     * Foreign ids (teacher deleting an admin-owned row) are silently
     * skipped rather than 403-ing the whole batch.
     */
    public function bulkDelete(Request $request)
    {
        $this->authorizeCurriculum($request);
        $user = $request->user();

        $rawIds = $request->input('ids', []);
        $rawIds = is_array($rawIds) ? $rawIds : [];
        $ids = collect($rawIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        if (count($ids) === 0) {
            return back()->with('error', '`ids` array is required and must be non-empty.');
        }
        if (count($ids) > 1000) {
            return back()->with('error', 'Too many ids in one request (max 1000).');
        }

        $existing = LearningObjective::query()
            ->whereIn('id', $ids)
            ->get(['id', 'uploaded_by']);
        $existingMap = $existing->keyBy('id');

        $deletable = [];
        $skipped = 0;
        foreach ($ids as $id) {
            if (! $existingMap->has($id)) {
                continue; // counted as notFound below
            }
            $owner = $existingMap->get($id)->uploaded_by;
            if ($user->role === 'admin' || $owner === $user->id) {
                $deletable[] = $id;
            } else {
                $skipped++;
            }
        }
        $notFound = count($ids) - $existing->count();

        $deleted = 0;
        if (count($deletable) > 0) {
            $deleted = LearningObjective::query()->whereIn('id', $deletable)->delete();
        }

        $parts = ["Deleted {$deleted}."];
        if ($skipped) {
            $parts[] = "Skipped {$skipped} (not yours).";
        }
        if ($notFound) {
            $parts[] = "{$notFound} not found.";
        }

        return back()->with('success', implode(' ', $parts));
    }

    /**
     * POST /teacher/learning-objectives/upload — two-phase Excel import.
     *
     *  Phase 1 (multipart, "file" present): parse the .xlsx server-side and
     *    redirect back with a `preview` flash payload so the page can show
     *    the recognised rows + warnings before committing.
     *  Phase 2 ("confirm"=1, "rows" JSON present): insert the confirmed
     *    rows (canonicalise subject, assign sort_order = max+1+index,
     *    dedupe within request + against the DB).
     */
    public function upload(Request $request)
    {
        $this->authorizeCurriculum($request);
        $user = $request->user();

        $confirm = filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOLEAN);

        // ----- Phase 2: commit the confirmed rows -----
        if ($confirm) {
            return $this->commitImport($request, $user);
        }

        // ----- Phase 1: parse the uploaded file and return a preview -----
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'curriculum' => ['required', 'string'],
        ]);

        $curriculum = $this->parseCurriculum((string) $request->input('curriculum'));
        if (! $curriculum) {
            return back()->with('error', 'Invalid curriculum.');
        }

        try {
            $parsed = $this->parseExcel($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', 'Excel parse failed: '.$e->getMessage());
        }

        if (count($parsed['rows']) === 0) {
            return back()->with('error',
                'No rows recognised in this Excel. Make sure it has headers like Topic / Subtopic / Learning Objective.'
            );
        }

        return back()->with('lo_preview', [
            'fileName' => $request->file('file')->getClientOriginalName(),
            'curriculum' => $curriculum,
            'rows' => $parsed['rows'],
            'warnings' => $parsed['warnings'],
        ]);
    }

    /** Phase 2 of upload(): validate + insert the confirmed rows. */
    private function commitImport(Request $request, User $user)
    {
        $curriculum = $this->parseCurriculum((string) $request->input('curriculum'));
        if (! $curriculum) {
            return back()->with('error', '`curriculum` is required and must be one of: '.implode(', ', self::VALID_CURRICULA).'.');
        }

        $language = trim((string) $request->input('language', ''));
        $language = $language !== '' ? $language : 'English';

        $subject = Subjects::canonical((string) $request->input('subject', ''));
        if ($subject === '') {
            return back()->with('error', '`subject` is required.');
        }

        $rawRows = $request->input('rows', []);
        if (! is_array($rawRows) || count($rawRows) === 0) {
            return back()->with('error', '`rows` array is required.');
        }

        $fileName = trim((string) $request->input('fileName', ''));
        $fileName = $fileName !== '' ? $fileName : null;

        // Sanitize + dedupe within the request.
        $seen = [];
        $warnings = [];
        $sane = [];
        foreach ($rawRows as $raw) {
            $r = $this->sanitizeRow($raw);
            if (! $r) {
                $warnings[] = 'Row skipped: missing topic or LO text.';

                continue;
            }
            $key = mb_strtolower($r['topic']).' '.mb_strtolower($r['subtopic'] ?? '').' '.mb_strtolower($r['text']);
            if (isset($seen[$key])) {
                $warnings[] = 'Duplicate in file: "'.mb_substr($r['text'], 0, 40).'…" — skipped.';

                continue;
            }
            $seen[$key] = true;
            $sane[] = $r;
        }

        if (count($sane) === 0) {
            return back()->with('error', 'No valid rows to import.');
        }

        [$ownerId, $ownerName] = $this->bankOwner($user);

        // Filter out rows THIS USER's own catalog already has — keeps re-uploads
        // idempotent without blocking imports across users.
        //
        // CRITICAL: the dedupe scope MUST match the visibility scope (index()
        // filters by uploaded_by for teachers). Otherwise teacher B trying to
        // import a file that teacher A already imported would have all 300
        // rows silently rejected as "already in DB" even though teacher B's
        // catalog is empty — and the misleading "Imported 0 LO(s). 300
        // warning(s)" message would point at content the user can't even see.
        // Symptom: tab count stays 0; teacher thinks the import is broken.
        $existing = LearningObjective::query()
            ->where('curriculum', $curriculum)
            ->where('subject', $subject)
            ->where('uploaded_by', $user->id)
            ->where(function ($q) use ($sane) {
                foreach ($sane as $r) {
                    $q->orWhere(function ($qq) use ($r) {
                        $qq->where('topic', $r['topic'])
                            ->where('text', $r['text']);
                        if ($r['subtopic'] === null) {
                            $qq->whereNull('subtopic');
                        } else {
                            $qq->where('subtopic', $r['subtopic']);
                        }
                    });
                }
            })
            ->get(['topic', 'subtopic', 'text']);
        $dbKeys = [];
        foreach ($existing as $e) {
            $dbKeys[mb_strtolower($e->topic).' '.mb_strtolower($e->subtopic ?? '').' '.mb_strtolower($e->text)] = true;
        }

        $toInsert = [];
        foreach ($sane as $r) {
            $key = mb_strtolower($r['topic']).' '.mb_strtolower($r['subtopic'] ?? '').' '.mb_strtolower($r['text']);
            if (isset($dbKeys[$key])) {
                $warnings[] = 'Already in your catalog: "'.mb_substr($r['text'], 0, 40).'…" — skipped.';

                continue;
            }
            $toInsert[] = $r;
        }

        if (count($toInsert) === 0) {
            return back()->with('success', 'Imported 0 LO(s).'.(count($warnings) > 0 ? ' '.count($warnings).' warning(s).' : ''));
        }

        // New uploads append at the END of the user's own existing list
        // (sortOrder = previous-max-for-this-user + 1 + index), so each
        // teacher's catalog renders in spreadsheet order regardless of what
        // other users have already uploaded.
        $startOrder = (int) (LearningObjective::query()
            ->where('curriculum', $curriculum)
            ->where('subject', $subject)
            ->where('uploaded_by', $user->id)
            ->max('sort_order') ?? -1) + 1;

        $now = now();
        $payload = [];
        foreach ($toInsert as $i => $r) {
            $payload[] = [
                'id' => (string) Str::uuid(),
                'curriculum' => $curriculum,
                'language' => $language,
                'subject' => $subject,
                'topic' => $r['topic'],
                'subtopic' => $r['subtopic'],
                'text' => $r['text'],
                'sort_order' => $startOrder + $i,
                'created_by' => $ownerId,
                'created_by_name' => $ownerName,
                'uploaded_by' => $user->id,
                'uploaded_by_name' => $user->full_name,
                'source_file_name' => $fileName,
                'created_at' => $now,
            ];
        }
        LearningObjective::query()->insert($payload);

        $added = count($toInsert);
        $msg = "Imported {$added} LO(s).";
        if (count($warnings) > 0) {
            $msg .= ' '.count($warnings).' warning(s).';
        }

        return back()->with('success', $msg);
    }

    // ------------------------------------------------------------------
    // Excel parser (PHP port of excel-lo-parser.ts)
    // ------------------------------------------------------------------

    /**
     * Parse a Learning Objectives spreadsheet into flat
     * { topic, subtopic, text } rows. Column order is matched by header
     * name (case-insensitive, EN + ID aliases). Subtopic optional. Empty
     * rows skipped. Topic / subtopic inherit from the row above when blank
     * (handles merged cells / sparse curriculum layouts). All sheets are
     * concatenated.
     *
     * @return array{rows:array<int,array{topic:string,subtopic:?string,text:string}>,warnings:string[]}
     */
    private function parseExcel(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $out = [];
        $warnings = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $name = $sheet->getTitle();
            $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            $highestRow = $sheet->getHighestDataRow();

            // Locate header row (row 1) + column positions.
            $topicCol = -1;
            $subtopicCol = -1;
            $loCol = -1;
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $txt = $this->cellText($sheet->getCell([$col, 1]));
                if ($txt === '') {
                    continue;
                }
                if ($topicCol < 0 && $this->matchColumn($txt, self::TOPIC_ALIASES)) {
                    $topicCol = $col;
                } elseif ($subtopicCol < 0 && $this->matchColumn($txt, self::SUBTOPIC_ALIASES)) {
                    $subtopicCol = $col;
                } elseif ($loCol < 0 && $this->matchColumn($txt, self::LO_ALIASES)) {
                    $loCol = $col;
                }
            }

            if ($loCol < 0) {
                $warnings[] = "Sheet \"{$name}\": no \"Learning Objective\" column found — skipped.";

                continue;
            }
            if ($topicCol < 0) {
                $warnings[] = "Sheet \"{$name}\": no \"Topic\" column found — skipped.";

                continue;
            }

            $lastTopic = '';
            $lastSubtopic = '';
            for ($r = 2; $r <= $highestRow; $r++) {
                $topicRaw = trim($this->cellText($sheet->getCell([$topicCol, $r])));
                $subtopicRaw = $subtopicCol > 0
                    ? trim($this->cellText($sheet->getCell([$subtopicCol, $r])))
                    : '';
                $loRaw = trim($this->cellText($sheet->getCell([$loCol, $r])));
                if ($loRaw === '') {
                    continue; // skip rows with no LO text
                }

                $topic = $topicRaw !== '' ? $topicRaw : $lastTopic;
                $subtopic = $subtopicRaw !== '' ? $subtopicRaw : $lastSubtopic;
                if ($topic !== '') {
                    $lastTopic = $topic;
                }
                if ($subtopic !== '') {
                    $lastSubtopic = $subtopic;
                }

                if ($topic === '') {
                    $warnings[] = "Sheet \"{$name}\" row {$r}: LO has no topic, skipped.";

                    continue;
                }
                $out[] = [
                    'topic' => $topic,
                    'subtopic' => $subtopic !== '' ? $subtopic : null,
                    'text' => $loRaw,
                ];
            }
        }

        return ['rows' => $out, 'warnings' => $warnings];
    }

    /** Read a cell's display text, flattening rich-text runs. */
    private function cellText(Cell $cell): string
    {
        $value = $cell->getValue();
        if ($value === null) {
            return '';
        }
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        return (string) $value;
    }

    private function normaliseHeader(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return mb_strtolower(trim($s));
    }

    /**
     * @param  string[]  $aliases
     */
    private function matchColumn(string $headerText, array $aliases): bool
    {
        $h = $this->normaliseHeader($headerText);
        foreach ($aliases as $alias) {
            if ($h === $alias || str_starts_with($h, $alias)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  mixed  $raw
     * @return array{topic:string,subtopic:?string,text:string}|null
     */
    private function sanitizeRow($raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $topic = isset($raw['topic']) && is_string($raw['topic']) ? trim($raw['topic']) : '';
        if ($topic === '') {
            return null;
        }
        $text = isset($raw['text']) && is_string($raw['text']) ? trim($raw['text']) : '';
        if ($text === '' || mb_strlen($text) < 3) {
            return null;
        }
        $subtopic = isset($raw['subtopic']) && is_string($raw['subtopic']) && trim($raw['subtopic']) !== ''
            ? trim($raw['subtopic'])
            : null;

        return ['topic' => $topic, 'subtopic' => $subtopic, 'text' => $text];
    }

    private function parseCurriculum(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return in_array($value, self::VALID_CURRICULA, true) ? $value : null;
    }

    /**
     * Look up the canonical LO owner: the first admin (the catalog belongs
     * to the admin database, mirroring bank), falling back to the caller.
     *
     * @return array{0:string,1:string}
     */
    private function bankOwner(User $user): array
    {
        $admin = User::query()
            ->where('role', 'admin')
            ->orderBy('created_at')
            ->first(['id', 'full_name']);

        return [
            $admin?->id ?? $user->id,
            $admin?->full_name ?? $user->full_name,
        ];
    }

    /**
     * Capability gate. The route group is teacher-only, but an admin who
     * reaches it must bypass, and a teacher missing `curriculum.manage`
     * must be rejected — exactly like the original requireCap().
     */
    private function authorizeCurriculum(Request $request): void
    {
        $user = $request->user();
        if ($user && $user->role === 'admin') {
            return; // admin bypasses
        }
        if (! $user || $user->role !== 'teacher') {
            abort(403, 'This action requires a teacher account.');
        }
        if (! Capabilities::has($user->capabilities, 'curriculum.manage')) {
            abort(403, 'This feature ("curriculum.manage") is disabled for your account. Ask your administrator to enable it.');
        }
    }
}
