<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\User;
use App\Models\UserCredential;
use App\Support\Capabilities;
use App\Support\CryptoSecrets;
use App\Support\Subjects;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

/**
 * Admin → Teachers (+ capabilities). Port of the original Next.js routes:
 *   - GET    /api/admin/teachers                       (list + per-teacher counts)
 *   - POST   /api/admin/teachers                       (create one)
 *   - PATCH  /api/admin/teachers/[uid]                 (rename / reset pw / toggle)
 *   - DELETE /api/admin/teachers/[uid]                 (delete, data orphaned)
 *   - GET    /api/admin/teachers/[uid]/capabilities    (folded into the list)
 *   - PATCH  /api/admin/teachers/[uid]/capabilities    (replace map)
 *
 * Admin sees every teacher (no created_by scoping). Resetting a password
 * returns the new plaintext so the row can reveal it inline; deactivating or
 * resetting bumps token_version to force-logout existing sessions.
 */
class TeacherController extends Controller
{
    private const USERNAME_RE = '/^[a-zA-Z0-9._-]{3,32}$/';

    /** GET /admin/teachers — Inertia page with every teacher + activity counts. */
    public function index(Request $request)
    {
        // JSON refresh after a mutation (mirrors the teacher Students page).
        if ($request->wantsJson() || $request->boolean('json')) {
            return response()->json(['teachers' => $this->summaries()]);
        }

        return Inertia::render('admin/Teachers', [
            'teachers' => $this->summaries(),
            // Drives the bilingual SubjectPicker in the add-teacher form.
            'subjects' => Subjects::mergeWithExisting($this->subjectsInUse()),
            // Drives the capabilities editor (groups → entries, declaration order).
            'capabilityGroups' => Capabilities::grouped(),
            'capabilityKeys' => Capabilities::KEYS,
            'capabilitySubgroupLabels' => Capabilities::SUBGROUP_LABELS,
        ]);
    }

    /**
     * POST /admin/teachers — create a teacher + credential. Every capability
     * starts disabled; the admin grants each one explicitly afterwards.
     */
    public function store(Request $request)
    {
        $admin = $request->user();

        $username = is_string($request->input('username')) ? trim($request->input('username')) : '';
        $fullName = is_string($request->input('fullName')) ? trim($request->input('fullName')) : '';
        $password = is_string($request->input('password')) ? $request->input('password') : '';
        $subjectRaw = is_string($request->input('subject')) ? trim($request->input('subject')) : '';
        $subject = $subjectRaw !== '' ? mb_substr(Subjects::canonical($subjectRaw), 0, 60) : null;

        if (! preg_match(self::USERNAME_RE, $username)) {
            return response()->json([
                'error' => 'Username must be 3-32 characters: letters, digits, dots, dashes, underscores.',
            ], 400);
        }
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 80) {
            return response()->json(['error' => 'Full name must be 2-80 characters.'], 400);
        }
        if (strlen($password) < 6 || strlen($password) > 64) {
            return response()->json(['error' => 'Password must be 6-64 characters.'], 400);
        }

        if (User::whereRaw('LOWER(username) = ?', [strtolower($username)])->exists()) {
            return response()->json(['error' => "Username \"{$username}\" is already in use."], 409);
        }

        try {
            $created = DB::transaction(function () use ($username, $fullName, $password, $subject, $admin) {
                $u = User::create([
                    'username' => $username,
                    'full_name' => $fullName,
                    'role' => 'teacher',
                    'active' => true,
                    'subject' => $subject,
                    'capabilities' => [],
                    'created_by' => $admin->id,
                ]);
                UserCredential::create([
                    'user_id' => $u->id,
                    // 12 rounds — at least as strong as student accounts.
                    'password_hash' => Hash::make($password),
                    'password_set_by' => $admin->id,
                ]);

                return $u;
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to create teacher.'], 500);
        }

        return response()->json([
            'teacher' => [
                'userId' => $created->id,
                'username' => $created->username,
                'fullName' => $created->full_name,
                'subject' => $created->subject,
                'active' => true,
                'examCount' => 0,
                'studentCount' => 0,
                'bankQuestionCount' => 0,
                'submissionCount' => 0,
            ],
        ]);
    }

    /**
     * PATCH /admin/teachers/{uid} — any subset of:
     *   - fullName / subject  (rename / re-tag)
     *   - password            (reset; echoes the new plaintext back)
     *   - active              (enable / disable)
     * Password reset and deactivation each bump token_version so outstanding
     * JWTs are rejected immediately.
     */
    public function update(Request $request, string $uid)
    {
        $admin = $request->user();

        $hasFullName = is_string($request->input('fullName')) && trim($request->input('fullName')) !== '';
        $hasSubject = $request->has('subject');
        $hasPassword = is_string($request->input('password')) && $request->input('password') !== '';
        $hasActive = is_bool($request->input('active'));

        if (! $hasFullName && ! $hasSubject && ! $hasPassword && ! $hasActive) {
            return response()->json(['error' => 'Provide fullName, subject, password, and/or active.'], 400);
        }

        $fullName = null;
        if ($hasFullName) {
            $fullName = trim($request->input('fullName'));
            if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 80) {
                return response()->json(['error' => 'Full name must be 2-80 characters.'], 400);
            }
        }
        if ($hasPassword) {
            $password = $request->input('password');
            if (strlen($password) < 6 || strlen($password) > 64) {
                return response()->json(['error' => 'Password must be 6-64 characters.'], 400);
            }
        }

        $target = User::find($uid);
        if (! $target) {
            return response()->json(['error' => 'Teacher not found.'], 404);
        }
        if ($target->role !== 'teacher') {
            return response()->json(['error' => 'Only teacher accounts can be modified from this page.'], 400);
        }

        $newPassword = null;
        if ($hasPassword) {
            $newPassword = $request->input('password');
            UserCredential::updateOrCreate(
                ['user_id' => $target->id],
                [
                    'password_hash' => Hash::make($newPassword),
                    'password_set_by' => $admin->id,
                    'password_set_at' => now(),
                    'failed_attempts' => 0,
                    'locked_until' => null,
                ]
            );
            // Invalidate any JWT issued before this reset.
            $target->increment('token_version');
        }

        if ($hasFullName) {
            $target->full_name = $fullName;
        }
        if ($hasSubject) {
            $subjectRaw = is_string($request->input('subject')) ? trim($request->input('subject')) : '';
            $target->subject = $subjectRaw !== '' ? mb_substr(Subjects::canonical($subjectRaw), 0, 60) : null;
        }
        if ($hasActive) {
            $nextActive = (bool) $request->input('active');
            $target->active = $nextActive;
            if ($nextActive === false) {
                // Deactivation force-logs-out immediately.
                $target->token_version = (int) $target->token_version + 1;
            }
        }
        $target->save();

        return response()->json([
            'ok' => true,
            // Echo the cleartext so the row can reveal it without a refetch.
            'passwordPlain' => $newPassword,
        ]);
    }

    /**
     * DELETE /admin/teachers/{uid} — remove a teacher. Their credential is
     * cascaded; students / exams / bank / classes / tokens they created stay
     * (createdBy → NULL, orphaned) so no exam data is lost. The signed-in
     * admin can't delete the account they are using.
     */
    public function destroy(Request $request, string $uid)
    {
        $admin = $request->user();

        if ($uid === $admin->id) {
            return response()->json([
                'error' => 'You cannot delete the account you are currently signed in as.',
            ], 400);
        }

        $target = User::find($uid);
        if (! $target) {
            return response()->json(['error' => 'Teacher not found.'], 404);
        }
        if ($target->role !== 'teacher') {
            return response()->json(['error' => 'Only teacher accounts can be deleted from this page.'], 400);
        }

        $target->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /admin/teachers/{uid}/capabilities — replace the entire capability
     * map. Every key is validated against the registry (booleans only here);
     * unknown keys / non-booleans are rejected so a UI typo can't corrupt the
     * stored map. Returns the fully-populated map back.
     */
    public function updateCapabilities(Request $request, string $uid)
    {
        $raw = $request->input('capabilities');
        // Accept an object of key → boolean. PHP decodes JSON {} to [], so an
        // empty array is fine; only a non-empty list (JSON array) is invalid.
        if (! is_array($raw) || (count($raw) > 0 && array_is_list($raw))) {
            return response()->json(['error' => '`capabilities` must be an object of key → boolean.'], 400);
        }

        $cleaned = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! Capabilities::isValidKey($key)) {
                return response()->json(['error' => "Unknown capability key: \"{$key}\"."], 400);
            }
            if (! is_bool($value)) {
                return response()->json(['error' => "Value for \"{$key}\" must be a boolean."], 400);
            }
            $cleaned[$key] = $value;
        }

        $target = User::find($uid);
        if (! $target) {
            return response()->json(['error' => 'Teacher not found.'], 404);
        }
        if ($target->role !== 'teacher') {
            return response()->json(['error' => 'Only teacher accounts have capability toggles.'], 400);
        }

        $target->capabilities = $cleaned;
        $target->save();

        return response()->json(['capabilities' => Capabilities::fill($cleaned)]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every teacher with per-teacher activity counts (exams, students, bank
     * questions, submissions) and the fully-populated capability map. Mirrors
     * GET /api/admin/teachers, ordered alphabetically by full_name.
     */
    private function summaries(): array
    {
        $teachers = User::query()
            ->where('role', 'teacher')
            ->withCount([
                'createdExams as exam_count',
                'createdUsers as student_count' => fn ($q) => $q->where('role', 'student'),
            ])
            ->orderBy('full_name')
            ->get();

        $teacherIds = $teachers->pluck('id');

        // Submission counts per owner: sum each owned exam's submission count.
        $submissionCountByOwner = Exam::query()
            ->whereIn('created_by', $teacherIds)
            ->withCount('submissions as submission_count')
            ->get()
            ->groupBy('created_by')
            ->map(fn ($exams) => (int) $exams->sum('submission_count'));

        // Bank questions per owner (User has no createdBankQs relation; group
        // BankQuestion by created_by directly).
        $bankCountByOwner = BankQuestion::query()
            ->whereIn('created_by', $teacherIds)
            ->selectRaw('created_by, COUNT(*) as c')
            ->groupBy('created_by')
            ->pluck('c', 'created_by');

        return $teachers->map(fn ($t) => [
            'userId' => $t->id,
            'username' => $t->username,
            'fullName' => $t->full_name,
            'subject' => $t->subject,
            'active' => (bool) $t->active,
            'examCount' => (int) $t->exam_count,
            'studentCount' => (int) $t->student_count,
            'bankQuestionCount' => (int) $bankCountByOwner->get($t->id, 0),
            'submissionCount' => $submissionCountByOwner->get($t->id, 0),
            'capabilities' => Capabilities::fill($t->capabilities),
        ])->values()->all();
    }

    /** Distinct non-empty subjects already used by teachers (for the picker). */
    private function subjectsInUse(): array
    {
        return User::query()
            ->where('role', 'teacher')
            ->whereNotNull('subject')
            ->where('subject', '!=', '')
            ->distinct()
            ->pluck('subject')
            ->all();
    }
}
