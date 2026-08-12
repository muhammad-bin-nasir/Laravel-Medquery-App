<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteLog extends Model
{
    use HasUuids;

    protected $table = 'site_logs';

    protected $fillable = [
        'severity',
        'source',
        'category',
        'message',
        'exception_class',
        'stack_trace',
        'context_json',
        'correlation_id',
        'user_id',
        'user_email',
        'user_role',
        'request_method',
        'request_path',
        'request_url',
        'ip_address',
        'user_agent',
        'status_code',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'status_code' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
