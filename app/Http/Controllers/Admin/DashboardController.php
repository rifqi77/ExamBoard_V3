<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankQuestion;
use App\Models\ExamAccessToken;
use App\Models\ExamSubmission;
use App\Models\StudentClass;
use App\Models\User;
use App\Support\CryptoSecrets;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admin → Overview. Port of the original Next.js pages/routes:
 *   - src/app/admin/page.tsx + AdminDashboardClient.tsx
 *   - GET /api/admin/overview-stats   (school-wide counts)
 *   - GET /api/admin/dashboard        (recent activity source — we surface the
 *     three feeds the overview shows: recent access tokens, recent classes,
 *     recent submissions)
 *
 * Admin sees ALL data — no created_by scoping anywhere on this page.
 */
class DashboardController extends Controller
{
    /** GET /admin — school-wide metric cards + recent activity feeds. */
    public function index(Request $request)
    {
        return Inertia::render('admin/Dashboard', [
            'metrics' => $this->metrics(),
            'recent' => [
                'tokens' => $this->recentTokens(),
                'classes' => $this->recentClasses(),
                'submissions' => $this->recentSubmissions(),
            ],
        ]);
    }

    // ---------------------------------------------------------------- metrics

    /**
     * Counts across every account, mirroring overview-stats/route.ts:
     * teachers, active teachers, students, exams, submissions, bank questions.
     */
    private function metrics(): array
    {
        return [
            'teacherCount' => User::where('role', 'teacher')->count(),
            'activeTeacherCount' => User::where('role', 'teacher')->where('active', true)->count(),
            'studentCount' => User::where('role', 'student')->count(),
            'examCount' => \App\Models\Exam::count(),
            'submissionCount' => ExamSubmission::count(),
            'bankQuestionCount' => BankQuestion::count(),
        ];
    }

    // ------------------------------------------------------------ recent feeds

    /** Latest 10 exam access tokens (decrypted preview), with exam + class. */
    private function recentTokens(): array
    {
        return ExamAccessToken::query()
            ->with(['exam:id,exam_code,name', 'studentClass:id,name'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                // Decrypt for the admin dashboard; legacy plaintext rows pass through.
                'token' => CryptoSecrets::decryptTokenPreview($t->token_preview) ?? $t->token_preview,
                'examId' => $t->exam?->exam_code,
                'examName' => $t->exam?->name,
                'className' => $t->studentClass?->name,
                'maxUses' => (int) $t->max_uses,
                'usedCount' => (int) $t->used_count,
                'active' => (bool) $t->active,
                'expiresAt' => $t->expires_at?->toIso8601String(),
                'createdAt' => $t->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** Latest 10 class rosters with their student counts. */
    private function recentClasses(): array
    {
        return StudentClass::query()
            ->withCount('students as student_count')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'academicYear' => $c->academic_year,
                'studentCount' => (int) $c->student_count,
                'sourceFileName' => $c->source_file_name,
                'createdAt' => $c->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** Latest 10 submissions across every exam (denormalized columns). */
    private function recentSubmissions(): array
    {
        return ExamSubmission::query()
            ->orderByDesc('submitted_at')
            ->limit(10)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'studentName' => $s->full_name,
                'username' => $s->username,
                'examName' => $s->exam_name,
                'finalScore' => $s->final_score,
                'possibleScore' => $s->possible_score,
                'percentScore' => $s->percent_score,
                'passed' => (bool) $s->passed,
                'pendingEssayCount' => (int) $s->pending_essay_count,
                'gradingStatus' => $s->pending_essay_count > 0 ? 'pending_grading' : 'graded',
                'submittedAt' => $s->submitted_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
