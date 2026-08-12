<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_headers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_user_id', 255);
            $table->index('owner_user_id', 'ix_chat_headers_owner_user_id');
            $table->uuid('owner_user_uuid')->nullable()->index();
            $table->uuid('business_id')->nullable()->index();
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('chat_id', 255);
            $table->string('title', 255);
            $table->timestamps();

            $table->unique(['owner_user_id', 'chat_id'], 'uq_chat_headers_owner_chat_id');
            $table->unique(['owner_user_uuid', 'business_id', 'workspace_id', 'chat_id'], 'uq_chat_headers_owner_scope_chat_id');
        });

        Schema::create('chat_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('workspace_id');
            $table->string('user_id', 255);
            $table->uuid('user_uuid')->nullable()->index();
            $table->string('chat_header', 255)->nullable();
            $table->index('chat_header', 'ix_chat_requests_chat_header');
            $table->longText('query_text');
            $table->longText('retrieved_chunk_ids');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('chat_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->string('chat_header', 255)->nullable();
            $table->index('chat_header', 'ix_chat_responses_chat_header');
            $table->longText('answer_text');
            $table->longText('sources_json');
            $table->string('model_used', 255);
            $table->longText('tokens_json');
            $table->timestamps();

            $table->foreign('request_id')->references('id')->on('chat_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_responses');
        Schema::dropIfExists('chat_requests');
        Schema::dropIfExists('chat_headers');
    }
};
