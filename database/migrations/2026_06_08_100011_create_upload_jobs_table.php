<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_upload_jobs', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->enum('kind', ['exam_config', 'exam_package', 'student_class']);
            $table->string('file_name', 191);
            $table->string('status', 191)->default('received'); // plain string, not enum
            $table->text('notes')->nullable();
            $table->string('uploaded_by', 191)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('exam_generation_prompts', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('upload_job_id', 191)->nullable();
            $table->integer('config_order')->default(0);
            $table->string('exam_id', 191); // soft ref to exams.exam_code (human code, NOT FK)
            $table->string('exam_title', 191);
            $table->json('config');
            $table->text('prompt_text');
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index('upload_job_id');
            $table->index('exam_id');
            $table->foreign('upload_job_id')->references('id')->on('admin_upload_jobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_generation_prompts');
        Schema::dropIfExists('admin_upload_jobs');
    }
};
