<?php

namespace App\Console\Commands;

use App\Mail\NewsletterMail;
use App\Models\NewsletterSubscriber;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Throwable;

class SendNewsletter extends Command
{
    protected $signature = 'newsletter:send {kind : welcome o monthly}';
    protected $description = 'Envía correos de bienvenida pendientes o el resumen mensual de la tienda.';

    public function handle(): int
    {
        $kind = $this->argument('kind');
        if (!in_array($kind, ['welcome', 'monthly'], true)) {
            $this->error('Usa welcome o monthly.');
            return self::INVALID;
        }

        if (!Schema::hasColumn('newsletter_subscribers', 'welcome_due_at')) {
            $this->warn('La migración del newsletter aún no está aplicada.');
            return self::FAILURE;
        }

        $welcome = $kind === 'welcome';
        $monthStart = now('America/Mexico_City')->startOfMonth();
        $month = $monthStart->format('Y-m');
        $cutoff = $monthStart->copy()->utc();
        $newProducts = collect();
        $offers = collect();

        $recipients = NewsletterSubscriber::query()->where('status', 'active');
        if ($welcome) {
            $recipients->where('welcome_due_at', '<=', now());
        } else {
            $products = Product::query()
                ->where('is_active', true)
                ->where('price', '>', 0)
                ->whereHas('brand', fn ($query) => $query->where('is_active', true))
                ->whereHas('category', fn ($query) => $query->where('is_active', true));

            $newProducts = (clone $products)
                ->where('created_at', '>=', $monthStart->copy()->subMonth()->utc())
                ->where('created_at', '<', $cutoff)
                ->latest()->limit(6)->get(['id', 'name']);

            $offers = (clone $products)
                ->where(function ($query) {
                    $query->where('discount', '>', 0)->orWhere(function ($query) {
                        $query->where('discounted_price', '>', 0)->whereColumn('discounted_price', '<', 'price');
                    });
                })
                ->whereNotIn('id', $newProducts->modelKeys())
                ->latest()->limit(6)->get(['id', 'name']);

            if ($newProducts->isEmpty() && $offers->isEmpty()) {
                return self::SUCCESS;
            }

            $recipients->where(fn ($query) => $query->whereNull('subscribed_at')->orWhere('subscribed_at', '<', $cutoff))
                ->where(fn ($query) => $query->whereNull('last_digest_month')->orWhere('last_digest_month', '<', $month));
        }

        $failed = 0;
        $recipients->select('id')->chunkById(50, function ($batch) use ($welcome, $month, $cutoff, $newProducts, $offers, &$failed) {
            foreach ($batch as $recipient) {
                try {
                    DB::transaction(function () use ($recipient, $welcome, $month, $cutoff, $newProducts, $offers) {
                        $subscriber = NewsletterSubscriber::whereKey($recipient->id)->lockForUpdate()->first();
                        if (!$subscriber || $subscriber->status !== 'active') {
                            return;
                        }

                        if ($welcome && (!$subscriber->welcome_due_at || $subscriber->welcome_due_at->isFuture())) {
                            return;
                        }

                        if (!$welcome && ($subscriber->last_digest_month >= $month || $subscriber->subscribed_at?->gte($cutoff))) {
                            return;
                        }

                        Mail::to($subscriber->email)->send(new NewsletterMail(
                            $welcome,
                            URL::signedRoute('newsletter.unsubscribe', ['subscriber' => $subscriber->id]),
                            $newProducts,
                            $offers,
                        ));

                        $subscriber->forceFill($welcome
                            ? ['welcome_due_at' => null]
                            : ['last_digest_month' => $month])->save();
                    });
                } catch (Throwable $error) {
                    $failed++;
                    if ($welcome) {
                        NewsletterSubscriber::whereKey($recipient->id)->where('status', 'active')
                            ->where('welcome_due_at', '<=', now())->update(['welcome_due_at' => now()->addHour()]);
                    }
                    Log::warning('NEWSLETTER_DELIVERY_FAILED', [
                        'subscriber_id' => $recipient->id,
                        'kind' => $welcome ? 'welcome' : 'monthly',
                        'exception' => $error::class,
                    ]);
                }
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
