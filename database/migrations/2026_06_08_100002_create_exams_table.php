<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('exam_code', 191)->unique();
            $table->string('name', 191);
            $table->integer('duration_minutes');
            $table->integer('passing_grade');
            $table->text('general_instructions')->nullable();
            $table->timestamp('start_time', 3)->nullable();
            $table->timestamp('end_time', 3)->nullable();
            $table->boolean('active')->default(true);
            $table->string('media_base_url', 191)->nullable();
            $table->enum('exam_mode', ['strict', 'try_out'])->default('strict');
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->string('language', 191)->default('English');
            $table->string('subject', 191)->nullable();
            $table->json('type_distribution')->nullable();
            $table->json('difficulty_distribution')->nullable();
            $table->json('media_targets')->nullable();
            $table->string('created_by', 191)->nullable();
            $table->string('created_by_name', 191)->nullable();
            $table->boolean('seb_required')->default(false);
            $table->string('seb_secret', 191)->nullable(); // plaintext, NOT encrypted
            $table->timestamps(3);

            $table->index('created_by');
            $table->index('active');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
