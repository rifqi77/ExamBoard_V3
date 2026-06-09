<?php

namespace App\Http\Controllers;

use App\Models\ExamAccessToken;
use App\Models\ExamSubmission;
use App\Models\ExamTokenRedemption;
use App\Support\ExamAccess;
use App\Support\JwtCookies;
use App\Support\Tokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ExamAccessController extends Controller
{
    public function showTokenEntry(Request $request)
    {
        return Inertia::render('student/TokenEntry');
    }

    public function validateToken(Request $request)
    {
        $user = $request->user();

        $key = 'exam-token:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 15)) {
            throw ValidationException::withMessages(['token' => 'Too many attempts. Try again in a minute.']);
        }
        RateLimiter::hit($key, 60);

        $data = $request->validate([
            'token' => 'required|string|min:6|max:80',
        ]);

        $digest = Tokens::digest($data['token']);
        $now = now();

        $token = ExamAccessToken::with('exam')
            ->where('token_digest', $digest)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->whereHas('exam', function ($q) use ($now) {
                $q->where('active', true)
                    ->where(fn ($q) => $q->whereNull('start_time')->orWhere('start_time', '<=', $now))
                    ->where(fn ($q) => $q->whereNull('end_time')->orWhere('end_time', '>=', $now));
            })
            ->first();

        if (! $token || $token->used_count >= $token->max_uses || ! $token->exam) {
            throw ValidationException::withMessages(['token' => 'Invalid or expired exam token.']);
        }

        // Strict-mode one-attempt enforcement at the door.
        if ($token->exam->exam_mode === 'strict') {
            $existing = ExamSubmission::where('user_id', $user->id)
                ->where('exam_id', $token->exam->id)
                ->first();
            if ($existing) {
                return redirect('/student/scores/' . $existing->id)
                    ->with('error', 'You have already submitted this exam. Only one attempt is allowed.');
            }
        }

        // Record redemption + bump usedCount only if this user hasn't redeemed before.
        // insertOrIgnore is a single idempotent statement (deadlock-safe under load).
        DB::transaction(function () use ($token, $user) {
            $inserted = DB::table('exam_token_redemptions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'token_id' => $token->id,
                'user_id' => $user->id,
                'redeemed_at' => now(),
            ]);
            if ($inserted) {
                ExamAccessToken::where('id', $token->id)->increment('used_count');
            }
        }, 5);

        $jwt = JwtCookies::signExamAccess($user->id, $token->exam->id, $token->id);

        return redirect('/exams/' . $token->exam->exam_code)
            ->withCookie(ExamAccess::cookie($jwt));
    }

    /**
     * GET /exams/{examId}/resume/{resumeToken}
     *
     * Per-attempt resume: lets a student come back to an in-progress draft
     * session without re-redeeming the original access token. Re-issues a
     * fresh 8h exam-access cookie + drops the user back on the exam page
     * with all answers intact.
     *
     * Security: the resume token alone isn't enough — we ALSO verify
     * session.user_id matches the authenticated user, so a leaked token
     * (e.g. copied from a screenshot) is useless to anyone else. Strict-mode
     * already-submitted students are kicked to their score; expired sessions
     * fall through to the regular /token flow.
     */
    public function resume(Request $request, string $examId, string $resumeToken)
    {
        $user = $request->user();

        $session = \App\Models\ExamSession::with('exam')
            ->where('resume_token', $resumeToken)
            ->first();
        if (! $session || ! $session->exam) {
            return redirect('/token')->with('error', 'Resume link is invalid or has expired.');
        }
        if ($session->user_id !== $user->id) {
            // Defense-in-depth: token owner check. Even a leaked URL is inert
            // for anyone but the original student.
            return redirect('/token')->with('error', 'That resume link belongs to a different student.');
        }
        if ($session->status !== 'draft') {
            // Already submitted (or expired-and-finalized). Send them to the
            // results page if a submission exists, else back to the hub.
            $existing = ExamSubmission::where('user_id', $user->id)
                ->where('exam_id', $session->exam_id)
                ->orderByDesc('submitted_at')->first();
            if ($existing) {
                return redirect('/student/scores/' . $existing->id)
                    ->with('error', 'This attempt has already been submitted.');
            }
            return redirect('/student')->with('error', 'This attempt is no longer resumable.');
        }
        // Confirm the examCode in the URL matches the session's exam (avoids
        // cross-exam confusion if a teacher reuses a code).
        if ($session->exam->exam_code !== $examId && $session->exam->id !== $examId) {
            return redirect('/token')->with('error', 'Resume link does not match the requested exam.');
        }

        // Mint a fresh exam-access cookie and bounce to the exam page. The
        // existing ExamController::show() will see the active draft session
        // and pick up exactly where the student left off (server drafts +
        // localStorage merge).
        $jwt = JwtCookies::signExamAccess($user->id, $session->exam->id, $session->token_id ?? $session->id);

        return redirect('/exams/' . $session->exam->exam_code)
            ->withCookie(ExamAccess::cookie($jwt));
    }
}
