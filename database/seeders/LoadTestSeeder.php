<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\ExamAccessToken;
use App\Models\ExamQuestion;
use App\Models\User;
use App\Support\CryptoSecrets;
use App\Support\Tokens;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the load-test fixture: 2000 loadstudent#### + 20 loadteacher## +
 * a LOADTEST try_out exam owned by loadteacher01 + a LOADTEST-2026 token
 * with max_uses=2500. All accounts share the same bcrypt hash (computed
 * ONCE) so the seed runs in seconds instead of hours.
 *
 * Idempotent — re-running purges prior load-test data first.
 *
 * Run with: php artisan db:seed --class=LoadTestSeeder
 */
class LoadTestSeeder extends Seeder
{
    public function run(): void
    {
        $studentCount = (int) env('LOADTEST_STUDENTS', 2000);
        $teacherCount = (int) env('LOADTEST_TEACHERS', 20);
        $password = 'LoadTestPass';

        $this->command->info('Purging prior load-test data...');
        Exam::where('exam_code', 'LOADTEST')->delete();
        DB::table('users')->where('username', 'like', 'loadstudent%')->delete();
        DB::table('users')->where('username', 'like', 'loadteacher%')->delete();

        $this->command->info('Hashing shared password (once)...');
        $hash = Hash::make($password);
        $encryptedPlain = CryptoSecrets::encryptStudentPassword($password);
        $now = now();

        // ===== Students (bulk insert) =====
        $this->command->info("Inserting {$studentCount} students...");
        $users = [];
        $creds = [];
        for ($i = 1; $i <= $studentCount; $i++) {
            $username = sprintf('loadstudent%04d', $i);
            $uid = (string) Str::uuid();
            $users[] = [
                'id' => $uid,
                'username' => $username,
                'full_name' => "Load Student {$i}",
                'role' => 'student',
                'active' => 1,
                'token_version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $creds[] = [
                'user_id' => $uid,
                'password_hash' => $hash,
                'password_plain' => $encryptedPlain,
                'password_set_at' => $now,
                'failed_attempts' => 0,
            ];
            if (count($users) >= 500) {
                DB::table('users')->insert($users);
                DB::table('user_credentials')->insert($creds);
                $users = [];
                $creds = [];
            }
        }
        if ($users) {
            DB::table('users')->insert($users);
            DB::table('user_credentials')->insert($creds);
        }

        // ===== Teachers =====
        $this->command->info("Inserting {$teacherCount} teachers...");
        $caps = json_encode([
            'ai.generate' => true,
            'curriculum.manage' => true,
        ]);
        $tUsers = [];
        $tCreds = [];
        $teacherIds = [];
        for ($i = 1; $i <= $teacherCount; $i++) {
            $username = sprintf('loadteacher%02d', $i);
            $uid = (string) Str::uuid();
            $teacherIds[] = $uid;
            $tUsers[] = [
                'id' => $uid,
                'username' => $username,
                'full_name' => "Load Teacher {$i}",
                'role' => 'teacher',
                'active' => 1,
                'subject' => 'PHYSICS / FISIKA',
                'capabilities' => $caps,
                'token_version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $tCreds[] = [
                'user_id' => $uid,
                'password_hash' => $hash,
                'password_plain' => $encryptedPlain,
                'password_set_at' => $now,
                'failed_attempts' => 0,
            ];
        }
        DB::table('users')->insert($tUsers);
        DB::table('user_credentials')->insert($tCreds);

        // ===== LOADTEST exam (owned by loadteacher01) =====
        $ownerTeacher = $teacherIds[0];
        $exam = Exam::create([
            'exam_code' => 'LOADTEST',
            'name' => 'Load Test Exam',
            'duration_minutes' => 30,
            'passing_grade' => 50,
            'general_instructions' => '',
            'exam_mode' => 'try_out',
            'language' => 'English',
            'subject' => 'PHYSICS / FISIKA',
            'active' => true,
            'created_by' => $ownerTeacher,
            'created_by_name' => 'Load Teacher 1',
        ]);

        // 5 questions, same shape as DEMO so the driver can reuse the answer map.
        $p = 1;
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'single_choice', 'topic' => 'Arithmetic',
            'prompt' => '2 + 2 = ?',
            'options' => [['id' => 'a', 'text' => '3'], ['id' => 'b', 'text' => '4'], ['id' => 'c', 'text' => '5'], ['id' => 'd', 'text' => '6']],
            'points' => 4, 'correct_answer' => 'b',
        ]);
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'multi_select', 'topic' => 'Numbers',
            'prompt' => 'Even numbers?',
            'options' => [['id' => '1', 'text' => '2'], ['id' => '2', 'text' => '3'], ['id' => '3', 'text' => '4'], ['id' => '4', 'text' => '5']],
            'points' => 4, 'correct_answer' => ['1', '3'],
        ]);
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'short_text', 'topic' => 'Geography',
            'prompt' => 'Capital of France',
            'points' => 3, 'correct_answer' => 'Paris',
        ]);
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'numeric', 'topic' => 'Arithmetic',
            'prompt' => '6 × 7',
            'points' => 4, 'correct_answer' => 42,
        ]);
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'essay', 'topic' => 'Physics',
            'prompt' => "State Newton's first law",
            'points' => 6, 'correct_answer' => null,
        ]);

        // Token LOADTEST-2026 with max_uses=2500
        $plain = 'LOADTEST-2026';
        ExamAccessToken::create([
            'exam_id' => $exam->id,
            'token_digest' => Tokens::digest($plain),
            'token_preview' => CryptoSecrets::encryptTokenPreview($plain),
            'max_uses' => 2500,
            'used_count' => 0,
            'active' => true,
            'created_by' => $ownerTeacher,
            'created_by_name' => 'Load Teacher 1',
        ]);

        $this->command->info("Seeded {$studentCount} students + {$teacherCount} teachers + LOADTEST exam + LOADTEST-2026 token.");
    }
}
