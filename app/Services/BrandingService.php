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
        $appUrl = rtrim((string) config('app.url'), '/');

        if (str_starts_with($path, '/api/media/')) {
            return $appUrl . $path;
        }

        if (str_starts_with($path, '/uploads/') || str_starts_with($path, '/storage/')) {
            $relative = app(MediaStorageService::class)->normalizeRelativePath($path);

            return $relative
                ? $appUrl . app(MediaStorageService::class)->publicUrl($relative)
                : $fallback;
        }

        return $frontend . $path;
    }
}
