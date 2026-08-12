<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'external_id',
        'business_id',
        'business_client_id',
        'workspace_id',
        'email',
        'email_normalized',
        'display_name',
        'password_hash',
        'role',
        'is_active',
        'created_by',
        'plan',
        'subscription_id',
        'stripe_customer_id',
        'stripe_payment_method_id',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'external_id' => 'string',
            'business_id' => 'string',
            'business_client_id' => 'string',
            'workspace_id' => 'string',
            'is_active' => 'boolean',
            'created_by' => 'string',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function isActiveAccount(): bool
    {
        return $this->is_active !== false;
    }
}
