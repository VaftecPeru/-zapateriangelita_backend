<?php

namespace App\Http\Controllers;

use App\Services\MediaStorageService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function __construct(private readonly MediaStorageService $media)
    {
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
