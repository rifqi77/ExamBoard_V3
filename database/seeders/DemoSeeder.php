<?php

namespace Database\Seeders;

use App\Models\AppConfigAi;
use App\Models\Exam;
use App\Models\ExamAccessToken;
use App\Models\ExamQuestion;
use App\Models\User;
use App\Models\UserCredential;
use App\Support\CryptoSecrets;
use App\Support\Tokens;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent: clear prior demo data (cascades to children).
        Exam::where('exam_code', 'DEMO')->delete();
        User::whereIn('username', ['RIFQI', 'teacher1', 'student1'])->delete();
        AppConfigAi::singleton();

        $teacherCaps = [
            'ai.generate' => true,
            'curriculum.manage' => true,
            'exam.config.duration' => true,
            'exam.config.passingGrade' => true,
            'exam.config.mode' => true,
            'exam.config.shuffleQuestions' => true,
            'exam.config.shuffleOptions' => true,
            'exam.config.language' => true,
            'exam.config.seb' => true,
            'exam.param.type.single' => true,
            'exam.param.type.multi' => true,
            'exam.param.type.short_text' => true,
            'exam.param.type.numeric' => true,
            'exam.param.type.essay' => true,
            // Bloom's revised taxonomy (replaces legacy easy/medium/hard/hots).
            'exam.param.difficulty.remember' => true,
            'exam.param.difficulty.understand' => true,
            'exam.param.difficulty.apply' => true,
            'exam.param.difficulty.analyze' => true,
        ];

        $this->makeUser('RIFQI', 'Rifqi Admin', 'admin', 'TestPass2026');
        $teacher = $this->makeUser('teacher1', 'Teacher One', 'teacher', 'Teacher123', 'PHYSICS / FISIKA', $teacherCaps);
        $this->makeUser('student1', 'Student One', 'student', 'Student123');

        $exam = Exam::create([
            'exam_code' => 'DEMO',
            'name' => 'Demo Exam',
            'duration_minutes' => 30,
            'passing_grade' => 50,
            'general_instructions' => 'Answer all questions. Good luck!',
            'exam_mode' => 'try_out',
            'language' => 'English',
            'subject' => 'PHYSICS / FISIKA',
            'active' => true,
            'created_by' => $teacher->id,
            'created_by_name' => $teacher->full_name,
        ]);

        $p = 1;
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'single_choice', 'topic' => 'Arithmetic',
            'prompt' => 'What is 2 + 2?',
            'options' => [['id' => 'a', 'text' => '3'], ['id' => 'b', 'text' => '4'], ['id' => 'c', 'text' => '5'], ['id' => 'd', 'text' => '6']],
            'points' => 4, 'correct_answer' => 'b', 'explanation_text' => '2 + 2 = 4.',
        ]);
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'multi_select', 'topic' => 'Numbers',
            'prompt' => 'Select the even numbers.',
            'options' => [['id' => '1', 'text' => '2'], ['id' => '2', 'text' => '3'], ['id' => '3', 'text' => '4'], ['id' => '4', 'text' => '5']],
            'points' => 4, 'correct_answer' => ['1', '3'], 'explanation_text' => '2 and 4 are even.',
        ]);
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'short_text', 'topic' => 'Geography',
            'prompt' => 'What is the capital of France?',
            'points' => 3, 'correct_answer' => 'Paris', 'explanation_text' => 'Paris is the capital of France.',
        ]);
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'numeric', 'topic' => 'Arithmetic',
            'prompt' => 'What is 6 × 7?',
            'points' => 4, 'correct_answer' => 42, 'explanation_text' => '6 × 7 = 42.',
        ]);
        ExamQuestion::create([
            'exam_id' => $exam->id, 'position' => $p++, 'type' => 'essay', 'topic' => 'Physics',
            'prompt' => "State Newton's first law of motion.",
            'points' => 6, 'correct_answer' => null,
        ]);

        $plain = 'DEMO-2026';
        ExamAccessToken::create([
            'exam_id' => $exam->id,
            'token_digest' => Tokens::digest($plain),
            'token_preview' => CryptoSecrets::encryptTokenPreview($plain),
            'max_uses' => 1000,
            'used_count' => 0,
            'active' => true,
            'created_by' => $teacher->id,
            'created_by_name' => $teacher->full_name,
        ]);
    }

    private function makeUser(string $username, string $full, string $role, string $pwd, ?string $subject = null, ?array $caps = null): User
    {
        $u = User::create([
            'username' => $username,
            'full_name' => $full,
            'role' => $role,
            'active' => true,
            'subject' => $subject,
            'capabilities' => $caps,
        ]);
        UserCredential::create([
            'user_id' => $u->id,
            'password_hash' => Hash::make($pwd),
            'password_plain' => CryptoSecrets::encryptStudentPassword($pwd),
        ]);

        return $u;
    }
}
