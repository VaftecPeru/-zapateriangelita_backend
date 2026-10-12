<?php

namespace App\Http\Controllers;

use App\Services\ProductInventoryImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductImportController extends Controller
{
    public function __construct(
        private readonly ProductInventoryImportService $importer,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        $file = $this->validatedFile($request);

        try {
            return response()->json($this->importer->preview($file));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function commit(Request $request): JsonResponse
    {
        $file = $this->validatedFile($request);

        try {
            return response()->json($this->importer->commit($file));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'preview' => $this->safePreview($file),
            ], 422);
        }
    }

    private function validatedFile(Request $request): \Illuminate\Http\UploadedFile
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $validated['file'];
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            abort(422, 'Formato no permitido. Usa XLSX o CSV.');
        }

        return $file;
    }

    private function safePreview(\Illuminate\Http\UploadedFile $file): ?array
    {
        try {
            return $this->importer->preview($file);
        } catch (\Throwable) {
            return null;
        }
    }
}
