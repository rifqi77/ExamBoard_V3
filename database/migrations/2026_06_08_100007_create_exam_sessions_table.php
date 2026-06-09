<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('user_id', 191);
            $table->string('exam_id', 191);
            $table->integer('attempt')->default(1);
            $table->enum('status', ['draft', 'submitted', 'expired'])->default('draft');
            $table->timestamp('started_at', 3)->useCurrent();
            $table->timestamp('last_saved_at', 3)->nullable();
            $table->timestamp('submitted_at', 3)->nullable();
            $table->string('token_id', 191)->nullable();
            $table->integer('time_used_seconds')->default(0);
            $table->json('anti_cheat_events')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique(['user_id', 'exam_id', 'attempt']);
            $table->index(['user_id', 'exam_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            $table->foreign('token_id')->references('id')->on('exam_access_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sessions');
    }
};
