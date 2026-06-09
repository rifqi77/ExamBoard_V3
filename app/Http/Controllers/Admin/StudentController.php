<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
 * Admin → Students. Port of the original AdminStudentsClient, adapted to the
 * task's school-wide scope: every student in the system (NO created_by
 * scoping — admin oversees the whole school), grouped by class roster with a
 * trailing "No class" bucket. Per-row inline password reset (reveals the new
 * plaintext + copy), power toggle, and a bulk-action bar.
 *
 *   - GET    /admin/students          (Inertia page, grouped roster)
 *   - GET    /admin/students/groups   (same payload as JSON for in-place refresh)
 *   - PATCH  /admin/students/{uid}    (reset password → returns plaintext / toggle)
 *   - POST   /admin/students/bulk     (deactivate / activate / reset / delete)
 *
 * Resetting a password or deactivating bumps token_version to force-logout.
 */
class StudentController extends Controller
{
    private const BULK_ACTIONS = ['deactivate', 'activate', 'reset', 'delete'];

    private const MAX_BULK_IDS = 1000;

    /** GET /admin/students — Inertia page with the school-wide grouped roster. */
    public function index(Request $request)
    {
        return Inertia::render('admin/Students', [
            'groups' => $this->buildGroups(),
        ]);
    }

    /** GET /admin/students/groups — same payload as JSON for in-place refresh. */
    public function groups(Request $request)
    {
        return response()->json(['groups' => $this->buildGroups()]);
    }

    /**
     * PATCH /admin/students/{uid} — reset password and/or toggle active. Admin
     * may act on ANY student (no ownership check). On reset the new cleartext
     * is echoed back so the row can reveal it without a refetch.
     */
    public function update(Request $request, string $uid)
    {
        $admin = $request->user();

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
            return response()->json(['error' => 'Only student accounts can be reset from this page.'], 400);
        }

        $newPassword = null;
        if ($hasPassword) {
            $newPassword = $request->input('password');
            $this->writeCredential($target->id, $newPassword, $admin->id);
            $target->increment('token_version');
        }
        if ($hasActive) {
            $nextActive = (bool) $request->input('active');
            $target->active = $nextActive;
            if ($nextActive === false) {
                $target->token_version = (int) $target->token_version + 1;
            }
            $target->save();
        }

        return response()->json([
            'ok' => true,
            'passwordPlain' => $newPassword,
        ]);
    }

    /**
     * POST /admin/students/bulk — one action over many students at once.
     *   action: deactivate | activate | reset | delete
     *   reset returns credentials[] (new plaintext) to hand out.
     * Admin scope: no ownership filter — any student id is fair game. Ids that
     * don't resolve to a student are silently dropped (counted as skipped).
     */
    public function bulk(Request $request)
    {
        $admin = $request->user();

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

        // School-wide: students only, but no created_by restriction.
        $allowed = User::whereIn('id', $ids)->where('role', 'student')->get();
        $allowedIds = $allowed->pluck('id')->all();
        $skipped = count($ids) - count($allowedIds);

        if (count($allowedIds) === 0) {
            return response()->json(['error' => 'None of the selected ids are student accounts.'], 400);
        }

        if ($action === 'deactivate' || $action === 'activate') {
            $nextActive = $action === 'activate';
            User::whereIn('id', $allowedIds)->update([
                'active' => $nextActive,
                ...($nextActive ? [] : ['token_version' => DB::raw('token_version + 1')]),
            ]);

            return response()->json(['action' => $action, 'updated' => count($allowedIds), 'skipped' => $skipped]);
        }

        if ($action === 'delete') {
            $deleted = User::whereIn('id', $allowedIds)->delete();

            return response()->json(['action' => $action, 'deleted' => $deleted, 'skipped' => $skipped]);
        }

        // reset — explicit password for all when supplied, else memorable
        // per-student <nickname><year>. New plaintext comes back in credentials[].
        $credentials = [];
        foreach ($allowed as $target) {
            $password = $explicitPassword ?? StudentCredentials::generatePasswordFromName($target->full_name);
            $this->writeCredential($target->id, $password, $admin->id);
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
     * Per-student summary keyed by user id, SCHOOL-WIDE (every student row).
     * Includes submission count, last submission, and the decrypted plaintext
     * (or a pattern-derived fallback for rows imported before passwordPlain).
     *
     * @return array<string,array>
     */
    private function summaries(): array
    {
        $students = User::query()
            ->where('role', 'student')
            ->withCount('submissions as total_submissions')
            ->with(['credential:user_id,password_plain'])
            ->orderBy('full_name')
            ->get();

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
     * School-wide grouped roster: every class (any owner) with its linked
     * students, plus a trailing "No class" bucket for students not in any
     * class. Same shape the teacher Students page consumes so the year filter,
     * collapse, and bulk bar all work unchanged.
     */
    private function buildGroups(): array
    {
        $summaries = $this->summaries();

        $classes = StudentClass::query()
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
