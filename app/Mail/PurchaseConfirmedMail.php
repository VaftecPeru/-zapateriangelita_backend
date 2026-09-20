<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?User $user = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Compra confirmada · Pedido ' . $this->order->code . ' · Zapatería Angelita',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase_confirmed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
