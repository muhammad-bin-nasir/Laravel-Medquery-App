<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ChatApiTestSeeder extends Seeder
{
    public function run(): void
    {
        // Remove all existing users and keep only one fixed admin account.
        User::query()->delete();

        $business = Business::query()->firstOrCreate(
            ['business_client_id' => 'acme'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'ACME Health',
            ]
        );

        $workspace = Workspace::query()->firstOrCreate(
            [
                'business_client_id' => $business->business_client_id,
                'workspace_id' => 'main',
            ],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Main Workspace',
            ]
        );

        $admin = User::query()->firstOrCreate(
            ['email_normalized' => 'admin@admin.com'],
            [
                'id' => (string) Str::uuid(),
                'business_id' => null,
                'workspace_id' => null,
                'email' => 'admin@admin.com',
                'password_hash' => Hash::make('admin@12345'),
                'role' => 'admin',
            ]
        );

        if ($admin->role !== 'admin' || $admin->business_id !== null || $admin->workspace_id !== null) {
            $admin->role = 'admin';
            $admin->business_id = null;
            $admin->workspace_id = null;
            $admin->save();
        }

        $business->admin_id = $admin->id;
        $business->save();

        WorkspaceConfig::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'workspace_id' => $workspace->id,
            ],
            [
                'id' => (string) Str::uuid(),
                'chunk_words' => 300,
                'overlap_words' => 50,
                'top_k' => 5,
                'similarity_threshold' => 0.2,
                'max_context_chars' => 12000,
                'embedding_model' => 'text-embedding-3-small',
                'use_local_embeddings' => false,
                'chat_model_default' => 'gpt-4.1-mini',
                'chat_temperature_default' => 0.2,
                'chat_max_tokens_default' => 600,
                'prompt_engineering' => 'You are a medical assistant. Provide concise answers based on the context.',
            ]
        );

        $document = Document::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'workspace_id' => $workspace->id,
                'filename' => 'seed-note.txt',
            ],
            [
                'id' => (string) Str::uuid(),
                'file_type' => 'txt',
                'storage_path' => 'seed/seed-note.txt',
                'status' => 'indexed',
                'meta_json' => json_encode(['source' => 'seed']),
            ]
        );

        DocumentChunk::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'workspace_id' => $workspace->id,
                'document_id' => $document->id,
                'chunk_index' => 0,
            ],
            [
                'id' => (string) Str::uuid(),
                'page_number' => 1,
                'content' => 'Seed medical context text for test usage.',
                'embedding' => DB::getDriverName() === 'pgsql'
                    ? '['.implode(',', array_fill(0, 384, '0')).']'
                    : json_encode(array_fill(0, 8, 0.0)),
            ]
        );

        if (trim((string) env('OPENAI_API_KEY', '')) !== '') {
            SystemConfig::query()->updateOrCreate(
                ['key' => 'OPENAI_API_KEY'],
                ['id' => (string) Str::uuid(), 'value' => (string) env('OPENAI_API_KEY')]
            );
        }
    }
}
