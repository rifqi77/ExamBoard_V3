<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_classes', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('name', 191);
            $table->string('academic_year', 191); // "YYYY/YYYY"
            $table->string('source_file_name', 191)->nullable();
            $table->string('created_by', 191)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique(['created_by', 'name', 'academic_year']);
            $table->index(['name', 'academic_year']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('class_students', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('class_id', 191);
            $table->string('student_identifier', 191); // plain string, NOT a FK to users
            $table->string('student_name', 191);
            $table->string('student_email', 191)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique(['class_id', 'student_identifier']);
            $table->index('class_id');
            $table->foreign('class_id')->references('id')->on('student_classes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_students');
        Schema::dropIfExists('student_classes');
    }
};
