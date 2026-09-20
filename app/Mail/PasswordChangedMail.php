<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $context = 'password_changed',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu contraseña fue actualizada · Zapatería Angelita',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password_changed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
