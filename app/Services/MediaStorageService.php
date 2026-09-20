<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MediaStorageService
{
    public function root(): string
    {
        $root = trim((string) config('media.root'));

        if ($root === '') {
            throw new RuntimeException('MEDIA_ROOT no está configurado.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function urlPrefix(): string
    {
        return '/' . trim((string) config('media.url_prefix', '/api/media'), '/');
    }

    public function normalizeRelativePath(?string $reference): ?string
    {
        if (!$reference) {
            return null;
        }

        $isAbsoluteUrl = preg_match('#^https?://#i', $reference) === 1;
        $path = parse_url($reference, PHP_URL_PATH) ?: $reference;
        $path = '/' . ltrim($path, '/');

        $prefix = $this->urlPrefix() . '/';

        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        } elseif (str_starts_with($path, '/storage/')) {
            $path = substr($path, strlen('/storage/'));
        } elseif (str_starts_with($path, '/uploads/')) {
            $path = ltrim($path, '/');
        } elseif ($isAbsoluteUrl) {
            return null;
        } else {
            $path = ltrim($path, '/');
        }

        if (
            $path === '' ||
            str_contains($path, '..') ||
            !preg_match('#^[A-Za-z0-9_./-]+$#', $path)
        ) {
            return null;
        }

        return $path;
    }

    public function absolutePath(string $relativePath): string
    {
        $relativePath = $this->normalizeRelativePath($relativePath)
            ?? throw new RuntimeException('Ruta de media inválida.');

        return $this->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function publicUrl(string $relativePath): string
    {
        $relativePath = $this->normalizeRelativePath($relativePath)
            ?? throw new RuntimeException('Ruta de media inválida.');

        return $this->urlPrefix() . '/' . $relativePath;
    }

    public function ensureDirectory(string $relativeDirectory): string
    {
        $relativeDirectory = trim($relativeDirectory, '/');
        $directory = $this->root()
            . ($relativeDirectory !== ''
                ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory)
                : '');

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio persistente de media: ' . $directory);
        }

        return $directory;
    }

    public function storeOptimizedImage(
        UploadedFile $file,
        string $subfolder,
        string $prefix,
        int $maxWidth,
        int $quality,
    ): string {
        $relativeDirectory = 'uploads/' . trim($subfolder, '/');
        $directory = $this->ensureDirectory($relativeDirectory);

        $mime = (string) $file->getMimeType();
        $sourcePath = $file->getPathname();
        $filename = uniqid($prefix, true) . '.webp';
        $finalPath = $directory . DIRECTORY_SEPARATOR . $filename;

        $image = null;
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $image = @imagecreatefromjpeg($sourcePath);
        } elseif ($mime === 'image/png') {
            $image = @imagecreatefrompng($sourcePath);
        } elseif ($mime === 'image/webp') {
            $image = @imagecreatefromwebp($sourcePath);
        }

        if ($image && function_exists('imagewebp')) {
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = (int) round(($height / $width) * $newWidth);
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled(
                    $resized,
                    $image,
                    0,
                    0,
                    0,
                    0,
                    $newWidth,
                    $newHeight,
                    $width,
                    $height,
                );
                imagedestroy($image);
                $image = $resized;
            }

            if (!imagewebp($image, $finalPath, max(1, min(100, $quality)))) {
                imagedestroy($image);
                throw new RuntimeException('No se pudo guardar la imagen optimizada.');
            }

            imagedestroy($image);
        } else {
            if ($image) {
                imagedestroy($image);
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $extension = 'bin';
            }

            $filename = uniqid($prefix, true) . '.' . $extension;
            $finalPath = $directory . DIRECTORY_SEPARATOR . $filename;

            if (!@copy($sourcePath, $finalPath)) {
                throw new RuntimeException('No se pudo guardar el archivo multimedia.');
            }
        }

        return $this->publicUrl($relativeDirectory . '/' . $filename);
    }

    public function resolveForRead(string $reference): ?string
    {
        $relativePath = $this->normalizeRelativePath($reference);
        if (!$relativePath) {
            return null;
        }

        $persistent = $this->absolutePath($relativePath);
        if (is_file($persistent)) {
            return $persistent;
        }

        foreach ($this->legacyCandidates($relativePath) as $legacy) {
            if (!is_file($legacy)) {
                continue;
            }

            try {
                $directory = dirname($persistent);
                if (!is_dir($directory)) {
                    @mkdir($directory, 0755, true);
                }

                if (is_dir($directory) && @copy($legacy, $persistent)) {
                    Log::info('MEDIA_LAZY_MIGRATED_TO_PERSISTENT_ROOT', [
                        'relative_path' => $relativePath,
                    ]);

                    return $persistent;
                }
            } catch (\Throwable $e) {
                Log::warning('MEDIA_LAZY_MIGRATION_FAILED', [
                    'relative_path' => $relativePath,
                    'message' => $e->getMessage(),
                ]);
            }

            return $legacy;
        }

        return null;
    }

    public function migrateReferencedMedia(): array
    {
        $references = [];

        if (Schema::hasTable('products')) {
            DB::table('products')
                ->orderBy('id')
                ->get(['img', 'images', 'color_images'])
                ->each(function ($product) use (&$references) {
                    $this->appendReference($references, $product->img ?? null);
                    $this->appendJsonReferences($references, $product->images ?? null);
                    $this->appendJsonReferences($references, $product->color_images ?? null);
                });
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('key', ['logo_url', 'homepage_banners'])
                ->get(['key', 'value'])
                ->each(function ($setting) use (&$references) {
                    if ($setting->key === 'logo_url') {
                        $this->appendReference($references, $setting->value);
                        return;
                    }

                    $this->appendJsonReferences($references, $setting->value);
                });
        }

        $references = array_values(array_unique(array_filter($references)));
        $stats = [
            'total_references' => count($references),
            'already_persistent' => 0,
            'migrated' => 0,
            'legacy_only' => 0,
            'missing' => 0,
            'missing_paths' => [],
        ];

        foreach ($references as $reference) {
            $relative = $this->normalizeRelativePath($reference);
            if (!$relative) {
                continue;
            }

            $persistent = $this->absolutePath($relative);
            if (is_file($persistent)) {
                $stats['already_persistent']++;
                continue;
            }

            $resolved = $this->resolveForRead($reference);
            if (!$resolved) {
                $stats['missing']++;
                if (count($stats['missing_paths']) < 100) {
                    $stats['missing_paths'][] = $reference;
                }
                continue;
            }

            if (is_file($persistent)) {
                $stats['migrated']++;
            } else {
                $stats['legacy_only']++;
            }
        }

        return $stats;
    }

    private function appendJsonReferences(array &$references, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                $this->appendReference($references, $value);
                return;
            }
        }

        if (is_array($value)) {
            array_walk_recursive($value, function ($reference) use (&$references) {
                $this->appendReference($references, $reference);
            });
        }
    }

    private function appendReference(array &$references, mixed $reference): void
    {
        if (!is_string($reference) || trim($reference) === '') {
            return;
        }

        if ($this->normalizeRelativePath($reference)) {
            $references[] = $reference;
        }
    }

    public function deleteReference(?string $reference): bool
    {
        $relativePath = $this->normalizeRelativePath($reference);
        if (!$relativePath) {
            return false;
        }

        $deleted = false;
        $paths = array_merge([$this->absolutePath($relativePath)], $this->legacyCandidates($relativePath));

        foreach (array_unique($paths) as $path) {
            if (is_file($path)) {
                $deleted = @unlink($path) || $deleted;
            }
        }

        return $deleted;
    }

    private function legacyCandidates(string $relativePath): array
    {
        return [
            storage_path('app/public/' . $relativePath),
            public_path($relativePath),
        ];
    }
}
