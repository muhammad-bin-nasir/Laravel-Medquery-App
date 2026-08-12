<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HelpTicket extends Model
{
    use HasUuids;

    protected $table = 'help_tickets';

    protected $fillable = [
        'ticket_number',
        'user_id',
        'email',
        'subject',
        'message',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'attachment_size',
        'status',
        'closed_at',
    ];

    protected $casts = [
        'attachment_size' => 'integer',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(HelpTicketReply::class)->orderBy('created_at');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isOpenForReplies(): bool
    {
        return in_array($this->status, ['open', 'answered'], true);
    }
}
