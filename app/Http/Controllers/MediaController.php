<?php

namespace App\Http\Controllers;

use App\Services\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function __construct(private readonly MediaStorageService $media)
    {
    }

    public function migrateReferencedMedia(): JsonResponse
    {
        $stats = $this->media->migrateReferencedMedia();

        return response()->json([
            'success' => $stats['missing'] === 0,
            'message' => $stats['missing'] === 0
                ? 'Multimedia consolidada en almacenamiento persistente.'
                : 'La consolidación terminó con referencias faltantes. Revisa missing_paths.',
            'data' => $stats,
        ], $stats['missing'] === 0 ? 200 : 207);
    }

    public function show(string $path): BinaryFileResponse|Response
    {
        $resolved = $this->media->resolveForRead($path);

        if (!$resolved || !is_file($resolved)) {
            abort(404);
        }

        return response()->file($resolved, [
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
