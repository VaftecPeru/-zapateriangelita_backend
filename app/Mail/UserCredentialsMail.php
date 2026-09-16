<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $password;
    public $order;

    public function __construct($user, $password, $order = null)
    {
        $this->user = $user;
        $this->password = $password;
        $this->order = $order;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Compra confirmada y acceso a Zapatería Angelita',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user_credentials',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
