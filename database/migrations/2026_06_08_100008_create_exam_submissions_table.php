<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_submissions', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('exam_id', 191);
            $table->string('user_id', 191);
            $table->string('session_id', 191)->nullable()->unique();
            $table->string('username', 191);
            $table->string('full_name', 191);
            $table->string('exam_name', 191);
            $table->enum('exam_mode', ['strict', 'try_out']);
            $table->integer('attempt')->default(1);
            $table->integer('passing_grade');
            $table->double('final_score');
            $table->double('possible_score');
            $table->double('percent_score');
            $table->boolean('passed');
            $table->integer('pending_essay_count')->default(0);
            $table->json('topic_breakdown')->nullable();
            $table->json('answers_snapshot')->nullable();
            $table->json('manual_scores')->nullable();
            $table->json('anti_cheat_events')->nullable();
            $table->json('review_items')->nullable();
            $table->timestamp('submitted_at', 3)->useCurrent();
            $table->timestamp('graded_at', 3)->nullable();

            $table->unique(['user_id', 'exam_id', 'attempt']);
            $table->index(['user_id', 'exam_id']);
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('session_id')->references('id')->on('exam_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_submissions');
    }
};
