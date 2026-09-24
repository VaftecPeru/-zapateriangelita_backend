<?php

namespace App\Services;

use App\Mail\WelcomeUserMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UserWelcomeNotificationService
{
    public function schedule(User $user, string $registrationMethod = 'password'): void
    {
        $userId = (int) $user->id;
        $method = $registrationMethod === 'google' ? 'google' : 'password';

        if ($this->supportsPersistence()) {
            User::whereKey($userId)->update([
                'welcome_email_method' => $method,
                'welcome_email_next_attempt_at' => now(),
                'welcome_email_last_error' => null,
            ]);
        }

        app()->terminating(function () use ($userId, $method) {
            try {
                $this->processById($userId, false, $method);
            } catch (\Throwable $e) {
                Log::error('WELCOME_EMAIL_DEFERRED_FAILED', [
                    'user_id' => $userId,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    public function processById(int $userId, bool $force = false, ?string $fallbackMethod = null): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }

        $persistent = $this->supportsPersistence();
        if ($persistent && $user->welcome_email_sent_at && !$force) {
            return true;
        }

        $method = (string) ($user->welcome_email_method ?: $fallbackMethod ?: 'password');
        $method = $method === 'google' ? 'google' : 'password';

        try {
            Mail::to($user->email)->send(new WelcomeUserMail($user, $method));

            if ($persistent) {
                User::whereKey($userId)->update([
                    'welcome_email_method' => $method,
                    'welcome_email_sent_at' => now(),
                    'welcome_email_attempts' => 0,
                    'welcome_email_next_attempt_at' => null,
                    'welcome_email_last_error' => null,
                ]);
            }

            Log::info('WELCOME_EMAIL_SENT', [
                'user_id' => $userId,
                'registration_method' => $method,
            ]);

            return true;
        } catch (\Throwable $e) {
            if ($persistent) {
                $attempts = ((int) $user->welcome_email_attempts) + 1;
                User::whereKey($userId)->update([
                    'welcome_email_attempts' => $attempts,
                    'welcome_email_next_attempt_at' => now()->addMinutes($this->backoffMinutes($attempts)),
                    'welcome_email_last_error' => Str::limit($e->getMessage(), 1000),
                ]);
            }

            Log::error('WELCOME_EMAIL_FAILED', [
                'user_id' => $userId,
                'registration_method' => $method,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function supportsPersistence(): bool
    {
        return Schema::hasTable('users')
            && Schema::hasColumn('users', 'welcome_email_sent_at')
            && Schema::hasColumn('users', 'welcome_email_next_attempt_at');
    }

    private function backoffMinutes(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 2,
            $attempts === 2 => 5,
            $attempts === 3 => 15,
            $attempts === 4 => 30,
            default => 60,
        };
    }
}
