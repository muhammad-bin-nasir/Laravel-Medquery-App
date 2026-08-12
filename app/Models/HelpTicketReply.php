<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpTicketReply extends Model
{
    use HasUuids;

    protected $table = 'help_ticket_replies';

    protected $fillable = [
        'help_ticket_id',
        'admin_id',
        'user_id',
        'author_role',
        'message',
        'emailed_at',
    ];

    protected $casts = [
        'emailed_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpTicket::class, 'help_ticket_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
