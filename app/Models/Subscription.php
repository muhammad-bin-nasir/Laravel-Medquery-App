<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'subscriptions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'plan_id',
        'status',
        'stripe_payment_intent_id',
        'tokens_included',
        'tokens_used',
        'amount_cents',
        'currency',
        'current_period_start',
        'current_period_end',
    ];

    protected function casts(): array
    {
        return [
            'tokens_included' => 'integer',
            'tokens_used' => 'integer',
            'amount_cents' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->current_period_end && $this->current_period_end->isPast()) {
            return false;
        }

        return true;
    }

    public function tokensRemaining(): int
    {
        return max(0, (int) $this->tokens_included - (int) $this->tokens_used);
    }

    public function hasTokenQuota(int $estimate = 1): bool
    {
        return $this->isActive() && $this->tokensRemaining() >= $estimate;
    }
}
