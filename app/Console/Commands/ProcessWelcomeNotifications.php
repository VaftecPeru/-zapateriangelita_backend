<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserWelcomeNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProcessWelcomeNotifications extends Command
{
    protected $signature = 'welcome:process-notifications {--limit=25}';
    protected $description = 'Reintenta correos de bienvenida pendientes en hosting cPanel.';

    public function handle(UserWelcomeNotificationService $service): int
    {
        if (
            !Schema::hasColumn('users', 'welcome_email_sent_at')
            || !Schema::hasColumn('users', 'welcome_email_next_attempt_at')
        ) {
            $this->warn('La migración de correos de bienvenida aún no está aplicada.');
            return self::SUCCESS;
        }

        $limit = max(1, min(100, (int) $this->option('limit')));

        $users = User::query()
            ->whereNull('welcome_email_sent_at')
            ->whereNotNull('welcome_email_next_attempt_at')
            ->where('welcome_email_next_attempt_at', '<=', now())
            ->orderBy('welcome_email_next_attempt_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($users as $user) {
            $service->processById((int) $user->id);
            $processed++;
        }

        $this->info("Correos de bienvenida revisados: {$processed}");
        return self::SUCCESS;
    }
}
