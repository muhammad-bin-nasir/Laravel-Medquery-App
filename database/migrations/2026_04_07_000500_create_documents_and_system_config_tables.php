<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('workspace_id');
            $table->string('filename', 255);
            $table->string('file_type', 50);
            $table->string('storage_path', 500);
            $table->string('status', 50)->default('uploaded');
            $table->timestampTz('indexed_at')->nullable();
            $table->text('meta_json')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement('CREATE TABLE document_chunks (
                id UUID PRIMARY KEY,
                business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
                workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                document_id UUID NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                chunk_index INTEGER NOT NULL,
                page_number INTEGER NULL,
                content TEXT NOT NULL,
                embedding vector(384) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NULL
            )');
            DB::statement('CREATE INDEX ix_chunk_business_workspace ON document_chunks (business_id, workspace_id)');
            DB::statement('CREATE INDEX ix_chunk_business_workspace_doc ON document_chunks (business_id, workspace_id, document_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS ix_document_chunks_embedding ON document_chunks USING hnsw (embedding vector_cosine_ops)');
        } else {
            Schema::create('document_chunks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('workspace_id');
                $table->uuid('document_id');
                $table->integer('chunk_index');
                $table->integer('page_number')->nullable();
                $table->longText('content');
                $table->longText('embedding');
                $table->timestamps();

                $table->index(['business_id', 'workspace_id'], 'ix_chunk_business_workspace');
                $table->index(['business_id', 'workspace_id', 'document_id'], 'ix_chunk_business_workspace_doc');
                $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            });
        }

        Schema::create('system_config', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('value', 2000)->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_config');
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('documents');
    }
};
