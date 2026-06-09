<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('learning_objectives', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->enum('curriculum', ['kurikulum_merdeka', 'as_a_level', 'ib', 'olympiad'])->default('kurikulum_merdeka');
            $table->string('language', 191);
            $table->string('subject', 191);
            $table->string('topic', 191);
            $table->string('subtopic', 191)->nullable();
            $table->text('text');
            $table->string('created_by', 191)->nullable();
            $table->string('created_by_name', 191)->nullable();
            $table->string('uploaded_by', 191)->nullable();
            $table->string('uploaded_by_name', 191)->nullable();
            $table->string('source_file_name', 191)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index('subject');
            $table->index('uploaded_by');
            $table->index(['curriculum', 'subject']);
            $table->index(['curriculum', 'subject', 'sort_order']);
            $table->index(['subject', 'topic']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_objectives');
    }
};
