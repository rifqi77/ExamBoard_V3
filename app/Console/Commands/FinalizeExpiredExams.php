<?php

namespace App\Console\Commands;

use App\Models\ExamSession;
use App\Services\ExamFinalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sweep draft exam sessions that are past their duration (with grace) and
 * convert them into scored ExamSubmissions. This is the safety net for
 * Case 1: a student whose time runs out without ever clicking Submit and
 * who never re-opens the page — without this command, their draft answers
 * stay as `draft` forever and never get scored. Idempotent + race-safe
 * via ExamFinalizer (P2002-aware), so safe to re-run.
 *
 * Schedule it everyMinute()->withoutOverlapping() in routes/console.php.
 * In dev: run `php artisan schedule:work`. In prod: `* * * * * php artisan schedule:run`.
 */
class FinalizeExpiredExams extends Command
{
    protected $signature = 'exams:finalize-expired
                            {--grace=60 : Seconds past the deadline before finalising}
                            {--limit=500 : Max sessions to process per run}';

    protected $description = 'Find draft exam sessions whose time is up and convert them into scored submissions.';

    public function handle(): int
    {
        $grace = (int) $this->option('grace');
        $limit = (int) $this->option('limit');

        // SQL-filter to candidates only — sessions whose started_at + duration*60 + grace
        // is in the past, joined to exams for the duration. This avoids loading every
        // draft session into memory when there are thousands of submissions a day.
        $candidates = DB::table('exam_sessions as s')
            ->join('exams as e', 'e.id', '=', 's.exam_id')
            ->where('s.status', 'draft')
            ->whereRaw('TIMESTAMPADD(SECOND, e.duration_minutes * 60 + ?, s.started_at) < NOW(3)', [$grace])
            ->orderBy('s.started_at')
            ->limit($limit)
            ->pluck('s.id');

        if ($candidates->isEmpty()) {
            $this->info('No expired draft sessions to finalize.');

            return self::SUCCESS;
        }

        $finalized = 0;
        $errors = 0;
        foreach ($candidates as $sessionId) {
            try {
                ExamFinalizer::finaliseExpiredSession($sessionId);
                $finalized++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('exams:finalize-expired failed to finalize session', [
                    'sessionId' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Finalized {$finalized} expired draft session(s)" . ($errors > 0 ? " (errors: {$errors})" : '') . '.');

        return self::SUCCESS;
    }
}
