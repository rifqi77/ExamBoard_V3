<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('username', 191)->unique();
            $table->string('full_name', 191);
            $table->enum('role', ['student', 'teacher', 'admin']);
            $table->boolean('active')->default(true);
            $table->string('subject', 191)->nullable();
            $table->json('capabilities')->nullable();
            $table->integer('token_version')->default(0);
            $table->string('created_by', 191)->nullable();
            $table->timestamps(3);

            $table->index('role');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('user_credentials', function (Blueprint $table) {
            $table->string('user_id', 191)->primary();
            $table->string('password_hash', 191);
            $table->string('password_plain', 191)->nullable(); // encrypted at rest
            $table->string('password_set_by', 191)->nullable();
            $table->timestamp('password_set_at', 3)->useCurrent();
            $table->timestamp('last_sign_in_at', 3)->nullable();
            $table->integer('failed_attempts')->default(0);
            $table->timestamp('locked_until', 3)->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_credentials');
        Schema::dropIfExists('users');
    }
};
