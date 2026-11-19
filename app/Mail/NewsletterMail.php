<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;

class NewsletterMail extends Mailable
{
    public function __construct(
        private readonly bool $welcome,
        private readonly string $unsubscribeUrl,
        private readonly Collection $newProducts,
        private readonly Collection $offers,
    ) {
    }

    public function build(): self
    {
        return $this->subject($this->welcome
            ? 'Suscripción confirmada · Zapatería Angelita'
            : 'Novedades y ofertas · Zapatería Angelita')
            ->view('newsletter.message', [
                'welcome' => $this->welcome,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'newProducts' => $this->newProducts,
                'offers' => $this->offers,
                'storeUrl' => rtrim(config('cors.allowed_origins.0', 'https://zapateriangelita.com'), '/'),
            ]);
    }
}
