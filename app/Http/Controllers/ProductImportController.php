<?php

namespace App\Http\Controllers;

use App\Services\ProductInventoryImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
                'Content-Disposition' => 'attachment; filename="Plantilla_Importacion_Productos_Inventario_Zapateria_Angelita_V2.xlsx"',
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
        $mappings = $this->validatedMappings($request);

        try {
            return response()->json($this->importer->preview($file, $mappings));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function commit(Request $request): JsonResponse
    {
        $file = $this->validatedFile($request);
        $mappings = $this->validatedMappings($request);
        $request->validate(['import_valid_only' => ['sometimes', 'boolean']]);

        try {
            return response()->json($this->importer->commit($file, $request->user()?->id, $mappings, $request->boolean('import_valid_only')));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'preview' => $this->safePreview($file, $mappings),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error inesperado al importar inventario', [
                'user_id' => $request->user()?->id,
                'file' => $file->getClientOriginalName(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'No se pudo completar la importación. No se aplicaron cambios parciales.',
            ], 500);
        }
    }

    /**
     * Las correspondencias no crean catálogos ni permiten saltar validaciones.
     * Siempre se vuelven a comprobar contra BD al confirmar el commit.
     */
    private function validatedMappings(Request $request): array
    {
        $payload = $request->input('catalog_mappings', '{}');
        $decoded = is_array($payload) ? $payload : json_decode((string) $payload, true);
        if (!is_array($decoded) || count($decoded) > 3) {
            abort(422, 'Correspondencias de catálogo inválidas.');
        }

        $result = [];
        foreach (['brands', 'categories', 'subcategories'] as $type) {
            $list = $decoded[$type] ?? [];
            if (!is_array($list) || count($list) > 500) {
                abort(422, 'Demasiadas correspondencias de catálogo.');
            }
            foreach ($list as $source => $target) {
                if (!is_string($source) || !is_string($target)
                    || mb_strlen($source) > 520 || trim($source) === ''
                    || mb_strlen($target) > 255 || trim($target) === '') {
                    abort(422, 'Correspondencia de catálogo inválida.');
                }
                $result[$type][$source] = trim($target);
            }
        }
        return $result;
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

    private function safePreview(\Illuminate\Http\UploadedFile $file, array $mappings = []): ?array
    {
        try {
            return $this->importer->preview($file, $mappings);
        } catch (\Throwable) {
            return null;
        }
    }
}
