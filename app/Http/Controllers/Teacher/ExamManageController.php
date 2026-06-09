<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamAccessToken;
use App\Models\ExamMedia;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\User;
use App\Support\Capabilities;
use App\Support\CryptoSecrets;
use App\Support\Subjects;
use App\Support\Tokens;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

/**
 * Teacher exam management: LIST + CREATE + EDIT-SETTINGS + DELETE +
 * PACKAGE-IMPORT, plus the inline token actions (regenerate / delete)
 * the Exams list renders next to each access-token pill.
 *
 * Port of the original Next.js routes:
 *   - /api/teacher/exams              (GET list, POST create)
 *   - /api/teacher/exams/[examId]     (PATCH settings, DELETE)
 *   - /api/teacher/exams/import       (zip/json package → exam + questions)
 *   - /api/teacher/tokens/[tokenId]   (DELETE) + .../regenerate (POST)
 *
 * Ownership: a teacher only sees / mutates exams where created_by is
 * theirs; admins bypass the scope (see {@see loadExamForRequester}).
 */
class ExamManageController extends Controller
{
    private const TYPE_VALUES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

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

    private const DEFAULT_TYPE_DISTRIBUTION = [
        'single_choice' => 0, 'multi_select' => 0, 'short_text' => 0, 'numeric' => 0, 'essay' => 0,
    ];

    private const DEFAULT_DIFFICULTY_DISTRIBUTION = [
        'remember' => 15, 'understand' => 25, 'apply' => 25, 'analyze' => 15,
        'evaluate' => 10, 'create' => 7, 'olympiad' => 3,
    ];

    private const DEFAULT_MEDIA_TARGETS = ['images' => 0, 'tables' => 0];

    // -----------------------------------------------------------------
    // LIST — GET /teacher/exams
    // -----------------------------------------------------------------

    /**
     * Lists exams owned by the signed-in teacher (or every exam for an
     * admin). Per-exam stats (submission count, average %, pass count)
     * and the live access-token pills (decrypted preview + usage) are
     * computed with aggregate queries instead of N+1 per exam.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        $query = Exam::query()->orderByDesc('created_at');
        if (! $isAdmin) {
            $query->where('created_by', $user->id);
        } elseif ($teacherId = $request->query('teacherId')) {
            // Admins may narrow to a single teacher's exams.
            $query->where('created_by', $teacherId);
        }

        $exams = $query->get([
            'id', 'exam_code', 'name', 'duration_minutes', 'passing_grade', 'active', 'created_by', 'created_by_name',
        ]);

        $examIds = $exams->pluck('id');

        $summaries = [];
        if ($examIds->isNotEmpty()) {
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
                    // Decrypt for the teacher's list (rendered as inline pills).
                    // Legacy plaintext previews flow through untouched.
                    'tokenPreview' => CryptoSecrets::decryptTokenPreview($t->token_preview) ?? $t->token_preview,
                    'usedCount' => (int) $t->used_count,
                    'maxUses' => (int) $t->max_uses,
                    'expiresAt' => $t->expires_at?->toIso8601String(),
                ];
            }

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
        }

        return Inertia::render('teacher/Exams', [
            'exams' => $summaries,
            'isAdmin' => $isAdmin,
        ]);
    }

    // -----------------------------------------------------------------
    // CREATE form data — GET /teacher/exams/new
    // -----------------------------------------------------------------

    /** Renders the empty create form with capability gates + subject choices. */
    public function create(Request $request)
    {
        return Inertia::render('teacher/ExamCreate', [
            'gates' => $this->formGates($request->user()),
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

    // -----------------------------------------------------------------
    // CREATE — POST /teacher/exams
    // -----------------------------------------------------------------

    /** Creates a new (empty) exam owned by the signed-in teacher. */
    public function store(Request $request)
    {
        $user = $request->user();

        $code = strtoupper(trim((string) $request->input('examCode', '')));
        if (! preg_match('/^[A-Z0-9-]{3,40}$/', $code)) {
            throw ValidationException::withMessages([
                'examCode' => 'Exam code must be 3-40 characters: uppercase letters, digits, or dashes.',
            ]);
        }
        $name = trim((string) $request->input('name', ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Exam name must be 2-120 characters.']);
        }
        // General Instructions field is no longer in the form (replaced by a
        // prominent Security panel). Accept anything the request sends —
        // including empty — and persist as-is for backwards compat with
        // imported exam packages that still carry the field.
        $generalInstructions = trim((string) $request->input('generalInstructions', ''));
        // Legacy ≥5 char check kept ONLY for the package-import path (which
        // still sends explicit instructions). The store() endpoint never sends
        // generalInstructions anymore, so we skip the check when empty.
        if ($generalInstructions !== '' && mb_strlen($generalInstructions) < 5) {
            throw ValidationException::withMessages([
                'generalInstructions' => 'Instructions are required (at least 5 characters).',
            ]);
        }

        $data = [
            'exam_code' => $code,
            'name' => $name,
            'general_instructions' => $generalInstructions,
            'created_by' => $user->id,
            'created_by_name' => $user->full_name,
            'active' => true,
            ...$this->settingsFromRequest($request, $user, true),
        ];

        try {
            $exam = Exam::create($data);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages(['examCode' => "Exam code \"{$code}\" is already in use."]);
            }
            throw $e;
        }

        return redirect('/teacher/exams/' . $exam->exam_code)
            ->with('success', "Created exam {$exam->name}. Add questions next.");
    }

    // -----------------------------------------------------------------
    // EDIT form data — GET /teacher/exams/{examId}/edit
    // -----------------------------------------------------------------

    /** Renders the edit-settings form seeded from the existing exam. */
    public function editSettings(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadExamForRequester($examId, $user);
        if (! $exam) {
            abort(404, 'Exam not found.');
        }

        return Inertia::render('teacher/ExamEdit', [
            'gates' => $this->formGates($user),
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
                'typeDistribution' => $this->normalizeTypeDistribution($exam->type_distribution),
                'difficultyDistribution' => $this->normalizeDifficultyDistribution($exam->difficulty_distribution),
                'mediaTargets' => $this->normalizeMediaTargets($exam->media_targets),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // UPDATE SETTINGS — PATCH /teacher/exams/{examId}
    // -----------------------------------------------------------------

    /** Updates the writable settings on an exam (capability-gated fields). */
    public function updateSettings(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadExamForRequester($examId, $user);
        if (! $exam) {
            abort(404, 'Exam not found.');
        }

        $exam->fill($this->settingsFromRequest($request, $user, false));
        $exam->save();

        return redirect('/teacher/exams/' . $exam->exam_code)
            ->with('success', 'Exam settings saved.');
    }

    // -----------------------------------------------------------------
    // DELETE EXAM — DELETE /teacher/exams/{examId}
    // -----------------------------------------------------------------

    /**
     * Removes the exam. FK cascades take care of questions, media,
     * answer keys, tokens, sessions, drafts, and submissions.
     */
    public function destroy(Request $request, string $examId)
    {
        $user = $request->user();
        $exam = $this->loadExamForRequester($examId, $user);
        if (! $exam) {
            abort(404, 'Exam not found.');
        }
        $name = $exam->name;
        $exam->delete();

        return redirect('/teacher/exams')->with('success', "Deleted exam {$name}.");
    }

    // -----------------------------------------------------------------
    // TOKEN: regenerate — POST /teacher/exams/tokens/{tokenId}/regenerate
    // -----------------------------------------------------------------

    /**
     * Deactivates a token + mints a fresh 6-char replacement carrying the
     * SAME exam scope, classId, expiry, and the REMAINING uses (so the
     * effective cap is preserved). Surfaces the new plaintext code once.
     */
    public function regenerateToken(Request $request, string $tokenId)
    {
        $user = $request->user();
        $existing = ExamAccessToken::with('exam:id,exam_code,name,created_by')->find($tokenId);
        if (! $existing || ! $existing->exam) {
            abort(404, 'Token not found.');
        }
        if ($user->role === 'teacher' && $existing->exam->created_by !== $user->id) {
            abort(403, 'This token is not for one of your exams.');
        }

        $remainingUses = $existing->max_uses - $existing->used_count;
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
            "Replaced token → {$newCode} ({$remainingUses} use" . ($remainingUses === 1 ? '' : 's') . ' left).'
        );
    }

    // -----------------------------------------------------------------
    // TOKEN: delete — DELETE /teacher/exams/tokens/{tokenId}
    // -----------------------------------------------------------------

    /** Hard-deletes a single access token (the X on a token pill). */
    public function deleteToken(Request $request, string $tokenId)
    {
        $user = $request->user();
        $token = ExamAccessToken::with('exam:id,created_by')->find($tokenId);
        if (! $token || ! $token->exam) {
            abort(404, 'Token not found.');
        }
        if ($user->role === 'teacher' && $token->exam->created_by !== $user->id) {
            abort(403, 'This token is not for one of your exams.');
        }
        $preview = CryptoSecrets::decryptTokenPreview($token->token_preview) ?? $token->token_preview;
        $token->delete();

        return back()->with('success', "Deleted token {$preview}.");
    }

    // -----------------------------------------------------------------
    // IMPORT PACKAGE — POST /teacher/exams/import
    // -----------------------------------------------------------------

    /**
     * Parses an uploaded exam package and creates the exam + all its
     * questions + media in one transaction. Supports two shapes:
     *   - .zip  : an .xlsx (Metadata + Questions sheets) at the root +
     *             optional media files referenced by basename.
     *   - .json : { metadata, questions[], media[] } produced by the AI
     *             export flow (data-URL media inlined).
     * Each created question is also mirrored into the shared admin
     * question bank (same behaviour as the original import route).
     *
     * PHP port of exam-package-parser (zip-parser.ts) using ext-zip +
     * phpoffice/phpspreadsheet.
     */
    public function importPackage(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'package' => 'required|file|max:51200', // 50 MB ceiling for media-heavy zips
        ]);
        $file = $request->file('package');
        $ext = strtolower($file->getClientOriginalExtension());

        try {
            $parsed = $ext === 'json'
                ? $this->parseExamPackageJson((string) file_get_contents($file->getRealPath()))
                : $this->parseExamPackageZip($file->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage() ?: 'Could not parse package.');
        }

        $code = strtoupper(trim($parsed['metadata']['examCode']));
        $code = preg_replace('/\s+/', '-', $code);
        if (! preg_match('/^[A-Z0-9-]{3,40}$/', $code)) {
            return back()->with('error', 'Exam code must be 3-40 characters: uppercase letters, digits, or dashes.');
        }
        $name = trim($parsed['metadata']['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return back()->with('error', 'Exam name must be 2-120 characters.');
        }
        $durationMinutes = (int) $parsed['metadata']['durationMinutes'];
        if ($durationMinutes < 1 || $durationMinutes > 480) {
            return back()->with('error', 'Duration must be between 1 and 480 minutes.');
        }
        $passingGrade = (int) $parsed['metadata']['passingGrade'];
        if ($passingGrade < 0 || $passingGrade > 100) {
            return back()->with('error', 'Passing grade must be between 0 and 100.');
        }
        $generalInstructions = trim($parsed['metadata']['generalInstructions']);
        if (mb_strlen($generalInstructions) < 5) {
            return back()->with('error', 'Instructions are required (at least 5 characters).');
        }

        $language = trim($parsed['metadata']['language']) ?: 'English';
        $subject = trim($parsed['metadata']['subject']);
        $examMode = $parsed['metadata']['examMode'] === 'try_out' ? 'try_out' : 'strict';

        // Index media by filename (basename), then prepare/validate questions.
        $mediaByFile = [];
        foreach ($parsed['media'] as $m) {
            $mediaByFile[$m['fileName']] = $m;
        }
        $usedMedia = [];
        $warnings = [];
        $prepared = [];

        $questions = $parsed['questions'];
        usort($questions, fn ($a, $b) => $a['position'] <=> $b['position']);

        foreach ($questions as $q) {
            $prompt = trim((string) ($q['prompt'] ?? ''));
            if (mb_strlen($prompt) < 2) {
                $warnings[] = "Question at position {$q['position']}: prompt too short, skipped.";

                continue;
            }
            $points = is_numeric($q['points']) ? (float) $q['points'] : 0;
            if ($points <= 0 || $points > 100) {
                $warnings[] = "Question at position {$q['position']}: invalid points, skipped.";

                continue;
            }
            if (! in_array($q['type'], self::TYPE_VALUES, true)) {
                $warnings[] = "Question at position {$q['position']}: invalid type, skipped.";

                continue;
            }
            $mediaEntry = null;
            if (! empty($q['mediaFile'])) {
                if (! isset($mediaByFile[$q['mediaFile']])) {
                    $warnings[] = "Question at position {$q['position']}: media file \"{$q['mediaFile']}\" not found in package.";
                } else {
                    $mediaEntry = $mediaByFile[$q['mediaFile']];
                    $usedMedia[$q['mediaFile']] = true;
                }
            }
            $prepared[] = [
                'position' => count($prepared) + 1,
                'type' => $q['type'],
                'topic' => (trim((string) ($q['topic'] ?? '')) ?: 'General'),
                'points' => $points,
                'prompt' => $prompt,
                'options' => $this->normalizeQuestionOptions($q['type'], $q['options'] ?? null),
                'correctAnswer' => $this->normalizeCorrectAnswer($q['type'], $q['correctAnswer'] ?? null),
                'explanationText' => trim((string) ($q['explanationText'] ?? '')),
                'mediaEntry' => $mediaEntry,
            ];
        }
        foreach (array_keys($mediaByFile) as $fileName) {
            if (! isset($usedMedia[$fileName])) {
                $warnings[] = "Media \"{$fileName}\" wasn't referenced by any question.";
            }
        }
        if (count($prepared) === 0) {
            return back()->with('error', 'All questions in the package failed validation.');
        }

        try {
            $result = DB::transaction(function () use (
                $prepared, $code, $name, $durationMinutes, $passingGrade, $generalInstructions,
                $examMode, $language, $subject, $parsed, $user
            ) {
                $exam = Exam::create([
                    'exam_code' => $code,
                    'name' => $name,
                    'duration_minutes' => $durationMinutes,
                    'passing_grade' => $passingGrade,
                    'general_instructions' => $generalInstructions,
                    'exam_mode' => $examMode,
                    'shuffle_questions' => (bool) $parsed['metadata']['shuffleQuestions'],
                    'shuffle_options' => (bool) $parsed['metadata']['shuffleOptions'],
                    'language' => $language,
                    'subject' => $subject !== '' ? $subject : null,
                    'type_distribution' => self::DEFAULT_TYPE_DISTRIBUTION,
                    'difficulty_distribution' => self::DEFAULT_DIFFICULTY_DISTRIBUTION,
                    'media_targets' => self::DEFAULT_MEDIA_TARGETS,
                    'created_by' => $user->id,
                    'created_by_name' => $user->full_name,
                    'active' => true,
                ]);

                // Bank is a shared admin resource: createdBy = the oldest
                // admin, uploadedBy = the teacher who uploaded the package.
                $bankOwner = User::where('role', 'admin')->orderBy('created_at')->first(['id', 'full_name']);
                $bankOwnerId = $bankOwner?->id ?? $user->id;
                $bankOwnerName = $bankOwner?->full_name ?? $user->full_name;

                $questionsCreated = 0;
                $mediaCreated = 0;
                $bankCreated = 0;

                foreach ($prepared as $p) {
                    $question = ExamQuestion::create([
                        'exam_id' => $exam->id,
                        'position' => $p['position'],
                        'type' => $p['type'],
                        'topic' => $p['topic'],
                        'tags' => [],
                        'prompt' => $p['prompt'],
                        'options' => $p['options'],
                        'points' => $p['points'],
                        'correct_answer' => $p['correctAnswer'],
                        'explanation_text' => $p['explanationText'],
                        'language' => $language,
                        'media_file' => $p['mediaEntry']['fileName'] ?? null,
                    ]);
                    $questionsCreated++;

                    if ($p['mediaEntry']) {
                        ExamMedia::create([
                            'question_id' => $question->id,
                            'type' => $p['mediaEntry']['type'],
                            'url' => $p['mediaEntry']['dataUrl'],
                            'sort_order' => 0,
                        ]);
                        $mediaCreated++;
                    }

                    BankQuestion::create([
                        'type' => $p['type'],
                        'language' => $language ?: 'English',
                        'subject' => Subjects::canonical($subject) ?: 'General',
                        'topic' => $p['topic'],
                        'difficulty' => 'understand',
                        'tags' => [],
                        'prompt' => $p['prompt'],
                        'options' => $p['options'],
                        'points' => $p['points'],
                        'correct_answer' => $p['correctAnswer'],
                        'explanation_text' => $p['explanationText'],
                        'created_by' => $bankOwnerId,
                        'created_by_name' => $bankOwnerName,
                        'uploaded_by' => $user->id,
                        'uploaded_by_name' => $user->full_name,
                        'source_file_name' => "{$name} (exam package)",
                        'media_url' => $p['mediaEntry']['dataUrl'] ?? null,
                        'media_type' => $p['mediaEntry']['type'] ?? null,
                    ]);
                    $bankCreated++;
                }

                return [
                    'examId' => $exam->exam_code,
                    'questionsCreated' => $questionsCreated,
                    'mediaCreated' => $mediaCreated,
                    'bankCreated' => $bankCreated,
                ];
            }, 3);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return back()->with('error', "Exam code \"{$code}\" is already in use.");
            }
            throw $e;
        }

        $warn = '';
        if (count($warnings) > 0) {
            $warn = ' Warnings: ' . implode('; ', array_slice($warnings, 0, 2)) . (count($warnings) > 2 ? '…' : '');
        }

        return redirect('/teacher/exams/' . $result['examId'])->with(
            'success',
            "Imported {$result['examId']}: {$result['questionsCreated']} question(s), {$result['mediaCreated']} media file(s). {$result['bankCreated']} also added to your Question Bank.{$warn}"
        );
    }

    // =================================================================
    // Helpers — settings, gates, subjects
    // =================================================================

    /**
     * Build the column updates for the writable exam settings, honouring
     * the teacher's capability gates. On create ($isCreate) every gated
     * field still gets a value (the form default) so the row is complete;
     * on edit a gated-off field is simply skipped so it keeps its stored
     * value. Admins are never gated.
     */
    private function settingsFromRequest(Request $request, User $user, bool $isCreate): array
    {
        $caps = $user->role === 'teacher' ? Capabilities::fill($user->capabilities) : null;
        $isAdmin = $user->role === 'admin';
        $can = fn (string $key) => $isAdmin || Capabilities::has($caps, $key);

        $out = [];

        // Duration / passing grade.
        if ($can('exam.config.duration')) {
            $v = (int) $request->input('durationMinutes', 30);
            if ($v < 1 || $v > 480) {
                throw ValidationException::withMessages(['durationMinutes' => 'Duration must be between 1 and 480 minutes.']);
            }
            $out['duration_minutes'] = $v;
        } elseif ($isCreate) {
            $out['duration_minutes'] = 30;
        }

        if ($can('exam.config.passingGrade')) {
            $v = (int) $request->input('passingGrade', 70);
            if ($v < 0 || $v > 100) {
                throw ValidationException::withMessages(['passingGrade' => 'Passing grade must be between 0 and 100.']);
            }
            $out['passing_grade'] = $v;
        } elseif ($isCreate) {
            $out['passing_grade'] = 70;
        }

        // Mode.
        if ($can('exam.config.mode')) {
            $out['exam_mode'] = $request->input('examMode') === 'try_out' ? 'try_out' : 'strict';
        } elseif ($isCreate) {
            $out['exam_mode'] = 'strict';
        }

        // Shuffling.
        if ($can('exam.config.shuffleQuestions')) {
            $out['shuffle_questions'] = $request->boolean('shuffleQuestions');
        } elseif ($isCreate) {
            $out['shuffle_questions'] = false;
        }
        if ($can('exam.config.shuffleOptions')) {
            $out['shuffle_options'] = $request->boolean('shuffleOptions');
        } elseif ($isCreate) {
            $out['shuffle_options'] = false;
        }

        // Language.
        if ($can('exam.config.language')) {
            $lang = trim((string) $request->input('language', 'English')) ?: 'English';
            if (mb_strlen($lang) > 60) {
                throw ValidationException::withMessages(['language' => 'Language label must be 60 characters or fewer.']);
            }
            $out['language'] = $lang;
        } elseif ($isCreate) {
            $out['language'] = 'English';
        }

        // SEB requirement.
        if ($can('exam.config.seb')) {
            $out['seb_required'] = $request->boolean('sebRequired');
        } elseif ($isCreate) {
            $out['seb_required'] = false;
        }

        // Subject — bilingual select, canonicalised at the write site.
        // Not capability-gated (parity with the bank/AI write sites).
        $subjectRaw = $request->input('subject');
        if ($subjectRaw !== null) {
            $subject = Subjects::canonical((string) $subjectRaw);
            if (mb_strlen($subject) > 60) {
                throw ValidationException::withMessages(['subject' => 'Subject label must be 60 characters or fewer.']);
            }
            $out['subject'] = $subject !== '' ? $subject : null;
        } elseif ($isCreate) {
            $out['subject'] = null;
        }

        // Media base URL.
        $mediaBaseRaw = $request->input('mediaBaseUrl');
        if ($mediaBaseRaw !== null) {
            $mb = trim((string) $mediaBaseRaw);
            $out['media_base_url'] = $mb !== '' ? $mb : null;
        } elseif ($isCreate) {
            $out['media_base_url'] = null;
        }

        // Scheduling.
        if ($request->has('startTime')) {
            $out['start_time'] = $this->parseDateOrNull($request->input('startTime'));
        } elseif ($isCreate) {
            $out['start_time'] = null;
        }
        if ($request->has('endTime')) {
            $out['end_time'] = $this->parseDateOrNull($request->input('endTime'));
        } elseif ($isCreate) {
            $out['end_time'] = null;
        }

        // Composition targets (per-field gated; whole map written/skipped).
        $type = $this->collectTypeDistribution($request, $caps, $isAdmin, $isCreate);
        if ($type !== null) {
            $this->validateTypeDistribution($type);
            $out['type_distribution'] = $type;
        }
        $difficulty = $this->collectDifficultyDistribution($request, $caps, $isAdmin, $isCreate);
        if ($difficulty !== null) {
            $this->validateDifficultyDistribution($difficulty);
            $out['difficulty_distribution'] = $difficulty;
        }
        $media = $this->collectMediaTargets($request, $caps, $isAdmin, $isCreate);
        if ($media !== null) {
            $this->validateMediaTargets($media);
            $out['media_targets'] = $media;
        }

        return $out;
    }

    /**
     * Per-field capability gates for the exam config form. Mirrors the
     * original exam-form-caps.examFormGroups(), with the SEB gate added.
     */
    private function formGates(User $user): array
    {
        $caps = $user->role === 'teacher' ? Capabilities::fill($user->capabilities) : null;
        $isAdmin = $user->role === 'admin';
        $g = fn (string $key) => $isAdmin || Capabilities::has($caps, $key);

        $showDuration = $g('exam.config.duration');
        $showPassing = $g('exam.config.passingGrade');
        $showMode = $g('exam.config.mode');
        $showShuffleQuestions = $g('exam.config.shuffleQuestions');
        $showShuffleOptions = $g('exam.config.shuffleOptions');
        $showLanguage = $g('exam.config.language');
        $showSeb = $g('exam.config.seb');

        $showTypeSingle = $g('exam.param.type.single');
        $showTypeMulti = $g('exam.param.type.multi');
        $showTypeShortText = $g('exam.param.type.short_text');
        $showTypeNumeric = $g('exam.param.type.numeric');
        $showTypeEssay = $g('exam.param.type.essay');

        // Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
        $showDifficultyRemember = $g('exam.param.difficulty.remember');
        $showDifficultyUnderstand = $g('exam.param.difficulty.understand');
        $showDifficultyApply = $g('exam.param.difficulty.apply');
        $showDifficultyAnalyze = $g('exam.param.difficulty.analyze');
        $showDifficultyEvaluate = $g('exam.param.difficulty.evaluate');
        $showDifficultyCreate = $g('exam.param.difficulty.create');
        $showDifficultyOlympiad = $g('exam.param.difficulty.olympiad');

        $showMediaImage = $g('exam.param.media.image');
        $showMediaTable = $g('exam.param.media.table');

        $showSchedulingRow = $showDuration || $showPassing;
        $showShuffleGroup = $showShuffleQuestions || $showShuffleOptions;
        $showModeRow = $showMode || $showShuffleGroup;
        $showTypeRow = $showTypeSingle || $showTypeMulti || $showTypeShortText || $showTypeNumeric || $showTypeEssay;
        $showDifficultyRow = $showDifficultyRemember || $showDifficultyUnderstand || $showDifficultyApply || $showDifficultyAnalyze || $showDifficultyEvaluate || $showDifficultyCreate || $showDifficultyOlympiad;
        $showMediaRow = $showMediaImage || $showMediaTable;
        $showCompositionFieldset = $showLanguage || $showTypeRow || $showDifficultyRow || $showMediaRow;

        return [
            'showDuration' => $showDuration,
            'showPassing' => $showPassing,
            'showMode' => $showMode,
            'showShuffleQuestions' => $showShuffleQuestions,
            'showShuffleOptions' => $showShuffleOptions,
            'showLanguage' => $showLanguage,
            'showSeb' => $showSeb,
            'showTypeSingle' => $showTypeSingle,
            'showTypeMulti' => $showTypeMulti,
            'showTypeShortText' => $showTypeShortText,
            'showTypeNumeric' => $showTypeNumeric,
            'showTypeEssay' => $showTypeEssay,
            // Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
            'showDifficultyRemember' => $showDifficultyRemember,
            'showDifficultyUnderstand' => $showDifficultyUnderstand,
            'showDifficultyApply' => $showDifficultyApply,
            'showDifficultyAnalyze' => $showDifficultyAnalyze,
            'showDifficultyEvaluate' => $showDifficultyEvaluate,
            'showDifficultyCreate' => $showDifficultyCreate,
            'showDifficultyOlympiad' => $showDifficultyOlympiad,
            'showMediaImage' => $showMediaImage,
            'showMediaTable' => $showMediaTable,
            'showSchedulingRow' => $showSchedulingRow,
            'showShuffleGroup' => $showShuffleGroup,
            'showModeRow' => $showModeRow,
            'showTypeRow' => $showTypeRow,
            'showDifficultyRow' => $showDifficultyRow,
            'showMediaRow' => $showMediaRow,
            'showCompositionFieldset' => $showCompositionFieldset,
        ];
    }

    /** Curated bilingual subjects merged with distinct subjects already in use. */
    private function subjectChoices(): array
    {
        $existing = Exam::query()->whereNotNull('subject')->distinct()->pluck('subject')->all();
        $bank = BankQuestion::query()->whereNotNull('subject')->distinct()->pluck('subject')->all();

        return Subjects::mergeWithExisting([...$existing, ...$bank]);
    }

    // ---- distribution collectors / validators -----------------------

    private function collectTypeDistribution(Request $request, ?array $caps, bool $isAdmin, bool $isCreate): ?array
    {
        $g = fn (string $key) => $isAdmin || Capabilities::has($caps, $key);
        $incoming = $request->input('typeDistribution');
        if (! is_array($incoming) && ! $isCreate) {
            return null;
        }
        $incoming = is_array($incoming) ? $incoming : [];
        $keys = ['single_choice' => 'exam.param.type.single', 'multi_select' => 'exam.param.type.multi',
            'short_text' => 'exam.param.type.short_text', 'numeric' => 'exam.param.type.numeric', 'essay' => 'exam.param.type.essay'];
        $out = self::DEFAULT_TYPE_DISTRIBUTION;
        $any = false;
        foreach ($keys as $field => $cap) {
            if ($g($cap) && isset($incoming[$field])) {
                $out[$field] = max(0, (int) $incoming[$field]);
                $any = true;
            } elseif (isset($incoming[$field])) {
                $out[$field] = max(0, (int) $incoming[$field]);
            }
        }

        return ($any || $isCreate) ? $out : null;
    }

    private function collectDifficultyDistribution(Request $request, ?array $caps, bool $isAdmin, bool $isCreate): ?array
    {
        $incoming = $request->input('difficultyDistribution');
        if (! is_array($incoming) && ! $isCreate) {
            return null;
        }
        $incoming = is_array($incoming) ? $incoming : [];
        $out = self::DEFAULT_DIFFICULTY_DISTRIBUTION;
        foreach (['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'] as $field) {
            if (isset($incoming[$field])) {
                $out[$field] = max(0, min(100, (int) $incoming[$field]));
            }
        }

        return $out;
    }

    private function collectMediaTargets(Request $request, ?array $caps, bool $isAdmin, bool $isCreate): ?array
    {
        $incoming = $request->input('mediaTargets');
        if (! is_array($incoming) && ! $isCreate) {
            return null;
        }
        $incoming = is_array($incoming) ? $incoming : [];
        $out = self::DEFAULT_MEDIA_TARGETS;
        foreach (['images', 'tables'] as $field) {
            if (isset($incoming[$field])) {
                $out[$field] = max(0, (int) $incoming[$field]);
            }
        }

        return $out;
    }

    private function validateTypeDistribution(array $d): void
    {
        foreach (['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'] as $key) {
            $v = $d[$key] ?? 0;
            if ($v < 0 || $v > 500) {
                throw ValidationException::withMessages(['typeDistribution' => "Type target {$key} must be 0–500."]);
            }
        }
    }

    private function validateDifficultyDistribution(array $d): void
    {
        $total = 0;
        foreach (['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'] as $key) {
            $v = $d[$key] ?? 0;
            if ($v < 0 || $v > 100) {
                throw ValidationException::withMessages(['difficultyDistribution' => "Difficulty {$key} must be 0–100."]);
            }
            $total += $v;
        }
        if (abs($total - 100) > 0.5) {
            throw ValidationException::withMessages([
                'difficultyDistribution' => "Difficulty percentages must sum to 100 (current total: {$total}).",
            ]);
        }
    }

    private function validateMediaTargets(array $d): void
    {
        if (($d['images'] ?? 0) < 0 || ($d['images'] ?? 0) > 500) {
            throw ValidationException::withMessages(['mediaTargets' => 'Image target must be 0–500.']);
        }
        if (($d['tables'] ?? 0) < 0 || ($d['tables'] ?? 0) > 500) {
            throw ValidationException::withMessages(['mediaTargets' => 'Table target must be 0–500.']);
        }
    }

    private function normalizeTypeDistribution(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        return [...self::DEFAULT_TYPE_DISTRIBUTION, ...array_intersect_key($raw, self::DEFAULT_TYPE_DISTRIBUTION)];
    }

    private function normalizeDifficultyDistribution(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        return [...self::DEFAULT_DIFFICULTY_DISTRIBUTION, ...array_intersect_key($raw, self::DEFAULT_DIFFICULTY_DISTRIBUTION)];
    }

    private function normalizeMediaTargets(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        return [...self::DEFAULT_MEDIA_TARGETS, ...array_intersect_key($raw, self::DEFAULT_MEDIA_TARGETS)];
    }

    // ---- misc helpers -----------------------------------------------

    private function loadExamForRequester(string $identifier, User $user): ?Exam
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

    private function parseDateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0:?string,1:?string} [plainCode, digest] or [null,null] */
    private function mintUniqueToken(): array
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = Tokens::generatePlain();
            $digest = Tokens::digest($candidate);
            $collision = ExamAccessToken::where('token_digest', $digest)->exists();
            if (! $collision) {
                return [$candidate, $digest];
            }
        }

        return [null, null];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? '') === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    // =================================================================
    // Package parsing (PHP port of zip-parser.ts / exam-package-parser)
    // =================================================================

    /**
     * Parse a .zip package: locate an .xlsx (prefer root), read its
     * Metadata + Questions sheets, and inline any media files referenced
     * by basename as data URLs.
     *
     * @return array{metadata:array,questions:array,media:array}
     */
    private function parseExamPackageZip(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open the zip file.');
        }

        try {
            // Find the .xlsx — prefer one at the root, accept any otherwise.
            $xlsxName = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = $stat['name'];
                if (str_ends_with($entry, '/')) {
                    continue;
                }
                if (! str_ends_with(strtolower($entry), '.xlsx')) {
                    continue;
                }
                if ($xlsxName === null || ! str_contains($entry, '/')) {
                    $xlsxName = $entry;
                }
            }
            if ($xlsxName === null) {
                throw new \RuntimeException('No .xlsx file found in the zip. Add an exam.xlsx at the root.');
            }

            // Extract the xlsx to a temp file for PhpSpreadsheet.
            $xlsxBytes = $zip->getFromName($xlsxName);
            if ($xlsxBytes === false) {
                throw new \RuntimeException('Could not read the xlsx inside the zip.');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'exampkg_') . '.xlsx';
            file_put_contents($tmp, $xlsxBytes);

            try {
                $reader = IOFactory::createReader('Xlsx');
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($tmp);
            } finally {
                @unlink($tmp);
            }

            $metadata = $this->readMetadataSheet($spreadsheet);
            $questions = $this->readQuestionsSheet($spreadsheet);
            $media = $this->readMediaFromZip($zip);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return ['metadata' => $metadata, 'questions' => $questions, 'media' => $media];
        } finally {
            $zip->close();
        }
    }

    /** @return array metadata map matching ParsedExamPackage.metadata */
    private function readMetadataSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): array
    {
        $sheet = $spreadsheet->getSheetByName('Metadata') ?? $spreadsheet->getSheetByName('metadata');
        if (! $sheet) {
            throw new \RuntimeException("Missing 'Metadata' sheet in the xlsx file.");
        }

        $map = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator('A', 'B');
            $cells->setIterateOnlyExistingCells(false);
            $vals = [];
            foreach ($cells as $cell) {
                $vals[] = $this->cellText($cell);
            }
            $key = strtolower(trim($vals[0] ?? ''));
            $value = trim($vals[1] ?? '');
            if ($key !== '' && $value !== '') {
                $map[$key] = $value;
            }
        }

        $examCode = strtoupper($map['exam code'] ?? $map['examcode'] ?? $map['code'] ?? '');
        $examCode = preg_replace('/\s+/', '-', $examCode);
        $name = $map['name'] ?? $map['title'] ?? '';
        $durationMinutes = (int) ($map['duration'] ?? $map['duration minutes'] ?? 30);
        $passingGrade = (int) ($map['passing grade'] ?? $map['passing'] ?? 70);
        $generalInstructions = $map['instructions'] ?? $map['general instructions']
            ?? 'Answer every question. Your answers are saved automatically while the timer is running.';

        if ($examCode === '') {
            throw new \RuntimeException("Metadata: 'Exam Code' is required.");
        }
        if ($name === '') {
            throw new \RuntimeException("Metadata: 'Name' is required.");
        }
        if ($durationMinutes < 1) {
            throw new \RuntimeException("Metadata: 'Duration' must be a positive number of minutes.");
        }
        if ($passingGrade < 0 || $passingGrade > 100) {
            throw new \RuntimeException("Metadata: 'Passing Grade' must be 0–100.");
        }

        return [
            'examCode' => $examCode,
            'name' => $name,
            'durationMinutes' => $durationMinutes,
            'passingGrade' => $passingGrade,
            'generalInstructions' => $generalInstructions,
            'examMode' => $this->parseExamMode($map['exam mode'] ?? $map['mode'] ?? null),
            'shuffleQuestions' => $this->parseBool($map['shuffle questions'] ?? $map['shuffle question'] ?? null, false),
            'shuffleOptions' => $this->parseBool($map['shuffle options'] ?? $map['shuffle option'] ?? null, false),
            'language' => 'English',
            'subject' => '',
        ];
    }

    /** @return array list of question rows matching ParsedExamPackage.questions */
    private function readQuestionsSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): array
    {
        $sheet = $spreadsheet->getSheetByName('Questions') ?? $spreadsheet->getSheetByName('questions');
        if (! $sheet) {
            throw new \RuntimeException("Missing 'Questions' sheet in the xlsx file.");
        }

        $highestColumn = $sheet->getHighestDataColumn();
        $headers = [];
        $headerRow = $sheet->getRowIterator(1, 1)->current();
        $hcells = $headerRow->getCellIterator('A', $highestColumn);
        $hcells->setIterateOnlyExistingCells(false);
        foreach ($hcells as $cell) {
            $label = strtolower(trim($this->cellText($cell)));
            if ($label !== '') {
                $headers[$label] = $cell->getColumn();
            }
        }

        $col = function (string ...$candidates) use ($headers): ?string {
            foreach ($candidates as $c) {
                if (isset($headers[$c])) {
                    return $headers[$c];
                }
            }

            return null;
        };

        $colPosition = $col('position', '#', 'no');
        $colType = $col('type');
        $colTopic = $col('topic');
        $colPoints = $col('points');
        $colPrompt = $col('prompt', 'question');
        $colA = $col('option a', 'a');
        $colB = $col('option b', 'b');
        $colC = $col('option c', 'c');
        $colD = $col('option d', 'd');
        $colCorrect = $col('correct answer', 'correct', 'answer');
        $colExplanation = $col('explanation');
        $colMedia = $col('media file', 'media');

        if (! $colType || ! $colPrompt || ! $colCorrect || ! $colExplanation) {
            throw new \RuntimeException('Questions header row must include at least: Type, Prompt, Correct Answer, Explanation.');
        }

        $get = fn ($row, ?string $c) => $c ? trim($this->cellText($sheet->getCell($c . $row))) : '';

        $questions = [];
        $highestRow = $sheet->getHighestDataRow();
        for ($r = 2; $r <= $highestRow; $r++) {
            $prompt = $get($r, $colPrompt);
            if ($prompt === '') {
                continue;
            }
            $typeText = strtolower($get($r, $colType));
            if (! in_array($typeText, self::TYPE_VALUES, true)) {
                throw new \RuntimeException("Row {$r}: unknown type \"{$typeText}\". Use one of " . implode(', ', self::TYPE_VALUES) . '.');
            }
            $type = $typeText;

            $position = $colPosition ? ((int) $get($r, $colPosition) ?: $r - 1) : $r - 1;
            $topic = $colTopic ? ($get($r, $colTopic) ?: 'General') : 'General';
            $points = $colPoints ? ((float) $get($r, $colPoints) ?: 1) : 1;
            $explanationText = $get($r, $colExplanation);
            $mediaFile = $colMedia ? ($get($r, $colMedia) ?: null) : null;

            $options = null;
            $correctAnswer = null;
            if ($type === 'single_choice' || $type === 'multi_select') {
                $optionTexts = [$get($r, $colA), $get($r, $colB), $get($r, $colC), $get($r, $colD)];
                $filled = [];
                foreach ($optionTexts as $idx => $text) {
                    if ($text !== '') {
                        $filled[] = ['id' => chr(ord('A') + $idx), 'text' => $text];
                    }
                }
                if (count($filled) < 2) {
                    throw new \RuntimeException("Row {$r}: choice questions need at least 2 options (fill Option A, B, …).");
                }
                $options = $filled;
                $correctRaw = strtoupper($get($r, $colCorrect));
                if ($correctRaw === '') {
                    throw new \RuntimeException("Row {$r}: Correct Answer is required.");
                }
                if ($type === 'single_choice') {
                    $correctAnswer = $correctRaw;
                } else {
                    $correctAnswer = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $correctRaw))));
                }
            } elseif ($type === 'short_text') {
                $text = $get($r, $colCorrect);
                if ($text === '') {
                    throw new \RuntimeException("Row {$r}: Correct Answer is required.");
                }
                $correctAnswer = $text;
            } else { // numeric / essay
                $numStr = $get($r, $colCorrect);
                if ($type === 'numeric') {
                    if ($numStr === '') {
                        throw new \RuntimeException("Row {$r}: Correct Answer is required.");
                    }
                    if (! is_numeric($numStr)) {
                        throw new \RuntimeException("Row {$r}: Correct Answer must be a number.");
                    }
                    $correctAnswer = (float) $numStr;
                } else {
                    $correctAnswer = '';
                }
            }

            if ($explanationText === '' && $type !== 'essay') {
                throw new \RuntimeException("Row {$r}: Explanation is required.");
            }

            $questions[] = [
                'position' => $position,
                'type' => $type,
                'topic' => $topic,
                'points' => $points,
                'prompt' => $prompt,
                'options' => $options,
                'correctAnswer' => $correctAnswer,
                'explanationText' => $explanationText,
                'mediaFile' => $mediaFile,
            ];
        }

        if (count($questions) === 0) {
            throw new \RuntimeException('No questions found in the Questions sheet.');
        }

        return $questions;
    }

    /**
     * Walk every zip entry with a recognised media extension and key it
     * by basename (so "media/q1.png", "images/q1.png", "q1.png" all match
     * a question's `mediaFile: q1.png`). First-seen basename wins.
     *
     * @return array list of {fileName,type,dataUrl}
     */
    private function readMediaFromZip(ZipArchive $zip): array
    {
        $media = [];
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entry = $stat['name'];
            if (str_ends_with($entry, '/')) {
                continue;
            }
            $basename = basename($entry);
            if ($basename === '') {
                continue;
            }
            $type = $this->detectMediaType($basename);
            if (! $type) {
                continue;
            }
            $lower = strtolower($basename);
            if (isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;

            $bytes = $zip->getFromName($entry);
            if ($bytes === false) {
                continue;
            }
            $dataUrl = 'data:' . $this->mimeFromExtension($basename) . ';base64,' . base64_encode($bytes);
            $media[] = ['fileName' => $basename, 'type' => $type, 'dataUrl' => $dataUrl];
        }

        return $media;
    }

    /**
     * Parse a JSON package: { metadata, questions[], media[] } with media
     * data-URLs already inlined (the AI export shape consumed by the
     * original /api/teacher/exams/import route).
     *
     * @return array{metadata:array,questions:array,media:array}
     */
    private function parseExamPackageJson(string $jsonText): array
    {
        $raw = json_decode($jsonText, true);
        if (! is_array($raw)) {
            throw new \RuntimeException('The JSON file is not valid JSON.');
        }
        $meta = is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [];
        $questionsIn = is_array($raw['questions'] ?? null) ? $raw['questions'] : [];
        $mediaIn = is_array($raw['media'] ?? null) ? $raw['media'] : [];

        $metadata = [
            'examCode' => strtoupper(trim((string) ($meta['examCode'] ?? ''))),
            'name' => trim((string) ($meta['name'] ?? '')),
            'durationMinutes' => (int) ($meta['durationMinutes'] ?? 30),
            'passingGrade' => (int) ($meta['passingGrade'] ?? 70),
            'generalInstructions' => trim((string) ($meta['generalInstructions'] ?? '')),
            'examMode' => ($meta['examMode'] ?? 'strict') === 'try_out' ? 'try_out' : 'strict',
            'shuffleQuestions' => ($meta['shuffleQuestions'] ?? false) === true,
            'shuffleOptions' => ($meta['shuffleOptions'] ?? false) === true,
            'language' => trim((string) ($meta['language'] ?? 'English')) ?: 'English',
            'subject' => trim((string) ($meta['subject'] ?? '')),
        ];

        $questions = [];
        foreach ($questionsIn as $idx => $q) {
            if (! is_array($q)) {
                continue;
            }
            $questions[] = [
                'position' => (int) ($q['position'] ?? ($idx + 1)),
                'type' => (string) ($q['type'] ?? ''),
                'topic' => (string) ($q['topic'] ?? 'General'),
                'points' => $q['points'] ?? 1,
                'prompt' => (string) ($q['prompt'] ?? ''),
                'options' => $q['options'] ?? null,
                'correctAnswer' => $q['correctAnswer'] ?? null,
                'explanationText' => (string) ($q['explanationText'] ?? $q['explanation'] ?? ''),
                'mediaFile' => isset($q['mediaFile']) ? (string) $q['mediaFile'] : null,
            ];
        }

        $media = [];
        foreach ($mediaIn as $m) {
            if (! is_array($m) || empty($m['fileName']) || empty($m['dataUrl'])) {
                continue;
            }
            $media[] = [
                'fileName' => (string) $m['fileName'],
                'type' => $this->detectMediaType((string) $m['fileName']) ?? (string) ($m['type'] ?? 'image'),
                'dataUrl' => (string) $m['dataUrl'],
            ];
        }

        return ['metadata' => $metadata, 'questions' => $questions, 'media' => $media];
    }

    // ---- option / answer normalisers (parity with exam-validation) ---

    private function normalizeQuestionOptions(string $type, mixed $options): ?array
    {
        if ($type !== 'single_choice' && $type !== 'multi_select') {
            return null;
        }
        if (! is_array($options)) {
            return null;
        }

        return array_map(fn ($o) => [
            'id' => strtoupper(trim((string) ($o['id'] ?? ''))),
            'text' => trim((string) ($o['text'] ?? '')),
        ], $options);
    }

    private function normalizeCorrectAnswer(string $type, mixed $raw): mixed
    {
        if ($type === 'single_choice') {
            return is_string($raw) ? strtoupper(trim($raw)) : '';
        }
        if ($type === 'multi_select') {
            if (! is_array($raw)) {
                return [];
            }
            $vals = array_map(fn ($v) => strtoupper(trim((string) $v)), $raw);
            sort($vals);

            return $vals;
        }
        if ($type === 'short_text') {
            return is_string($raw) ? trim($raw) : (string) ($raw ?? '');
        }
        if ($type === 'numeric') {
            return is_numeric($raw) ? (float) $raw : 0.0;
        }

        return $raw;
    }

    // ---- cell / media helpers ---------------------------------------

    private function cellText(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        $value = $cell->getValue();
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_object($value) && method_exists($value, 'getPlainText')) {
            return $value->getPlainText();
        }

        return (string) $value;
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

    private function parseExamMode(?string $value): string
    {
        if (! $value) {
            return 'strict';
        }
        $lower = preg_replace('/[\s_-]+/', '', strtolower(trim($value)));

        return ($lower === 'tryout' || $lower === 'practice') ? 'try_out' : 'strict';
    }

    private function parseBool(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        $lower = strtolower(trim($value));
        if (in_array($lower, ['true', 'yes', 'y', '1', 'on'], true)) {
            return true;
        }
        if (in_array($lower, ['false', 'no', 'n', '0', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
