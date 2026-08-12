<?php

namespace App\Mail;

use App\Models\HelpTicket;
use App\Models\HelpTicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HelpTicketReplyMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly HelpTicket $ticket,
        public readonly HelpTicketReply $reply,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Re: '.($this->ticket->ticket_number ? $this->ticket->ticket_number.' — ' : '').$this->ticket->subject,
            to: [$this->ticket->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.help-ticket-reply-text',
            with: [
                'ticket' => $this->ticket,
                'reply' => $this->reply,
            ],
        );
    }
}
