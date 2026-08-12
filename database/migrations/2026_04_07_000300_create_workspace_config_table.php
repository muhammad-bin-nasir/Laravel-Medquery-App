<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_config', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('workspace_id');
            $table->integer('chunk_words')->default(300);
            $table->integer('overlap_words')->default(50);
            $table->integer('top_k')->default(5);
            $table->float('similarity_threshold')->default(0.2);
            $table->integer('max_context_chars')->default(12000);
            $table->string('embedding_model', 255)->default('text-embedding-3-small');
            $table->boolean('use_local_embeddings')->default(false);
            $table->string('chat_model_default', 255)->default('gpt-4.1-mini');
            $table->float('chat_temperature_default')->default(0.2);
            $table->integer('chat_max_tokens_default')->default(600);
            $table->text('prompt_engineering');
            $table->timestamps();

            $table->unique(['business_id', 'workspace_id'], 'uq_config_workspace');
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_config');
    }
};
