<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Actualización de tu pedido ' . $this->order->code . ' · Zapatería Angelita',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order_status',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
