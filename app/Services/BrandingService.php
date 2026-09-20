<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class BrandingService
{
    public function logoUrl(): string
    {
        $frontend = rtrim((string) env('FRONTEND_URL', 'https://zapateriangelita.com'), '/');
        $fallback = $frontend . '/logo-angelita-horizontal.png';

        if (!Schema::hasTable('settings')) {
            return $fallback;
        }

        $value = trim((string) Setting::where('key', 'logo_url')->value('value'));
        if ($value === '') {
            return $fallback;
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        $path = '/' . ltrim($value, '/');
        if (str_starts_with($path, '/uploads/')) {
            return rtrim((string) config('app.url'), '/') . '/api/media' . $path;
        }

        return $frontend . $path;
    }
}
