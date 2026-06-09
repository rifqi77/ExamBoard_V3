<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('exam_id', 191);
            $table->integer('position');
            $table->enum('type', ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay']);
            $table->string('topic', 191);
            $table->json('tags')->nullable();
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->double('points')->default(1);
            $table->string('source_bank_question_id', 191)->nullable(); // soft ref, no FK
            $table->json('correct_answer')->nullable();
            $table->text('explanation_text')->nullable();
            $table->json('explanation_media')->nullable();
            $table->string('language', 191)->nullable();
            $table->enum('difficulty', ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'])->nullable();
            $table->string('media_file', 191)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique(['exam_id', 'position']);
            $table->index('exam_id');
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
        });

        Schema::create('exam_media', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('question_id', 191);
            $table->enum('type', ['image', 'audio', 'video']);
            $table->text('url');
            $table->string('alt_text', 191)->nullable();
            $table->string('caption', 191)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index('question_id');
            $table->foreign('question_id')->references('id')->on('exam_questions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_media');
        Schema::dropIfExists('exam_questions');
    }
};
