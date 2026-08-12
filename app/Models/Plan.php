<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'plans';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'slug',
        'name',
        'description',
        'monthly_token_limit',
        'price_cents',
        'currency',
        'openai_usd_per_million',
        'markup_multiplier',
        'features',
        'is_active',
        'is_highlighted',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_token_limit' => 'integer',
            'price_cents' => 'integer',
            'openai_usd_per_million' => 'float',
            'markup_multiplier' => 'float',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_highlighted' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function formattedPrice(): string
    {
        return '$'.number_format($this->price_cents / 100, 2);
    }
}
