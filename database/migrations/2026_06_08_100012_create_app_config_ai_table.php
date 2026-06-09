<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_config_ai', function (Blueprint $table) {
            $table->string('id', 191)->primary()->default('ai'); // singleton row
            $table->string('text_provider', 191)->default('claude');
            $table->string('text_model', 191)->default('claude-haiku-4-5');
            $table->double('temperature')->default(0.4);
            $table->string('image_provider', 191)->default('gemini');
            $table->json('ai_keys')->nullable(); // { gemini?, claude?, openai? } each AES-256-GCM encrypted
            $table->string('updated_by', 191)->nullable();
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_config_ai');
    }
};
