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

    public function template(): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $binary = $this->importer->template();

            return response($binary, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="Plantilla_Importacion_Productos_Inventario_Zapateria_Angelita_V1.xlsx"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
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
