<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_access_tokens', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('exam_id', 191);
            $table->string('class_id', 191)->nullable();
            $table->string('token_digest', 191)->unique(); // sha256 hex of normalized token
            $table->string('token_preview', 191); // encrypted at rest (plaintext code)
            $table->integer('max_uses')->default(1);
            $table->integer('used_count')->default(0);
            $table->timestamp('expires_at', 3)->nullable();
            $table->boolean('active')->default(true);
            $table->string('created_by', 191)->nullable();
            $table->string('created_by_name', 191)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index('exam_id');
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('student_classes')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_access_tokens');
    }
};
