<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpNotification extends Model
{
    use HasUuids;

    protected $table = 'help_notifications';

    protected $fillable = [
        'user_id',
        'help_ticket_id',
        'type',
        'title',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpTicket::class, 'help_ticket_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
