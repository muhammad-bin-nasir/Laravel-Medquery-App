<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $resetUrl,
        public readonly string $siteName = 'NursingAI',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reset your {$this->siteName} password",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody(),
        );
    }

    private function htmlBody(): string
    {
        $url = e($this->resetUrl);
        $site = e($this->siteName);

        return <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.5;">
  <div style="max-width: 560px; margin: 0 auto; padding: 24px;">
    <h2 style="color: #053447; margin-bottom: 8px;">Reset your password</h2>
    <p>We received a request to reset your {$site} account password.</p>
    <p style="margin: 24px 0;">
      <a href="{$url}" style="display: inline-block; background: #2EAADB; color: #fff; text-decoration: none; padding: 12px 18px; border-radius: 999px; font-weight: 600;">
        Reset password
      </a>
    </p>
    <p style="font-size: 13px; color: #64748b;">This link expires in 60 minutes. If you did not request a reset, you can ignore this email.</p>
    <p style="font-size: 12px; color: #94a3b8; word-break: break-all;">{$url}</p>
  </div>
</body>
</html>
HTML;
    }
}
