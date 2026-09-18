<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function show(string $path): BinaryFileResponse|Response
    {
        $relativePath = ltrim($path, '/');

        if (
            $relativePath === '' ||
            str_contains($relativePath, '..') ||
            !preg_match('#^[A-Za-z0-9_./-]+$#', $relativePath)
        ) {
            abort(404);
        }

        $disk = Storage::disk('public');

        // Compatibilidad: al primer acceso copia una imagen antigua de public/uploads
        // al almacenamiento persistente. Después, las actualizaciones de código ya no
        // afectan el archivo.
        if (!$disk->exists($relativePath) && str_starts_with($relativePath, 'uploads/')) {
            $legacyRoot = realpath(public_path('uploads'));
            $legacyFile = realpath(public_path($relativePath));

            if (
                $legacyRoot &&
                $legacyFile &&
                str_starts_with($legacyFile, $legacyRoot . DIRECTORY_SEPARATOR) &&
                is_file($legacyFile)
            ) {
                $disk->put($relativePath, file_get_contents($legacyFile));
            }
        }

        if (!$disk->exists($relativePath)) {
            abort(404);
        }

        $fullPath = $disk->path($relativePath);

        return response()->file($fullPath, [
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
