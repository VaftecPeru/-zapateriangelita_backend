<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
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
        $order = $this->order->loadMissing('items');
        $voucher = $this->voucherHtml($order);

        return [
            Attachment::fromData(
                fn () => $voucher,
                'voucher-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $order->code) . '.html'
            )->withMime('text/html'),
        ];
    }

    private function voucherHtml(Order $order): string
    {
        $esc = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $currency = strtoupper((string) config('openpay.currency', 'MXN'));
        $rows = '';

        foreach ($order->items as $item) {
            $rows .= '<tr>'
                . '<td>' . $esc($item->product_name) . '</td>'
                . '<td>' . $esc($item->size ?: '-') . '</td>'
                . '<td>' . $esc($item->color ?: '-') . '</td>'
                . '<td style="text-align:center">' . (int) $item->quantity . '</td>'
                . '<td style="text-align:right">' . $esc($currency) . ' ' . number_format((float) $item->unit_price, 2) . '</td>'
                . '</tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>Voucher</title></head>'
            . '<body style="font-family:Arial,sans-serif;color:#222">'
            . '<h2 style="color:#e30613">Zapatería Angelita</h2>'
            . '<h3>Comprobante de compra</h3>'
            . '<p><strong>Pedido:</strong> ' . $esc($order->code) . '<br>'
            . '<strong>Transacción:</strong> ' . $esc($order->payment_transaction_id ?: '-') . '<br>'
            . '<strong>Cliente:</strong> ' . $esc($order->customer_name) . '<br>'
            . '<strong>Correo:</strong> ' . $esc($order->customer_email) . '</p>'
            . '<table width="100%" cellspacing="0" cellpadding="6" border="1" style="border-collapse:collapse">'
            . '<thead><tr><th>Producto</th><th>Talla</th><th>Color</th><th>Cant.</th><th>Precio</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<p><strong>Subtotal:</strong> ' . $esc($currency) . ' ' . number_format((float) $order->subtotal, 2) . '<br>'
            . '<strong>Envío:</strong> ' . $esc($currency) . ' ' . number_format((float) $order->shipping_cost, 2) . '<br>'
            . '<strong>Total:</strong> ' . $esc($currency) . ' ' . number_format((float) $order->total, 2) . '</p>'
            . '<p>Gracias por tu compra.</p></body></html>';
    }
}
