<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class TransactionalMailHealthService
{
    public function diagnostics(): array
    {
        $mailer = (string) config('mail.default', '');
        $from = trim((string) config('mail.from.address', ''));
        $errors = [];

        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'MAIL_FROM_ADDRESS no está configurado con un correo válido.';
        }

        if ($mailer === 'smtp') {
            $host = trim((string) config('mail.mailers.smtp.host', ''));
            $username = trim((string) config('mail.mailers.smtp.username', ''));
            $password = (string) config('mail.mailers.smtp.password', '');

            if ($host === '') $errors[] = 'MAIL_HOST está vacío.';
            if ($username === '') $errors[] = 'MAIL_USERNAME está vacío.';
            if ($password === '') $errors[] = 'MAIL_PASSWORD está vacío.';
        } elseif (in_array($mailer, ['array', 'log'], true)) {
            $errors[] = 'MAIL_MAILER está configurado para pruebas y no entrega correos reales.';
        } elseif ($mailer === '') {
            $errors[] = 'MAIL_MAILER está vacío.';
        }

        $pendingOrders = null;
        if (
            Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'purchase_email_sent_at')
            && Schema::hasColumn('orders', 'credential_email_ciphertext')
        ) {
            $pendingOrders = Order::query()
                ->where('payment_status', 'paid')
                ->where(function ($query) {
                    $query->whereNull('purchase_email_sent_at')
                        ->orWhere(function ($credentials) {
                            $credentials->whereNotNull('credential_email_ciphertext')
                                ->whereNull('credential_email_sent_at');
                        });
                })
                ->count();
        }

        $pendingWelcome = null;
        if (
            Schema::hasTable('users')
            && Schema::hasColumn('users', 'welcome_email_sent_at')
            && Schema::hasColumn('users', 'welcome_email_next_attempt_at')
        ) {
            $pendingWelcome = User::query()
                ->whereNull('welcome_email_sent_at')
                ->whereNotNull('welcome_email_next_attempt_at')
                ->count();
        }

        return [
            'configured' => empty($errors),
            'mailer' => $mailer,
            'from_address' => $from !== '' ? $from : null,
            'smtp' => $mailer === 'smtp' ? [
                'host_configured' => trim((string) config('mail.mailers.smtp.host', '')) !== '',
                'port' => (int) config('mail.mailers.smtp.port', 0),
                'encryption' => config('mail.mailers.smtp.encryption'),
                'username_configured' => trim((string) config('mail.mailers.smtp.username', '')) !== '',
                'password_configured' => (string) config('mail.mailers.smtp.password', '') !== '',
            ] : null,
            'pending' => [
                'order_notifications' => $pendingOrders,
                'welcome_emails' => $pendingWelcome,
            ],
            'errors' => $errors,
        ];
    }
}
