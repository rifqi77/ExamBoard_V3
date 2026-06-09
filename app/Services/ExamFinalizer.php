<?php

namespace App\Services;

use App\Models\AnswerDraft;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Models\User;
use App\Support\Scoring;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Convert an expired draft ExamSession into a real ExamSubmission, scoring
 * whatever was in AnswerDraft at the deadline. Idempotent + race-safe.
 * Port of the original exam-finalize.ts.
 */
class ExamFinalizer
{
    /** @return array{submissionId:string,created:bool} */
    public static function finaliseExpiredSession(string $sessionId): array
    {
        $session = ExamSession::with('exam')->find($sessionId);
        if (! $session) {
            throw new RuntimeException('Session not found.');
        }

        // Fast-path: already finalised by another request.
        if ($session->status === 'submitted') {
            $existing = ExamSubmission::where('user_id', $session->user_id)
                ->where('exam_id', $session->exam_id)
                ->where('attempt', $session->attempt)
                ->first();
            if ($existing) {
                return ['submissionId' => $existing->id, 'created' => false];
            }
        }

        $exam = $session->exam;
        $questions = ExamQuestion::where('exam_id', $session->exam_id)
            ->get(['id', 'topic', 'points', 'correct_answer', 'type']);
        $drafts = AnswerDraft::where('session_id', $session->id)->get(['question_id', 'value']);

        $answersSnapshot = [];
        foreach ($drafts as $d) {
            $answersSnapshot[$d->question_id] = $d->value;
        }

        $scoring = Scoring::scoreExam(
            $questions->map(fn ($q) => [
                'id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type,
            ])->all(),
            $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
            $answersSnapshot
        );

        $events = is_array($session->anti_cheat_events) ? $session->anti_cheat_events : [];
        $events[] = [
            'kind' => 'auto_submitted_timeout',
            'at' => now()->toIso8601String(),
            'detail' => "Duration {$exam->duration_minutes}m exceeded",
        ];

        $passed = $scoring['percentScore'] >= $exam->passing_grade;

        try {
            $user = User::find($session->user_id);
            $submission = ExamSubmission::create([
                'exam_id' => $session->exam_id,
                'user_id' => $session->user_id,
                'session_id' => $session->id,
                'attempt' => $session->attempt,
                'username' => $user?->username ?? '',
                'full_name' => $user?->full_name ?? '',
                'exam_name' => $exam->name,
                'exam_mode' => $exam->exam_mode,
                'passing_grade' => $exam->passing_grade,
                'final_score' => $scoring['finalScore'],
                'possible_score' => $scoring['possibleScore'],
                'percent_score' => $scoring['percentScore'],
                'passed' => $passed,
                'pending_essay_count' => $scoring['pendingEssayCount'],
                'topic_breakdown' => $scoring['topicBreakdown'],
                'answers_snapshot' => $answersSnapshot,
                'anti_cheat_events' => $events,
                'submitted_at' => now(), // Explicit; Eloquent doesn't refresh useCurrent() defaults.
            ]);

            ExamSession::where('id', $session->id)
                ->whereIn('status', ['draft', 'expired'])
                ->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'last_saved_at' => now(),
                ]);

            return ['submissionId' => $submission->id, 'created' => true];
        } catch (QueryException $e) {
            // Unique-violation race: another request finalised concurrently.
            if ($e->getCode() === '23000') {
                $existing = ExamSubmission::where('user_id', $session->user_id)
                    ->where('exam_id', $session->exam_id)
                    ->where('attempt', $session->attempt)
                    ->first();
                if ($existing) {
                    return ['submissionId' => $existing->id, 'created' => false];
                }
            }
            throw $e;
        }
    }

    public static function isSessionExpired(
        DateTimeInterface $startedAt,
        int $durationMinutes,
        int $graceSeconds = 60
    ): bool {
        $elapsed = now()->getTimestamp() - $startedAt->getTimestamp();

        return $elapsed > $durationMinutes * 60 + $graceSeconds;
    }
}
