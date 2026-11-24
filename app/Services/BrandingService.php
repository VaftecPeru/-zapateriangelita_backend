<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BrandingService
{
    public function __construct(private readonly MediaStorageService $media)
    {
    }

    public function logoUrl(): string
    {
        $frontend = rtrim((string) config('cors.allowed_origins.0', 'https://zapateriangelita.com'), '/');
        $fallback = $frontend . '/logo-angelita-horizontal.png';

        if (!Schema::hasTable('settings')) {
            return $fallback;
        }

        $value = trim((string) Setting::where('key', 'logo_url')->value('value'));
        if ($value === '') {
            return $fallback;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $absolute = preg_match('#^https?://#i', $value) === 1;
        if ($absolute && !str_starts_with($value, $appUrl . '/')) {
            return $value;
        }

        $path = $absolute ? substr($value, strlen($appUrl)) : '/' . ltrim($value, '/');

        if (str_starts_with($path, $this->media->urlPrefix() . '/') ||
            str_starts_with($path, '/uploads/') ||
            str_starts_with($path, '/storage/')) {
            $relative = $this->media->normalizeRelativePath($path);

            return $relative
                ? $appUrl . $this->media->publicUrl($this->emailImagePath($relative))
                : $fallback;
        }

        return $absolute ? $value : $frontend . $path;
    }

    private function emailImagePath(string $relative): string
    {
        if (!str_ends_with(strtolower($relative), '.webp') || !function_exists('imagecreatefromwebp')) {
            return $relative;
        }

        try {
            return $this->transparentPng($relative) ?? $relative;
        } catch (RuntimeException) {
            return $relative;
        }
    }

    private function transparentPng(string $relative): ?string
    {
        $source = $this->media->resolveForRead($relative);
        $size = $source === null ? false : @filesize($source);
        if (!$size || $size > 8 * 1024 * 1024) {
            return null;
        }

        $hash = @hash_file('sha256', $source);
        if ($hash === false) {
            return null;
        }

        $directory = dirname($relative);
        $png = ($directory === '.' ? '' : $directory . '/') . 'email-logo-' . $hash . '.png';
        $destination = $this->media->absolutePath($png);
        if (is_file($destination)) {
            return $png;
        }

        $dimensions = @getimagesize($source);
        if (!$dimensions || $dimensions[2] !== IMAGETYPE_WEBP || $dimensions[0] * $dimensions[1] > 4_000_000) {
            return null;
        }

        $image = @imagecreatefromwebp($source);
        if ($image === false) {
            return null;
        }

        $temporary = @tempnam($this->media->ensureDirectory($directory), '.email-logo-');
        if ($temporary === false) {
            return null;
        }

        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);

            if (!@imagepng($image, $temporary) || !@filesize($temporary) ||
                !@chmod($temporary, 0644) || !@rename($temporary, $destination)) {
                return null;
            }

            return $png;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
