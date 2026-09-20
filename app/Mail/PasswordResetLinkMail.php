<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Restablece tu contraseña · Zapatería Angelita',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password_reset_link',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
