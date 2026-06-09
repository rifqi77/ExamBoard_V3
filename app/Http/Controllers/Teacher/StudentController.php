<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\ClassStudent;
use App\Models\ExamSubmission;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCredential;
use App\Support\CryptoSecrets;
use App\Support\StudentCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

/**
 * Teacher → Students. Port of the original Next.js routes:
 *   - GET    /api/teacher/students            (grouped roster)
 *   - POST   /api/teacher/students            (create one)
 *   - PATCH  /api/teacher/students/[uid]      (reset password / toggle active)
 *   - DELETE /api/teacher/students/[uid]      (delete)
 *   - POST   /api/teacher/students/bulk       (reset/activate/deactivate/delete)
 *   + bulk-create from a pasted roster (uses StudentCredentials generators)
 *
 * Every row is scoped to the signed-in teacher via created_by = $user->id.
 * Plaintext passwords are stored AES-GCM-encrypted (CryptoSecrets) and
 * decrypted for teacher display; the bcrypt hash is the real credential.
 */
class StudentController extends Controller
{
    private const USERNAME_RE = '/^[a-zA-Z0-9._-]{3,32}$/';

    private const BULK_ACTIONS = ['deactivate', 'activate', 'reset', 'delete'];

    private const MAX_BULK_IDS = 1000;

    /** GET /teacher/students — Inertia page with the grouped roster. */
    public function index(Request $request)
    {
        $user = $request->user();

        return Inertia::render('teacher/Students', [
            'groups' => $this->buildGroups($user->id),
        ]);
    }

    /**
     * GET /teacher/students/groups — same payload as index(), but as JSON so
     * the page can refresh in place after a mutation without a full reload.
     */
    public function groups(Request $request)
    {
        $user = $request->user();

        return response()->json(['groups' => $this->buildGroups($user->id)]);
    }

    /**
     * POST /teacher/students — create one student owned by the teacher.
     * Returns the new summary including the cleartext password (shown once).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $username = is_string($request->input('username')) ? trim($request->input('username')) : '';
        $fullName = is_string($request->input('fullName')) ? trim($request->input('fullName')) : '';
        $password = is_string($request->input('password')) ? $request->input('password') : '';

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
            $created = DB::transaction(function () use ($username, $fullName, $password, $user) {
                $u = User::create([
                    'username' => $username,
                    'full_name' => $fullName,
                    'role' => 'student',
                    'active' => true,
                    'subject' => null,
                    'created_by' => $user->id,
                ]);
                UserCredential::create([
                    'user_id' => $u->id,
                    'password_hash' => Hash::make($password),
                    'password_plain' => CryptoSecrets::encryptStudentPassword($password),
                    'password_set_by' => $user->id,
                ]);

                return $u;
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Create failed.'], 500);
        }

        return response()->json([
            'student' => [
                'userId' => $created->id,
                'username' => $created->username,
                'fullName' => $created->full_name,
                'active' => true,
                'totalSubmissions' => 0,
                'lastSubmissionAt' => null,
                'passwordPlain' => $password,
            ],
        ]);
    }

    /**
     * PATCH /teacher/students/{uid} — reset password and/or toggle active.
     * Both fields are independent. On password reset the new cleartext is
     * returned so the teacher can reveal it inline.
     */
    public function update(Request $request, string $uid)
    {
        $user = $request->user();

        $hasPassword = is_string($request->input('password')) && $request->input('password') !== '';
        $hasActive = is_bool($request->input('active'));

        if (! $hasPassword && ! $hasActive) {
            return response()->json(['error' => 'Provide password and/or active.'], 400);
        }
        if ($hasPassword) {
            $password = $request->input('password');
            if (strlen($password) < 6 || strlen($password) > 64) {
                return response()->json(['error' => 'Password must be 6-64 characters.'], 400);
            }
        }

        $target = User::find($uid);
        if (! $target) {
            return response()->json(['error' => 'Student not found.'], 404);
        }
        if ($target->role !== 'student') {
            return response()->json(['error' => 'Only student accounts can be reset from this endpoint.'], 400);
        }
        if ($target->created_by !== $user->id) {
            return response()->json(['error' => 'You can only reset students you created.'], 403);
        }

        $newPassword = null;
        if ($hasPassword) {
            $newPassword = $request->input('password');
            $this->writeCredential($target->id, $newPassword, $user->id);
            // Invalidate any JWT issued before this reset.
            $target->increment('token_version');
        }
        if ($hasActive) {
            $nextActive = (bool) $request->input('active');
            $target->active = $nextActive;
            if ($nextActive === false) {
                // Deactivating force-logs-out the student immediately.
                $target->token_version = (int) $target->token_version + 1;
            }
            $target->save();
        }

        return response()->json([
            'ok' => true,
            // Echo the cleartext so the row can reveal it without a refetch.
            'passwordPlain' => $newPassword,
        ]);
    }

    /**
     * DELETE /teacher/students/{uid} — delete a student the teacher created.
     * Submissions / drafts / sessions cascade at the DB level.
     */
    public function destroy(Request $request, string $uid)
    {
        $user = $request->user();

        $target = User::find($uid);
        if (! $target) {
            return response()->json(['error' => 'Student not found.'], 404);
        }
        if ($target->role !== 'student') {
            return response()->json(['error' => 'Only student accounts can be deleted from this endpoint.'], 400);
        }
        if ($target->created_by !== $user->id) {
            return response()->json(['error' => 'You can only delete students you created.'], 403);
        }

        $target->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /teacher/students/bulk — bulk operations over selected userIds.
     *   action: deactivate | activate | reset | delete
     *   reset returns the new credentials[] so they can be handed out.
     * Ids that aren't the teacher's students are silently dropped (skipped).
     */
    public function bulk(Request $request)
    {
        $user = $request->user();

        $action = $request->input('action');
        if (! is_string($action) || ! in_array($action, self::BULK_ACTIONS, true)) {
            return response()->json(['error' => 'Unknown action.'], 400);
        }

        $rawIds = $request->input('userIds');
        $ids = is_array($rawIds)
            ? array_values(array_unique(array_filter($rawIds, fn ($x) => is_string($x) && $x !== '')))
            : [];
        if (count($ids) === 0) {
            return response()->json(['error' => 'No students selected.'], 400);
        }
        if (count($ids) > self::MAX_BULK_IDS) {
            return response()->json(['error' => 'Too many at once (max '.self::MAX_BULK_IDS.'). Narrow your selection.'], 400);
        }

        $explicitPassword = is_string($request->input('password')) && $request->input('password') !== ''
            ? $request->input('password')
            : null;
        if ($explicitPassword !== null && (strlen($explicitPassword) < 6 || strlen($explicitPassword) > 64)) {
            return response()->json(['error' => 'Password must be 6-64 characters.'], 400);
        }

        $allowed = User::whereIn('id', $ids)
            ->where('role', 'student')
            ->where('created_by', $user->id)
            ->get();
        $allowedIds = $allowed->pluck('id')->all();
        $skipped = count($ids) - count($allowedIds);

        if (count($allowedIds) === 0) {
            return response()->json(['error' => 'None of the selected students are yours to manage.'], 403);
        }

        if ($action === 'deactivate' || $action === 'activate') {
            $nextActive = $action === 'activate';
            User::whereIn('id', $allowedIds)->update([
                'active' => $nextActive,
                // Deactivating force-logs-out immediately; reactivating leaves
                // the (already-invalid) token version as-is.
                ...($nextActive ? [] : ['token_version' => DB::raw('token_version + 1')]),
            ]);

            return response()->json(['action' => $action, 'updated' => count($allowedIds), 'skipped' => $skipped]);
        }

        if ($action === 'delete') {
            $deleted = User::whereIn('id', $allowedIds)->delete();

            return response()->json(['action' => $action, 'deleted' => $deleted, 'skipped' => $skipped]);
        }

        // reset — re-hash each, using the explicit password for all of them
        // when supplied, otherwise a memorable per-student <nickname><year>.
        $credentials = [];
        foreach ($allowed as $target) {
            $password = $explicitPassword ?? StudentCredentials::generatePasswordFromName($target->full_name);
            $this->writeCredential($target->id, $password, $user->id);
            $target->increment('token_version');
            $credentials[] = [
                'userId' => $target->id,
                'username' => $target->username,
                'fullName' => $target->full_name,
                'password' => $password,
            ];
        }

        return response()->json([
            'action' => $action,
            'reset' => count($credentials),
            'credentials' => $credentials,
            'skipped' => $skipped,
        ]);
    }

    /**
     * POST /teacher/students/bulk-create — create many students from a pasted
     * roster. Each line is "Full Name" (optionally "Full Name, username,
     * password" — extra columns split on comma/tab). Blank username/password
     * are auto-generated via StudentCredentials. Optionally links the new
     * students to an existing class the teacher owns (classId). Returns the
     * created credentials[] (with source: generated|provided) and skipped[].
     */
    public function bulkCreate(Request $request)
    {
        $user = $request->user();

        $roster = is_string($request->input('roster')) ? $request->input('roster') : '';
        $lines = preg_split('/\r\n|\r|\n/', $roster) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
        if (count($lines) === 0) {
            return response()->json(['error' => 'Paste at least one student (one per line).'], 400);
        }
        if (count($lines) > self::MAX_BULK_IDS) {
            return response()->json(['error' => 'Too many at once (max '.self::MAX_BULK_IDS.').'], 400);
        }

        // Optional target class — must belong to the teacher.
        $classId = is_string($request->input('classId')) && $request->input('classId') !== ''
            ? $request->input('classId')
            : null;
        $class = null;
        if ($classId !== null) {
            $class = StudentClass::where('id', $classId)->where('created_by', $user->id)->first();
            if (! $class) {
                return response()->json(['error' => 'Selected class not found.'], 400);
            }
        }

        $taken = User::pluck('username')->map(fn ($u) => strtolower($u))->all();
        $created = [];
        $skipped = [];

        foreach ($lines as $line) {
            $cols = array_map('trim', preg_split('/\t|,/', $line) ?: []);
            $fullName = $cols[0] ?? '';
            if ($fullName === '' || mb_strlen($fullName) < 2 || mb_strlen($fullName) > 80) {
                $skipped[] = ['reason' => 'Full name missing or invalid', 'identifier' => $line];

                continue;
            }

            $username = $cols[1] ?? '';
            if ($username === '') {
                $username = StudentCredentials::generateUsernameFromName($fullName, $taken);
            }
            if (in_array(strtolower($username), $taken, true)) {
                $skipped[] = ['reason' => 'Username already exists', 'identifier' => $username];

                continue;
            }
            if (! preg_match(self::USERNAME_RE, $username)) {
                $skipped[] = ['reason' => 'Invalid username format', 'identifier' => $username];

                continue;
            }

            $provided = $cols[2] ?? '';
            $passwordWasGenerated = $provided === '';
            $password = $passwordWasGenerated
                ? StudentCredentials::generatePasswordFromName($fullName)
                : $provided;
            if (strlen($password) < 6 || strlen($password) > 64) {
                $skipped[] = ['reason' => 'Password length must be 6-64', 'identifier' => $username];

                continue;
            }

            try {
                DB::transaction(function () use ($username, $fullName, $password, $user, $class) {
                    $u = User::create([
                        'username' => $username,
                        'full_name' => $fullName,
                        'role' => 'student',
                        'active' => true,
                        'created_by' => $user->id,
                    ]);
                    UserCredential::create([
                        'user_id' => $u->id,
                        'password_hash' => Hash::make($password),
                        'password_plain' => CryptoSecrets::encryptStudentPassword($password),
                        'password_set_by' => $user->id,
                    ]);
                    if ($class) {
                        ClassStudent::create([
                            'class_id' => $class->id,
                            'student_identifier' => $u->id,
                            'student_name' => $fullName,
                        ]);
                    }
                });
            } catch (\Throwable $e) {
                $skipped[] = ['reason' => 'Insert failed', 'identifier' => $username];

                continue;
            }

            $taken[] = strtolower($username);
            $created[] = [
                'className' => $class?->name ?? 'No class',
                'fullName' => $fullName,
                'username' => $username,
                'password' => $password,
                'passwordWasGenerated' => $passwordWasGenerated,
            ];
        }

        return response()->json([
            'studentsCreated' => count($created),
            'createdStudents' => $created,
            'studentsSkipped' => $skipped,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** Upsert a credential row with a freshly hashed + encrypted password. */
    private function writeCredential(string $userId, string $password, string $actorId): void
    {
        UserCredential::updateOrCreate(
            ['user_id' => $userId],
            [
                'password_hash' => Hash::make($password),
                'password_plain' => CryptoSecrets::encryptStudentPassword($password),
                'password_set_by' => $actorId,
                'password_set_at' => now(),
                'failed_attempts' => 0,
                'locked_until' => null,
            ]
        );
    }

    /**
     * Per-student summary keyed by user id, scoped to one teacher. Includes
     * submission count, last submission, and the decrypted plaintext (or a
     * pattern-derived fallback for rows imported before passwordPlain existed).
     */
    private function summariesFor(string $teacherId): array
    {
        $students = User::query()
            ->where('role', 'student')
            ->where('created_by', $teacherId)
            ->withCount('submissions as total_submissions')
            ->with(['credential:user_id,password_plain'])
            ->orderBy('full_name')
            ->get();

        // Latest submission per student in one grouped query.
        $lastByUser = ExamSubmission::query()
            ->whereIn('user_id', $students->pluck('id'))
            ->selectRaw('user_id, MAX(submitted_at) as last_submitted_at')
            ->groupBy('user_id')
            ->pluck('last_submitted_at', 'user_id');

        $summaries = [];
        foreach ($students as $s) {
            $rawStored = $s->credential?->password_plain;
            $stored = $rawStored ? CryptoSecrets::decryptStudentPassword($rawStored) : null;
            $derived = $stored ?? $this->derivePasswordFromUsername($s->username, $s->created_at);

            $last = $lastByUser->get($s->id);

            $summaries[$s->id] = [
                'userId' => $s->id,
                'username' => $s->username,
                'fullName' => $s->full_name,
                'active' => (bool) $s->active,
                'totalSubmissions' => (int) $s->total_submissions,
                'lastSubmissionAt' => $last ? Carbon::parse($last)->toIso8601String() : null,
                'passwordPlain' => $derived,
            ];
        }

        return $summaries;
    }

    /**
     * Build the grouped roster: every class the teacher owns (with its linked
     * students) plus a trailing "No class" bucket for owned students not in
     * any class. Mirrors the original GET /api/teacher/students shape.
     */
    private function buildGroups(string $teacherId): array
    {
        $summaries = $this->summariesFor($teacherId);

        $classes = StudentClass::query()
            ->where('created_by', $teacherId)
            ->with(['students' => fn ($q) => $q->orderBy('student_name')])
            ->orderBy('name')
            ->get();

        $placed = [];
        $groups = [];

        foreach ($classes as $cls) {
            $seen = [];
            $classStudents = [];
            foreach ($cls->students as $link) {
                $summary = $summaries[$link->student_identifier] ?? null;
                if (! $summary || isset($seen[$summary['userId']])) {
                    continue;
                }
                $seen[$summary['userId']] = true;
                $placed[$summary['userId']] = true;
                $classStudents[] = $summary;
            }
            $groups[] = [
                'classId' => $cls->id,
                'className' => $cls->name,
                'academicYear' => $cls->academic_year,
                'studentCount' => count($classStudents),
                'sourceFileName' => $cls->source_file_name,
                'students' => $classStudents,
            ];
        }

        // "No class" bucket — only emitted when there are orphan students.
        $orphans = [];
        foreach ($summaries as $userId => $summary) {
            if (! isset($placed[$userId])) {
                $orphans[] = $summary;
            }
        }
        if (count($orphans) > 0) {
            $groups[] = [
                'classId' => null,
                'className' => 'No class',
                'academicYear' => null,
                'studentCount' => count($orphans),
                'sourceFileName' => null,
                'students' => $orphans,
            ];
        }

        return $groups;
    }

    /**
     * Fallback when plaintext wasn't captured: usernames matching the
     * generator pattern "<nickname><3-digit>" imply password "<nickname><year>"
     * (the year the row was created). Returns null when it can't be derived.
     */
    private function derivePasswordFromUsername(string $username, $createdAt): ?string
    {
        if (! preg_match('/^([a-z]+)\d{3}$/', $username, $m)) {
            return null;
        }
        $nickname = $m[1];
        if (strlen($nickname) < 2) {
            return null;
        }
        $year = $createdAt ? Carbon::parse($createdAt)->format('Y') : date('Y');

        return $nickname.$year;
    }
}
