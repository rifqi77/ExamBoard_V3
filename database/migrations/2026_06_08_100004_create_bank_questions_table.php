<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_questions', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->enum('type', ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay']);
            $table->string('language', 191)->nullable();
            $table->string('subject', 191)->nullable();
            $table->string('topic', 191);
            $table->string('subtopic', 191)->nullable();
            $table->enum('difficulty', ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'])->nullable();
            $table->json('tags')->nullable();
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->double('points')->default(1);
            $table->json('correct_answer')->nullable();
            $table->text('explanation_text')->nullable();
            $table->string('created_by', 191)->nullable();
            $table->string('created_by_name', 191)->nullable();
            $table->string('uploaded_by', 191)->nullable();
            $table->string('uploaded_by_name', 191)->nullable();
            $table->string('source_file_name', 191)->nullable();
            $table->text('media_url')->nullable();
            $table->enum('media_type', ['image', 'audio', 'video'])->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index('created_by');
            $table->index('uploaded_by');
            $table->index('subject');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_questions');
    }
};
