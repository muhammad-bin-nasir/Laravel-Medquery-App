<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkspaceConfig extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'workspace_config';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'business_id',
        'workspace_id',
        'chunk_words',
        'overlap_words',
        'top_k',
        'similarity_threshold',
        'max_context_chars',
        'embedding_model',
        'use_local_embeddings',
        'chat_model_default',
        'chat_temperature_default',
        'chat_max_tokens_default',
        'prompt_engineering',
    ];
}
