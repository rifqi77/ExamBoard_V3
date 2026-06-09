<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeacherController extends Controller
{
    /** GET /teacher — overview dashboard. */
    public function dashboard(Request $request)
    {
        $user = $request->user();

        $exams = Exam::where('created_by', $user->id)
            ->withCount([
                'submissions as total_submissions',
                'submissions as passed_count' => fn ($q) => $q->where('passed', true),
            ])
            ->get();

        $examIds = $exams->pluck('id');
        $totalSubmissions = (int) $exams->sum('total_submissions');
        $totalPassed = (int) $exams->sum('passed_count');

        $recent = ExamSubmission::whereIn('exam_id', $examIds)
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
                'pendingEssayCount' => $s->pending_essay_count,
                'gradingStatus' => $s->pending_essay_count > 0 ? 'pending_grading' : 'graded',
                'submittedAt' => $s->submitted_at->toIso8601String(),
            ]);

        $pendingGrading = $recent->where('gradingStatus', 'pending_grading')->count();
        $studentCount = User::where('role', 'student')->count();

        return Inertia::render('teacher/Dashboard', [
            'metrics' => [
                'exams' => $exams->count(),
                'totalSubmissions' => $totalSubmissions,
                'totalPassed' => $totalPassed,
                'pendingGrading' => $pendingGrading,
                'students' => $studentCount,
            ],
            'recentSubmissions' => $recent->values(),
        ]);
    }
}
