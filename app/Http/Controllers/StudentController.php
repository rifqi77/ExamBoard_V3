<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StudentController extends Controller
{
    /** GET /student — hub: resume in-progress attempts + recent scores. */
    public function home(Request $request)
    {
        $user = $request->user();
        $now = now();

        // Server-authoritative resumable attempts: the user's own draft
        // sessions for active, in-window, non-expired exams. Metadata only.
        $drafts = ExamSession::with('exam')
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->orderByDesc('created_at')
            ->get();

        $resumable = [];
        foreach ($drafts as $s) {
            $exam = $s->exam;
            if (! $exam || ! $exam->active) {
                continue;
            }
            if ($exam->start_time && $exam->start_time->gt($now)) {
                continue;
            }
            if ($exam->end_time && $exam->end_time->lt($now)) {
                continue;
            }
            $elapsed = $now->getTimestamp() - $s->started_at->getTimestamp();
            $remaining = $exam->duration_minutes * 60 - $elapsed;
            if ($remaining <= 0) {
                continue; // expired — finalizer will sweep it
            }
            $resumable[] = [
                'examId' => $exam->exam_code,
                'examName' => $exam->name,
                'mode' => $exam->exam_mode,
                'timeRemainingSeconds' => $remaining,
                'startedAt' => $s->started_at->toIso8601String(),
                // Resume token — drives the /exams/{code}/resume/{token} URL on
                // the Hub. Null for legacy sessions created before the column
                // existed; the Hub falls back to /exams/{code} (requires the
                // exam-access cookie) when null.
                'resumeToken' => $s->resume_token,
            ];
        }

        $recent = ExamSubmission::with('exam:id,exam_code')
            ->where('user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'examName' => $r->exam_name,
                'percentScore' => $r->percent_score,
                'passed' => (bool) $r->passed,
                'pendingEssayCount' => $r->pending_essay_count,
                'submittedAt' => $r->submitted_at->toIso8601String(),
            ])->all();

        return Inertia::render('student/Hub', [
            'resumable' => $resumable,
            'recentScores' => $recent,
        ]);
    }
}
