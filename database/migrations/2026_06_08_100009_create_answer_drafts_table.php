<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('answer_drafts', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('session_id', 191);
            $table->string('question_id', 191);
            $table->json('value'); // NOT nullable
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['session_id', 'question_id']);
            $table->index('session_id');
            $table->foreign('session_id')->references('id')->on('exam_sessions')->cascadeOnDelete();
            $table->foreign('question_id')->references('id')->on('exam_questions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_drafts');
    }
};
