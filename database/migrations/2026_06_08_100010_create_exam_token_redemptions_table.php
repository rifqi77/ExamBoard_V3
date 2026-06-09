<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_token_redemptions', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('token_id', 191);
            $table->string('user_id', 191);
            $table->timestamp('redeemed_at', 3)->useCurrent();

            $table->unique(['token_id', 'user_id']); // load-bearing: P2002 == already redeemed
            $table->foreign('token_id')->references('id')->on('exam_access_tokens')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_token_redemptions');
    }
};
