<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductSize;
use App\Models\ProductVariantStock;
use App\Models\Subcategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ProductInventoryImportService
{
    private const MAX_MEANINGFUL_ROWS = 5000;
    private const MAX_XLSX_XML_BYTES = 25_000_000;

    // El formato admite campos opcionales; basta una columna identificable.
    // Una fila totalmente vacía nunca produce un producto.
    private const RECOGNIZED_ANCHORS = ['codigo_producto', 'nombre', 'modelo', 'marca'];

    public function template(): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('El servidor no tiene habilitada la extensión ZIP requerida para generar XLSX.');
        }

        $path = tempnam(sys_get_temp_dir(), 'angelita_template_');
        if ($path === false) {
            throw new RuntimeException('No se pudo crear la plantilla temporal.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('No se pudo generar la plantilla XLSX.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '</Types>');

            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>');

            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="IMPORTAR_PRODUCTOS" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>');

            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>');

            $rows = [
                ['ZAPATERÍA ANGELITA — PLANTILLA DE IMPORTACIÓN DE PRODUCTOS E INVENTARIO'],
                ['Plantilla V3 · Todos los campos son opcionales. Datos faltantes = borrador; cantidades sin desglose quedan sin talla asignada. Los códigos de libreta jamás se sustituyen.'],
                [],
                [
                    'ACCIÓN', 'CÓDIGO_PRODUCTO', 'NOMBRE', 'MODELO', 'MARCA',
                    'CATEGORÍA', 'SUBCATEGORÍA', 'PRECIO', 'PRECIO_OFERTA', 'MATERIAL',
                    'COLOR', 'TALLA', 'STOCK', 'STOCK_POR_TALLA', 'DESCRIPCIÓN', 'IMAGEN_PRINCIPAL',
                    'IMAGEN_2', 'IMAGEN_3', 'ESTADO', 'OBSERVACIONES', 'VALIDACIÓN',
                ],
            ];

            $sheet = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

            foreach ($rows as $rowIndex => $values) {
                $rowNumber = $rowIndex + 1;
                $sheet .= '<row r="' . $rowNumber . '">';

                foreach ($values as $columnIndex => $value) {
                    if ($value === '') {
                        continue;
                    }

                    $reference = $this->columnLetters($columnIndex + 1) . $rowNumber;
                    $sheet .= '<c r="' . $reference . '" t="inlineStr"><is><t>'
                        . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8')
                        . '</t></is></c>';
                }

                $sheet .= '</row>';
            }

            $sheet .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        } finally {
            $zip->close();
        }

        $binary = file_get_contents($path);
        @unlink($path);

        if ($binary === false) {
            throw new RuntimeException('No se pudo leer la plantilla generada.');
        }

        return $binary;
    }

    public function preview(UploadedFile $file, array $mappings = []): array
    {
        $parsed = $this->readFile($file);

        return $this->analyzeRows(
            $parsed['rows'],
            $parsed['format'],
            $parsed['header_row'],
            $mappings,
        );
    }

    public function commit(UploadedFile $file, ?int $userId = null, array $mappings = [], bool $validOnly = false): array
    {
        // El endpoint de commit recibe el archivo original y lo vuelve a
        // interpretar/validar. Nunca se confía en la vista previa del navegador.
        $preview = $this->preview($file, $mappings);

        if (!$preview['valid'] && !$validOnly) {
            throw new RuntimeException('El archivo contiene errores. Corrige la vista previa antes de importar.');
        }

        $eligibleGroups = array_values(array_filter(
            $preview['groups'],
            fn ($group) => $group['valid']
        ));
        if (!$eligibleGroups) {
            throw new RuntimeException('Ningún producto tiene datos de inventario válidos para importar.');
        }
        $skippedCodes = array_values(array_map(
            fn ($group) => $group['code'],
            array_filter($preview['groups'], fn ($group) => !$group['valid'])
        ));

        $this->assertImportSchemaReady();

        $result = DB::transaction(function () use ($eligibleGroups, $skippedCodes, $userId) {
            $created = 0;
            $updated = 0;
            $variants = 0;
            $units = 0;
            $published = 0;
            $drafts = 0;

            foreach ($eligibleGroups as $group) {
                $product = $this->persistGroup($group, $userId);
                if ($product->is_active) {
                    $published++;
                } else {
                    $drafts++;
                }

                if ($group['action'] === 'CREAR') {
                    $created++;
                } else {
                    $updated++;
                }

                $variants += count($group['variants']);
                $units += (int) $group['total_stock'];
            }

            return array_merge(
                compact('created', 'updated', 'variants', 'units', 'published', 'drafts'),
                ['skipped' => count($skippedCodes), 'skipped_codes' => $skippedCodes],
            );
        });

        return [
            'success' => true,
            'message' => 'Inventario importado correctamente.',
            'summary' => $result,
        ];
    }

    /**
     * Evita errores SQL opacos si se desplegó el código antes de ejecutar
     * las migraciones en producción. No modifica el esquema ni inventarios.
     */
    private function assertImportSchemaReady(): void
    {
        $required = [
            'products' => [
                'product_code', 'name', 'model', 'brand_id', 'category_id',
                'subcategory_id', 'price', 'stock', 'size', 'color',
                'status', 'is_active', 'auto_publish', 'img', 'images',
                'color_images', 'color_sizes',
            ],
            'product_variant_stocks' => ['product_id', 'color', 'size', 'stock'],
            'product_sizes' => ['product_id', 'size', 'stock'],
            'product_colors' => ['product_id', 'color', 'hex'],
            'inventory_movements' => [
                'product_id', 'user_id', 'color', 'size', 'type',
                'quantity_change', 'previous_stock', 'new_stock', 'reason',
            ],
        ];

        $missing = [];
        foreach ($required as $table => $columns) {
            if (!Schema::hasTable($table)) {
                $missing[] = $table . ' (tabla ausente)';
                continue;
            }

            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Esquema de producción pendiente de migración: ' . implode(', ', $missing)
                . '. Haz respaldo de la base de datos y ejecuta las migraciones Laravel '
                . '(php artisan migrate --force) antes de importar. El inventario no se ha modificado.'
            );
        }
    }

    private function readFile(UploadedFile $file): array
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            throw new RuntimeException('Formato no permitido. Usa XLSX o CSV.');
        }

        return $extension === 'xlsx'
            ? $this->readXlsx($file->getRealPath())
            : $this->readCsv($file->getRealPath());
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo leer el archivo CSV.');
        }

        try {
            $sampleLines = [];
            while (count($sampleLines) < 20 && ($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $sampleLines[] = $line;
                }
            }

            if (!$sampleLines) {
                throw new RuntimeException('El archivo está vacío.');
            }

            $delimiter = $this->detectCsvDelimiter($sampleLines);
            rewind($handle);

            $rawRows = [];
            $logicalRow = 0;

            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $logicalRow++;

                if ($logicalRow === 1 && isset($values[0])) {
                    $values[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', (string) $values[0]) ?? (string) $values[0];
                }

                $row = [];
                $hasContent = false;

                foreach ($values as $column => $value) {
                    $clean = trim((string) $value);
                    $row[$column] = $clean;
                    $hasContent = $hasContent || $clean !== '';
                }

                if ($hasContent) {
                    $rawRows[] = ['row' => $logicalRow, 'cells' => $row];
                }
            }
        } finally {
            fclose($handle);
        }

        if (!$rawRows) {
            throw new RuntimeException('El archivo está vacío.');
        }

        [$headerIndex, $headerMap] = $this->discoverHeader($rawRows);

        return [
            'format' => 'csv',
            'header_row' => $rawRows[$headerIndex]['row'],
            'rows' => $this->associateRows($rawRows, $headerIndex, $headerMap),
        ];
    }

    private function detectCsvDelimiter(array $sampleLines): string
    {
        $bestDelimiter = ',';
        $bestHeaderMatches = -1;
        $bestColumnCount = -1;

        foreach ([',', ';'] as $delimiter) {
            $headerMatches = 0;
            $maxColumnCount = 0;

            foreach ($sampleLines as $line) {
                $values = str_getcsv($line, $delimiter);
                $maxColumnCount = max($maxColumnCount, count($values));

                $matches = 0;
                foreach ($values as $value) {
                    if ($this->canonicalHeader($value) !== null) {
                        $matches++;
                    }
                }

                $headerMatches = max($headerMatches, $matches);
            }

            if (
                $headerMatches > $bestHeaderMatches
                || ($headerMatches === $bestHeaderMatches && $maxColumnCount > $bestColumnCount)
            ) {
                $bestDelimiter = $delimiter;
                $bestHeaderMatches = $headerMatches;
                $bestColumnCount = $maxColumnCount;
            }
        }

        return $bestDelimiter;
    }

    private function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('El servidor no tiene habilitada la extensión ZIP requerida para leer XLSX.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo XLSX.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->firstWorksheetPath($zip);
            $sheetXml = $this->readZipEntry($zip, $sheetPath);

            if ($sheetXml === false) {
                throw new RuntimeException('El XLSX no contiene una hoja válida.');
            }

            $xml = simplexml_load_string($sheetXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$xml instanceof SimpleXMLElement) {
                throw new RuntimeException('No se pudo interpretar la hoja del XLSX.');
            }

            $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $xml->registerXPathNamespace('m', $namespace);
            $rawRows = [];

            foreach ($xml->xpath('//m:sheetData/m:row') ?: [] as $rowNode) {
                $rowNode->registerXPathNamespace('m', $namespace);
                $rowNumber = (int) ($rowNode['r'] ?? (count($rawRows) + 1));
                $cells = [];

                foreach ($rowNode->xpath('./m:c') ?: [] as $cell) {
                    $cell->registerXPathNamespace('m', $namespace);
                    $reference = (string) ($cell['r'] ?? '');
                    $columnIndex = $this->columnIndex($reference);
                    if ($columnIndex < 0) {
                        continue;
                    }

                    $type = (string) ($cell['t'] ?? '');
                    $value = '';

                    if ($type === 'inlineStr') {
                        $texts = $cell->xpath('.//m:is//m:t') ?: [];
                        $value = implode('', array_map(fn ($item) => (string) $item, $texts));
                    } else {
                        $valueNodes = $cell->xpath('./m:v') ?: [];
                        $rawValue = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';

                        if ($type === 's' && $rawValue !== '') {
                            $value = $sharedStrings[(int) $rawValue] ?? '';
                        } else {
                            $value = $rawValue;
                        }
                    }

                    $cells[$columnIndex] = trim($value);
                }

                $rawRows[] = ['row' => $rowNumber, 'cells' => $cells];
            }

            if (!$rawRows) {
                throw new RuntimeException('La hoja de cálculo no contiene filas.');
            }

            [$headerIndex, $headerMap] = $this->discoverHeader($rawRows);

            return [
                'format' => 'xlsx',
                'header_row' => $rawRows[$headerIndex]['row'],
                'rows' => $this->associateRows($rawRows, $headerIndex, $headerMap),
            ];
        } finally {
            $zip->close();
        }
    }

    private function readZipEntry(ZipArchive $zip, string $path): string|false
    {
        $stat = $zip->statName($path);

        if ($stat !== false && (int) ($stat['size'] ?? 0) > self::MAX_XLSX_XML_BYTES) {
            throw new RuntimeException('El XLSX contiene una sección demasiado grande y fue bloqueado por seguridad.');
        }

        $content = $zip->getFromName($path);

        if (is_string($content)) {
            if (strlen($content) > self::MAX_XLSX_XML_BYTES) {
                throw new RuntimeException('El XLSX excede el tamaño interno permitido.');
            }

            if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
                throw new RuntimeException('El XLSX contiene XML no permitido.');
            }
        }

        return $content;
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $content = $this->readZipEntry($zip, 'xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }

        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$xml instanceof SimpleXMLElement) {
            return [];
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $xml->registerXPathNamespace('m', $namespace);
        $strings = [];

        foreach ($xml->xpath('//m:si') ?: [] as $item) {
            $item->registerXPathNamespace('m', $namespace);
            $texts = $item->xpath('.//m:t') ?: [];
            $strings[] = implode('', array_map(fn ($text) => (string) $text, $texts));
        }

        return $strings;
    }

    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbookContent = $this->readZipEntry($zip, 'xl/workbook.xml');
        $relationsContent = $this->readZipEntry($zip, 'xl/_rels/workbook.xml.rels');

        if ($workbookContent === false || $relationsContent === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = simplexml_load_string($workbookContent, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $relations = simplexml_load_string($relationsContent, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        if (!$workbook instanceof SimpleXMLElement || !$relations instanceof SimpleXMLElement) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = $workbook->xpath('//m:sheets/m:sheet') ?: [];

        if (!$sheets) {
            return 'xl/worksheets/sheet1.xml';
        }

        $attributes = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($attributes['id'] ?? '');

        $relations->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($relations->xpath('//r:Relationship') ?: [] as $relation) {
            if ((string) $relation['Id'] !== $relationshipId) {
                continue;
            }

            $target = ltrim((string) $relation['Target'], '/');
            if (str_starts_with($target, 'xl/')) {
                return $target;
            }

            return 'xl/' . preg_replace('#^(\.\./)+#', '', $target);
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function columnLetters(int $column): string
    {
        $letters = '';

        while ($column > 0) {
            $column--;
            $letters = chr(65 + ($column % 26)) . $letters;
            $column = intdiv($column, 26);
        }

        return $letters;
    }

    private function columnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return -1;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    private function discoverHeader(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $headerMap = [];

            foreach ($row['cells'] as $column => $value) {
                $canonical = $this->canonicalHeader($value);
                if ($canonical !== null) {
                    $headerMap[$canonical] = $column;
                }
            }

            $found = array_keys($headerMap);
            if (count($found) >= 3 || count(array_intersect(self::RECOGNIZED_ANCHORS, $found)) >= 1) {
                return [$index, $headerMap];
            }
        }

        throw new RuntimeException(
            'No se encontraron encabezados reconocibles. Usa al menos una columna como CÓDIGO_PRODUCTO, NOMBRE, MODELO o MARCA.'
        );
    }

    private function associateRows(array $rows, int $headerIndex, array $headerMap): array
    {
        $result = [];

        for ($index = $headerIndex + 1; $index < count($rows); $index++) {
            $source = $rows[$index];
            $data = [];

            foreach ($headerMap as $field => $column) {
                $data[$field] = $source['cells'][$column] ?? '';
            }

            $result[] = [
                'row' => $source['row'],
                'data' => $data,
            ];
        }

        return $result;
    }

    private function canonicalHeader(mixed $value): ?string
    {
        $key = $this->normalizeKey((string) $value);

        $aliases = [
            'accion' => ['accion', 'action'],
            'codigo_producto' => ['codigo_producto', 'codigo', 'sku', 'codigo_de_producto'],
            'nombre' => ['nombre', 'producto', 'nombre_producto', 'product'],
            'modelo' => ['modelo', 'model'],
            'marca' => ['marca', 'brand'],
            'categoria' => ['categoria', 'category'],
            'subcategoria' => ['subcategoria', 'subcategory'],
            'precio' => ['precio', 'precio_mxn', 'price'],
            'precio_oferta' => ['precio_oferta', 'precio_descuento', 'discounted_price'],
            'material' => ['material'],
            'color' => ['color', 'colores', 'colors'],
            'talla' => ['talla', 'tallas', 'size', 'sizes'],
            'stock' => ['stock', 'stock_talla', 'cantidad', 'inventario'],
            'stock_por_talla' => ['stock_por_talla', 'desglose_tallas', 'existencias_por_talla'],
            'descripcion' => ['descripcion', 'description'],
            'imagen_principal' => ['imagen_principal', 'imagen_1', 'img'],
            'imagen_2' => ['imagen_2'],
            'imagen_3' => ['imagen_3'],
            'estado' => ['estado', 'status'],
            'observaciones' => ['observaciones', 'observacion', 'notes', 'nota'],
            'validacion' => ['validacion', 'validation'],
        ];

        foreach ($aliases as $canonical => $accepted) {
            if (in_array($key, $accepted, true)) {
                return $canonical;
            }
        }

        return null;
    }

    private function analyzeRows(array $rows, string $format, int $headerRow, array $mappings = []): array
    {
        $categoryLookup = Category::query()->where('is_active', true)->get(['id', 'name'])->keyBy(
            fn ($item) => $this->categoryAlias($item->name)
        );
        $brandLookup = Brand::query()->where('is_active', true)->get(['id', 'name'])->keyBy(
            fn ($item) => $this->normalizeText($item->name)
        );
        $subcategories = Subcategory::query()->where('is_active', true)->get(['id', 'category_id', 'name']);

        $existingProducts = Product::query()
            ->whereNotNull('product_code')
            ->get(['id', 'product_code', 'status', 'img', 'images', 'color_images'])
            ->keyBy(fn ($product) => $this->normalizeText($product->product_code));

        $groupDefaults = $this->generalDefaultsByCode($rows);
        $sharedModelPrices = $this->sharedPricesByModel($rows, $mappings);
        $normalizedRows = [];
        $meaningfulCount = 0;

        foreach ($rows as $source) {
            $raw = $source['data'];

            if (!$this->isMeaningfulRow($raw)) {
                continue;
            }

            $meaningfulCount++;
            if ($meaningfulCount > self::MAX_MEANINGFUL_ROWS) {
                throw new RuntimeException('El archivo supera el máximo de ' . self::MAX_MEANINGFUL_ROWS . ' filas de inventario.');
            }

            $errors = [];
            $warnings = [];

            $code = trim((string) ($raw['codigo_producto'] ?? ''));
            $defaults = $code !== ''
                ? ($groupDefaults[$this->normalizeText($code)] ?? [])
                : [];
            $generalValue = fn (string $field) => $this->valueOrDefault(
                $raw[$field] ?? null,
                $defaults[$field] ?? null,
            );

            $actionInput = trim((string) $generalValue('accion'));
            $action = $actionInput === ''
                ? ($code !== '' && $existingProducts->has($this->normalizeText($code)) ? 'ACTUALIZAR' : 'CREAR')
                : $this->normalizeAction($actionInput);
            $sourceName = trim((string) $generalValue('nombre'));
            $namePending = $sourceName === '';
            $name = $namePending
                ? 'Producto por identificar · ' . ($code !== '' ? $code : 'fila ' . $source['row'])
                : $sourceName;
            if ($namePending) { $warnings[] = 'Nombre comercial pendiente: nombre interno de borrador'; }
            if ($code === '') { $warnings[] = 'Código original pendiente: queda vacío, sin crear códigos artificiales'; }
            if ($actionInput === '') { $warnings[] = 'ACCIÓN detectada automáticamente: ' . $action; }
            $model = trim((string) $generalValue('modelo'));
            $brandName = $this->catalogMappedName(trim((string) $generalValue('marca')), $mappings['brands'] ?? []);
            $categoryName = $this->canonicalCategoryName($this->catalogMappedName(trim((string) $generalValue('categoria')), $mappings['categories'] ?? []));
            $subcategoryName = $this->catalogMappedName(
                trim((string) $generalValue('subcategoria')),
                $mappings['subcategories'] ?? [],
                $this->normalizeText($categoryName) . '|'
            );
            $material = trim((string) $generalValue('material'));
            $color = trim((string) ($raw['color'] ?? ''));
            $size = trim((string) ($raw['talla'] ?? ''));
            $description = trim((string) $generalValue('descripcion'));
            $observations = trim((string) ($raw['observaciones'] ?? ''));
            $validation = trim((string) ($raw['validacion'] ?? ''));
            $state = mb_strtoupper($this->normalizeText((string) $generalValue('estado')));
            if ($state === '') { $state = 'BORRADOR'; $warnings[] = 'ESTADO vacío: se conserva como borrador'; }

            $rawPrice = $generalValue('precio');
            $price = $this->decimalValue($rawPrice);
            if (trim((string) ($rawPrice ?? '')) !== '' && ($price === null || $price < 0)) {
                $errors[] = 'PRECIO debe ser un número mayor o igual a 0';
            }
            // Precio único del modelo: se hereda a todos sus colores si el
            // archivo contiene un único precio positivo para marca + categoría + modelo.
            // Si varios colores declaran precios diferentes, no se hereda.
            if (($price === null || $price === 0.0) && $model !== '' && $brandName !== '') {
                $modelKey = $this->modelPriceKey($brandName, $categoryName, $model);
                if (isset($sharedModelPrices[$modelKey]) && $sharedModelPrices[$modelKey] > 0) {
                    $price = $sharedModelPrices[$modelKey];
                    $warnings[] = 'Precio MXN aplicado desde el modelo ' . $model . ' a este color';
                }
            }
            $price ??= 0.0;
            $offerSource = $generalValue('precio_oferta');
            $offerRaw = trim((string) ($offerSource ?? ''));
            $offerPrice = $offerRaw === '' ? null : $this->decimalValue($offerSource);
            $stockWasMissing = trim((string) ($raw['stock'] ?? '')) === '';
            $stock = $stockWasMissing ? null : $this->integerValue($raw['stock'] ?? null);

            if (!in_array($action, ['CREAR', 'ACTUALIZAR'], true)) {
                $errors[] = 'ACCIÓN debe ser CREAR o ACTUALIZAR';
            }
            if ($code !== '' && mb_strlen($code) > 100) {
                $errors[] = 'CÓDIGO_PRODUCTO supera 100 caracteres';
            }
            if (mb_strlen($name) > 255) {
                $errors[] = 'NOMBRE supera 255 caracteres';
            }
            if (mb_strlen($model) > 120) {
                $errors[] = 'MODELO supera 120 caracteres';
            }

            $categoryNormalized = $this->categoryAlias($categoryName);
            $category = $categoryName === ''
                ? null
                : $categoryLookup->get($categoryNormalized);
            if ($categoryName !== '' && !$category) {
                $warnings[] = 'Categoría pendiente: ' . $categoryName . '. Producto guardado como borrador.';
            }

            $brand = $brandLookup->get($this->brandAlias($brandName));
            if ($brandName === '') {
                $warnings[] = 'Marca pendiente; borrador';
            } elseif (!$brand) {
                $warnings[] = 'Marca no registrada: ' . $brandName . '. Se guarda sin vincular el catálogo.';
            } elseif ($this->normalizeText($brandName) !== $this->normalizeText($brand->name)) {
                $warnings[] = 'Marca normalizada: ' . $brandName . ' → ' . $brand->name;
            }

            $subcategory = null;
            if ($subcategoryName !== '' && $category) {
                $subcategory = $subcategories->first(fn ($item) =>
                    (int) $item->category_id === (int) $category->id
                    && $this->normalizeText($item->name) === $this->normalizeText($subcategoryName)
                );

                if (!$subcategory) {
                    $warnings[] = 'Subcategoría pendiente: ' . $subcategoryName . ' dentro de ' . $category->name;
                }
            } elseif ($subcategoryName !== '' && $categoryName !== '') {
                $warnings[] = 'Subcategoría pendiente: ' . $subcategoryName . '. Primero se debe aprobar la categoría ' . $categoryName;
            } elseif ($subcategoryName !== '') {
                $warnings[] = 'Subcategoría sin categoría padre: queda pendiente de vincular';
            }

            if ($price == 0.0) {
                $warnings[] = 'Precio pendiente: se importa con 0 MXN y permanece en borrador';
            }
            if ($offerRaw !== '' && ($offerPrice === null || $offerPrice <= 0)) {
                $errors[] = 'PRECIO_OFERTA debe ser numérico y mayor que 0';
            } elseif ($offerPrice !== null && $price !== null && $offerPrice >= $price) {
                $errors[] = 'PRECIO_OFERTA debe ser menor que PRECIO';
            }

            if ($color === '') {
                $warnings[] = 'COLOR pendiente: stock sin asignación de variante';
            } elseif (mb_strlen($color) > 50) {
                $errors[] = 'COLOR supera 50 caracteres';
            }
            // TALLA puede contener una lista mayor de 10 caracteres (ej. 18,19,20,21).
            // Cada talla INDIVIDUAL debe caber en product_sizes.size y
            // product_variant_stocks.size: VARCHAR(10) en MariaDB histórico.
            if ($size === '') { $warnings[] = 'TALLA pendiente: stock sin asignación de variante'; }
            if (!$stockWasMissing && ($stock === null || $stock < 0)) {
                $errors[] = 'STOCK debe ser un entero mayor o igual a 0';
            }

            // La lista de tallas nunca se persiste como una talla literal.
            // Requiere desglose explícito para evitar asignar stock arbitrariamente.
            $breakdown = trim((string) ($raw['stock_por_talla'] ?? ''));
            $sizeTokens = array_map('trim', explode(',', $size));
            foreach ($sizeTokens as $atomicSize) {
                if ($atomicSize !== '' && mb_strlen($atomicSize) > 10) {
                    $errors[] = 'Cada talla individual debe tener máximo 10 caracteres (límite de BD)';
                    break;
                }
            }
            $expandedVariants = [];
            $unallocatedStock = 0;
            if ($breakdown === '' && count($sizeTokens) > 1) {
                $warnings[] = 'Stock total sin desglose por talla: conservar sin asignar y NO publicar';
                $unallocatedStock = max(0, (int) ($stock ?? 0));
            } elseif ($breakdown !== '') {
                $seenSizes = [];
                $quantities = [];
                foreach (explode(';', $breakdown) as $entry) {
                    $entry = trim($entry);
                    if (!preg_match('/^([^:;]+):([0-9]+)$/u', $entry, $parts)) {
                        $errors[] = 'STOCK_POR_TALLA inválido: usa TALLA:CANTIDAD separadas por punto y coma';
                        continue;
                    }
                    $entrySize = trim($parts[1]);
                    $entryKey = $this->normalizeText($entrySize);
                    if ($entrySize === '' || mb_strlen($entrySize) > 10 || isset($seenSizes[$entryKey])) {
                        $errors[] = 'STOCK_POR_TALLA contiene una talla vacía, repetida o demasiado larga';
                        continue;
                    }
                    $seenSizes[$entryKey] = true;
                    $quantity = filter_var($parts[2], FILTER_VALIDATE_INT);
                    if ($quantity === false || $quantity < 0) {
                        $errors[] = 'STOCK_POR_TALLA contiene una cantidad inválida';
                        continue;
                    }
                    $quantities[$entryKey] = $quantity;
                    $expandedVariants[] = ['color' => $color, 'size' => $entrySize, 'stock' => $quantity];
                }
                $declaredSizes = array_map(fn ($item) => $this->normalizeText($item), $sizeTokens);
                $declaredSizes = array_filter($declaredSizes, fn ($item) => $item !== '');
                if (count($declaredSizes) !== count($sizeTokens)
                    || count(array_unique($declaredSizes)) !== count($declaredSizes)
                    || array_diff($declaredSizes, array_keys($quantities))
                    || array_diff(array_keys($quantities), $declaredSizes)) {
                    $errors[] = 'Las tallas de STOCK_POR_TALLA deben coincidir exactamente con TALLA';
                }
                if ($stockWasMissing) {
                    $stock = array_sum($quantities);
                    $warnings[] = 'STOCK calculado a partir del desglose real por talla';
                }
                if ($stock !== null && array_sum($quantities) !== $stock) {
                    $errors[] = 'STOCK no coincide con la suma de STOCK_POR_TALLA';
                }
            } elseif ($size !== '' && $color !== '') {
                $expandedVariants[] = ['color' => $color, 'size' => $size, 'stock' => $stock ?? 0];
            } else {
                $unallocatedStock = max(0, (int) ($stock ?? 0));
            }
            if ($color === '' && $expandedVariants) {
                $expandedVariants = [];
                $unallocatedStock = max(0, (int) ($stock ?? 0));
            }
            if ($stockWasMissing && $breakdown === '') {
                $warnings[] = 'STOCK vacío: se registra 0 hasta conocer las cantidades';
            }
            $stock ??= 0;

            if (!in_array($state, ['ACTIVO', 'INACTIVO', 'ACTIVE', 'INACTIVE', 'BORRADOR', 'DRAFT'], true)) {
                $warnings[] = 'ESTADO desconocido; se usará BORRADOR';
                $state = 'BORRADOR';
            }
            $autoPublish = in_array($state, ['ACTIVO', 'ACTIVE'], true);
            $isActive = false; // Nunca publicar antes de guardar y validar inventario.


            if (str_contains($this->normalizeText($observations), 'ejemplo')) {
                $errors[] = 'Elimina las filas de EJEMPLO antes de importar';
            }

            $images = [];
            foreach (['imagen_principal', 'imagen_2', 'imagen_3'] as $imageField) {
                $image = trim((string) ($raw[$imageField] ?? ''));
                if ($image === '') {
                    continue;
                }

                $normalizedImage = $this->normalizeImageReference($image);
                if ($normalizedImage === null) {
                    $warnings[] = 'Imagen no importada (' . $image . '): usa URL https:// o ruta /uploads/...';
                } else {
                    $images[] = $normalizedImage;
                }
            }

            if ($images === []) {
                $warnings[] = 'Imagen opcional pendiente: puedes añadirla después sin bloquear la publicación';
            }

            $normalizedRows[] = [
                'row' => (int) $source['row'],
                'action' => $action,
                'code' => $code,
                'name' => $name,
                'name_pending' => $namePending,
                'unallocated_stock' => $unallocatedStock,
                'catalog_pending' => ($brandName !== '' && !$brand) || ($categoryName !== '' && !$category) || ($subcategoryName !== '' && !$subcategory),
                'model' => $model,
                'brand' => $brand?->name ?? $brandName,
                'brand_id' => $brand?->id,
                'category' => $category?->name ?? $categoryName,
                'category_id' => $category?->id,
                'subcategory' => $subcategoryName,
                'subcategory_id' => $subcategory?->id,
                'price' => $price,
                'offer_price' => $offerPrice,
                'material' => $material,
                'color' => $color,
                'size' => $size,
                'stock' => $stock ?? 0,
                'stock_por_talla' => trim((string) ($raw['stock_por_talla'] ?? '')),
                'expanded_variants' => $expandedVariants,
                'description' => $description,
                'images' => array_values(array_unique($images)),
                'is_active' => $isActive,
                'auto_publish' => $autoPublish,
                'observations' => $observations,
                'validation' => $validation,
                'valid' => false,
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        if (!$normalizedRows) {
            throw new RuntimeException('No se encontraron filas de productos para importar.');
        }

        $groupedIndexes = [];
        foreach ($normalizedRows as $index => $row) {
            $groupKey = $this->normalizeText($row['code']);
            if ($groupKey === '') {
                $groupKey = '__fila_' . $row['row'];
            }
            $groupedIndexes[$groupKey][] = $index;
        }

        $groups = [];
        foreach ($groupedIndexes as $groupKey => $indexes) {
            $groupRows = array_map(fn ($index) => $normalizedRows[$index], $indexes);
            $first = $groupRows[0];
            $groupErrors = [];
            $groupWarnings = [];
            $seenVariants = [];

            foreach ($groupRows as $row) {
                foreach ($row['expanded_variants'] as $variant) {
                    $variantKey = $this->variantKey($variant['color'], $variant['size']);
                    if (isset($seenVariants[$variantKey])) {
                        $groupErrors[] = 'Variante duplicada: ' . $variant['color'] . ' / talla ' . $variant['size'];
                    }
                    $seenVariants[$variantKey] = true;
                }
                $groupWarnings = array_merge($groupWarnings, $row['warnings']);
            }

            foreach ($groupRows as $row) {
                if (!$this->sameGeneralData($first, $row)) {
                    $groupErrors[] = 'Los datos generales del mismo CÓDIGO_PRODUCTO deben coincidir en todas sus filas';
                    break;
                }
            }

            $existing = $existingProducts->get($this->normalizeText($first['code']));
            if ($first['action'] === 'CREAR' && $existing) {
                $groupErrors[] = 'CREAR no es válido: el código ya existe';
            }
            if ($first['action'] === 'ACTUALIZAR' && !$existing) {
                $groupErrors[] = 'ACTUALIZAR no es válido: el código no existe';
            }

            foreach ($groupRows as $row) {
                $groupErrors = array_merge($groupErrors, $row['errors']);
            }
            $groupErrors = array_values(array_unique($groupErrors));
            $groupWarnings = array_values(array_unique($groupWarnings));

            foreach ($indexes as $index) {
                if ($groupErrors) {
                    $normalizedRows[$index]['errors'] = array_values(array_unique(array_merge(
                        $normalizedRows[$index]['errors'],
                        $groupErrors,
                    )));
                }
                $normalizedRows[$index]['valid'] = count($normalizedRows[$index]['errors']) === 0;
            }

            $colors = [];
            $sizes = [];
            $colorSizes = [];
            $colorImages = [];
            $allImages = [];
            $variants = [];
            $totalStock = 0;
            $unallocatedTotal = 0;

            foreach ($groupRows as $row) {
                $unallocatedTotal += (int) ($row['unallocated_stock'] ?? 0);
                $totalStock += (int) ($row['unallocated_stock'] ?? 0);
                if ($row['color'] !== '' && !in_array($row['color'], $colors, true)) {
                    $colors[] = $row['color'];
                }
                if ($row['images']) {
                    $colorImages[$row['color']] = array_values(array_unique(array_merge(
                        $colorImages[$row['color']] ?? [],
                        $row['images'],
                    )));
                    $allImages = array_values(array_unique(array_merge($allImages, $row['images'])));
                }

                foreach ($row['expanded_variants'] as $variant) {
                    $variantSize = $variant['size'];
                    if (!in_array($variantSize, $sizes, true)) {
                        $sizes[] = $variantSize;
                    }
                    $colorSizes[$variant['color']] ??= [];
                    if (!in_array($variantSize, $colorSizes[$variant['color']], true)) {
                        $colorSizes[$variant['color']][] = $variantSize;
                    }
                    $variants[] = $variant;
                    $totalStock += (int) $variant['stock'];
                }
            }

            $willPublish = count($groupErrors) === 0
                && $unallocatedTotal === 0 && !$first['name_pending']
                && $first['code'] !== '' && !$first['catalog_pending']
                && count($variants) > 0 && $first['auto_publish'] && (float) $first['price'] > 0
                && $totalStock > 0
                && $first['brand_id'] && $first['category_id']
                && trim((string) $first['model']) !== '';
            foreach ($indexes as $index) {
                $normalizedRows[$index]['is_active'] = (bool) $willPublish;
            }

            $groups[] = [
                'code' => $first['code'],
                'action' => $first['action'],
                'name' => $first['name'],
                'name_pending' => $first['name_pending'],
                'unallocated_stock' => $unallocatedTotal,
                'import_pending_data' => [
                    'source_rows' => array_map(static fn ($r) => [
                        'row' => $r['row'], 'code' => $r['code'],
                        'brand' => $r['brand'], 'category' => $r['category'],
                        'subcategory' => $r['subcategory'],
                        'color' => $r['color'], 'size' => $r['size'],
                        'stock' => $r['stock'],
                        'unallocated_stock' => $r['unallocated_stock'],
                        'warnings' => $r['warnings'],
                    ], $groupRows),
                ],
                'model' => $first['model'],
                'brand' => $first['brand'],
                'brand_id' => $first['brand_id'],
                'category' => $first['category'],
                'category_id' => $first['category_id'],
                'subcategory' => $first['subcategory'],
                'subcategory_id' => $first['subcategory_id'],
                'price' => $first['price'],
                'offer_price' => $first['offer_price'],
                'material' => $first['material'],
                'description' => $first['description'],
                // Estado previsto para la vista previa. La decisión final se
                // revalida sobre el producto persistido mediante PublicationService.
                'is_active' => (bool) $willPublish,
                'auto_publish' => $first['auto_publish'],
                'colors' => $colors,
                'sizes' => $sizes,
                'color_sizes' => $colorSizes,
                'images' => $allImages,
                'color_images' => $colorImages,
                'variants' => $variants,
                'total_stock' => $totalStock,
                'existing_product_id' => $existing?->id,
                'valid' => count($groupErrors) === 0,
                'errors' => $groupErrors,
                'warnings' => $groupWarnings,
            ];
        }

        $errorRows = count(array_filter($normalizedRows, fn ($row) => !$row['valid']));
        $warningCount = array_sum(array_map(fn ($row) => count($row['warnings']), $normalizedRows));
        $validGroups = count(array_filter($groups, fn ($group) => $group['valid']));
        $readyGroups = count(array_filter($groups, fn ($group) => $group['valid'] && $group['is_active']));
        $draftGroups = $validGroups - $readyGroups;

        return [
            'valid' => $errorRows === 0 && $validGroups > 0,
            'format' => $format,
            'header_row' => $headerRow,
            'summary' => [
                'rows' => count($normalizedRows),
                'products' => count($groups),
                'variants' => array_sum(array_map(fn ($group) => count($group['variants']), $groups)),
                'valid_products' => $validGroups,
                'ready_products' => $readyGroups,
                'draft_products' => $draftGroups,
                'error_rows' => $errorRows,
                'warnings' => $warningCount,
            ],
            'rows' => $normalizedRows,
            'groups' => $groups,
        ];
    }

    private function persistGroup(array $group, ?int $userId): Product
    {
        $product = $group['action'] === 'ACTUALIZAR'
            ? Product::query()->whereRaw('LOWER(product_code) = ?', [mb_strtolower($group['code'])])->lockForUpdate()->firstOrFail()
            : new Product();

        $productExisted = $product->exists;
        $previousTotalStock = $productExisted ? (int) $product->stock : 0;
        $hadVariantInventory = $productExisted && $product->variantStocks()->exists();
        $previousVariants = [];

        if ($hadVariantInventory) {
            foreach ($product->variantStocks()->get(['color', 'size', 'stock']) as $variant) {
                $previousVariants[$this->variantKey($variant->color, $variant->size)] = [
                    'color' => (string) $variant->color,
                    'size' => (string) $variant->size,
                    'stock' => (int) $variant->stock,
                ];
            }
        }

        $price = (float) $group['price'];
        $offerPrice = $group['offer_price'] !== null ? (float) $group['offer_price'] : null;
        $discount = $offerPrice !== null
            ? round((($price - $offerPrice) / $price) * 100, 2)
            : null;

        $marketingStatus = $offerPrice !== null
            ? 'oferta'
            : (($product->exists && $product->status !== 'oferta') ? $product->status : 'normal');

        $resolvedCategoryId = $this->resolveCategoryId(
            $group['category'] ?? '',
            $group['category_id'] ?? null,
        );
        $resolvedSubcategoryId = $this->resolveSubcategoryId(
            $group['subcategory'] ?? '',
            $group['subcategory_id'] ?? null,
            $resolvedCategoryId,
        );

        $attributes = [
            'product_code' => $group['code'] !== '' ? $group['code'] : null,
            'name' => $group['name'],
            'model' => $group['model'] !== '' ? $group['model'] : null,
            'brand_id' => $group['brand_id'],
            'category_id' => $resolvedCategoryId ?? ($product->exists ? $product->category_id : null),
            'subcategory_id' => $resolvedCategoryId === null && $product->exists
                ? $product->subcategory_id
                : $resolvedSubcategoryId,
            'price' => $price,
            'discounted_price' => $offerPrice,
            'discount' => $discount,
            'stock' => (int) $group['total_stock'],
            'size' => implode('|', $group['sizes']),
            'color' => implode('|', $group['colors']),
            'material' => $group['material'] !== '' ? $group['material'] : null,
            'description' => $group['description'] !== '' ? $group['description'] : null,
            'status' => $marketingStatus,
            'is_active' => false,
            'auto_publish' => (bool) $group['auto_publish']
                && !$group['name_pending'] && $group['code'] !== ''
                && (int) $group['unallocated_stock'] === 0,
            'color_sizes' => $group['color_sizes'] ?: null,
            'import_pending_data' => $group['import_pending_data'],
        ];

        if ($group['images']) {
            $attributes['img'] = $group['images'][0];
            $attributes['images'] = $group['images'];
            $attributes['color_images'] = $group['color_images'] ?: null;
        } elseif (!$product->exists) {
            $attributes['img'] = null;
            $attributes['images'] = null;
            $attributes['color_images'] = null;
        }

        $product->fill($attributes);
        $product->save();

        $this->replaceVariantInventory($product, $group['variants'], (int) $group['unallocated_stock']);
        $this->recordInventoryImportMovements(
            $product,
            $productExisted,
            $hadVariantInventory,
            $previousTotalStock,
            $previousVariants,
            $group['variants'],
            $userId,
            $group['action'],
        );

        app(ProductPublicationService::class)->synchronize($product);
        return $product->fresh();
    }

    private function recordInventoryImportMovements(
        Product $product,
        bool $productExisted,
        bool $hadVariantInventory,
        int $previousTotalStock,
        array $previousVariants,
        array $newVariants,
        ?int $userId,
        string $action,
    ): void {
        $reason = 'Importación masiva ' . $action . ' XLSX/CSV V2';

        if ($productExisted && !$hadVariantInventory) {
            $newTotalStock = array_sum(array_map(fn ($variant) => (int) $variant['stock'], $newVariants));

            if ($previousTotalStock !== $newTotalStock) {
                InventoryMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $userId,
                    'color' => null,
                    'size' => null,
                    'type' => 'ajuste',
                    'quantity_change' => $newTotalStock - $previousTotalStock,
                    'previous_stock' => $previousTotalStock,
                    'new_stock' => $newTotalStock,
                    'reason' => $reason,
                ]);
            }

            return;
        }

        $newVariantMap = [];
        foreach ($newVariants as $variant) {
            $newVariantMap[$this->variantKey($variant['color'], $variant['size'])] = [
                'color' => (string) $variant['color'],
                'size' => (string) $variant['size'],
                'stock' => (int) $variant['stock'],
            ];
        }

        foreach (array_unique(array_merge(array_keys($previousVariants), array_keys($newVariantMap))) as $key) {
            $before = $previousVariants[$key] ?? null;
            $after = $newVariantMap[$key] ?? null;
            $previousStock = (int) ($before['stock'] ?? 0);
            $newStock = (int) ($after['stock'] ?? 0);

            if ($previousStock === $newStock) {
                continue;
            }

            InventoryMovement::create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'color' => (string) (($after['color'] ?? null) ?? ($before['color'] ?? '')),
                'size' => (string) (($after['size'] ?? null) ?? ($before['size'] ?? '')),
                'type' => 'ajuste',
                'quantity_change' => $newStock - $previousStock,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'reason' => $reason,
            ]);
        }
        // Registrar por separado el stock total que todavía no tiene
        // talla/color asignados. No se mezcla con existencias vendibles.
        $oldVariantStock = array_sum(array_map(
            static fn ($v) => (int) $v['stock'], $previousVariants
        ));
        $newVariantStock = array_sum(array_map(
            static fn ($v) => (int) $v['stock'], $newVariantMap
        ));
        $oldUnallocated = max(0, $previousTotalStock - $oldVariantStock);
        $newUnallocated = max(0, (int) $product->stock - $newVariantStock);
        if ($oldUnallocated !== $newUnallocated) {
            InventoryMovement::create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'color' => null,
                'size' => null,
                'type' => 'ajuste',
                'quantity_change' => $newUnallocated - $oldUnallocated,
                'previous_stock' => $oldUnallocated,
                'new_stock' => $newUnallocated,
                'reason' => $reason . ' (sin talla/color asignados)',
            ]);
        }
    }

    private function variantKey(string $color, string $size): string
    {
        return $this->normalizeText($color) . '|' . $this->normalizeText($size);
    }

    private function replaceVariantInventory(Product $product, array $variants, int $unallocatedStock = 0): void
    {
        $product->variantStocks()->delete();
        $product->sizes()->delete();
        $product->colors()->delete();

        $sizeTotals = [];
        $colorNames = [];
        $totalStock = 0;

        foreach ($variants as $variant) {
            ProductVariantStock::create([
                'product_id' => $product->id,
                'color' => $variant['color'],
                'size' => $variant['size'],
                'stock' => $variant['stock'],
            ]);

            $sizeTotals[$variant['size']] = ($sizeTotals[$variant['size']] ?? 0) + $variant['stock'];
            $colorNames[$this->normalizeText($variant['color'])] = $variant['color'];
            $totalStock += $variant['stock'];
        }

        foreach ($sizeTotals as $size => $stock) {
            ProductSize::create([
                'product_id' => $product->id,
                'size' => $size,
                'stock' => $stock,
            ]);
        }

        $palette = [
            'blanco' => '#ffffff',
            'negro' => '#111111',
            'rojo' => '#d62828',
            'azul' => '#2563eb',
            'verde' => '#2e8b57',
            'amarillo' => '#facc15',
            'rosa' => '#f472b6',
            'morado' => '#7c3aed',
            'gris' => '#9ca3af',
            'cafe' => '#6f4e37',
            'marron' => '#8b5e3c',
            'beige' => '#d6c2a1',
            'celeste' => '#7dd3fc',
            'turquesa' => '#2dd4bf',
            'naranja' => '#f97316',
            'vino' => '#7f1d1d',
            'dorado' => '#d4a017',
            'plateado' => '#c0c0c0',
            'arena' => '#d6b98c',
            'crema' => '#fffdd0',
        ];

        foreach ($colorNames as $normalized => $color) {
            ProductColor::create([
                'product_id' => $product->id,
                'color' => $color,
                'hex' => $palette[$normalized] ?? '#888888',
            ]);
        }

        // Existencias sin talla/color permanecen en el total, pero jamás
        // se reparten artificialmente ni se habilitan para el checkout.
        $product->forceFill(['stock' => $totalStock + $unallocatedStock])->saveQuietly();
    }

    private function sameGeneralData(array $left, array $right): bool
    {
        foreach ([
            'action', 'name', 'model', 'brand', 'category', 'subcategory',
            'material', 'description', 'auto_publish',
        ] as $field) {
            if ($this->normalizeComparable($left[$field] ?? null) !== $this->normalizeComparable($right[$field] ?? null)) {
                return false;
            }
        }

        if (abs(((float) ($left['price'] ?? 0)) - ((float) ($right['price'] ?? 0))) > 0.001) {
            return false;
        }

        $leftOffer = $left['offer_price'];
        $rightOffer = $right['offer_price'];
        if (($leftOffer === null) !== ($rightOffer === null)) {
            return false;
        }

        return $leftOffer === null || abs(((float) $leftOffer) - ((float) $rightOffer)) <= 0.001;
    }

    private function normalizeComparable(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $this->normalizeText((string) ($value ?? ''));
    }

    private function isMeaningfulRow(array $row): bool
    {
        foreach ([
            'accion', 'codigo_producto', 'nombre', 'modelo', 'marca', 'categoria',
            'subcategoria', 'precio', 'precio_oferta', 'material', 'color', 'talla',
            'stock', 'stock_por_talla', 'descripcion', 'imagen_principal', 'imagen_2', 'imagen_3', 'estado',
        ] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function decimalValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/[^0-9,\.\-]/u', '', $text) ?? '';
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace(',', '', $text);
        } elseif (str_contains($text, ',')) {
            $text = str_replace(',', '.', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    private function integerValue(mixed $value): ?int
    {
        $decimal = $this->decimalValue($value);
        if ($decimal === null || floor($decimal) !== $decimal) {
            return null;
        }

        return (int) $decimal;
    }

    private function normalizeImageReference(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('#^https://#i', $value)) {
            return $value;
        }

        if (str_starts_with($value, '/uploads/') || str_starts_with($value, 'uploads/')) {
            return '/' . ltrim($value, '/');
        }

        return null;
    }

    /** Precio común por marca + categoría + modelo. Si hay precios diferentes,
     * cada color mantiene el suyo y los faltantes quedan en cero.
     */
    private function sharedPricesByModel(array $rows, array $mappings): array
    {
        $values = [];
        foreach ($rows as $entry) {
            $data = $entry['data'] ?? [];
            $brand = $this->catalogMappedName(trim((string) ($data['marca'] ?? '')), $mappings['brands'] ?? []);
            $category = $this->canonicalCategoryName($this->catalogMappedName(
                trim((string) ($data['categoria'] ?? '')), $mappings['categories'] ?? []
            ));
            $model = trim((string) ($data['modelo'] ?? ''));
            $price = $this->decimalValue($data['precio'] ?? null);
            if ($brand === '' || $model === '' || $price === null || $price <= 0) {
                continue;
            }
            $key = $this->modelPriceKey($brand, $category, $model);
            $values[$key][number_format($price, 2, '.', '')] = $price;
        }

        $shared = [];
        foreach ($values as $key => $prices) {
            if (count($prices) === 1) {
                $shared[$key] = reset($prices);
            }
        }
        return $shared;
    }

    private function modelPriceKey(string $brand, string $category, string $model): string
    {
        return $this->brandAlias($brand) . '|' . $this->categoryAlias($category)
            . '|' . $this->normalizeText($model);
    }

    private function generalDefaultsByCode(array $rows): array
    {
        $fields = [
            'accion', 'nombre', 'modelo', 'marca', 'categoria', 'subcategoria',
            'precio', 'precio_oferta', 'material', 'descripcion', 'estado',
        ];
        $defaults = [];

        foreach ($rows as $source) {
            $raw = $source['data'] ?? [];
            $code = trim((string) ($raw['codigo_producto'] ?? ''));
            if ($code === '') {
                continue;
            }

            $key = $this->normalizeText($code);
            foreach ($fields as $field) {
                $value = $raw[$field] ?? null;
                if (trim((string) ($value ?? '')) === '') {
                    continue;
                }
                if (!array_key_exists($field, $defaults[$key] ?? [])) {
                    $defaults[$key][$field] = $value;
                }
            }
        }

        return $defaults;
    }

    private function valueOrDefault(mixed $value, mixed $default): mixed
    {
        return trim((string) ($value ?? '')) === '' ? $default : $value;
    }

    private function normalizeAction(mixed $value): string
    {
        return match ($this->normalizeText((string) ($value ?? ''))) {
            'crear', 'create', 'alta', 'nuevo', 'nueva', 'agregar' => 'CREAR',
            'actualizar', 'update', 'editar', 'modificar' => 'ACTUALIZAR',
            default => mb_strtoupper($this->normalizeText((string) ($value ?? '')), 'UTF-8'),
        };
    }

    private function canonicalCategoryName(string $value): string
    {
        $normalized = $this->normalizeText($value);

        return match ($normalized) {
            'huarrache', 'huarraches', 'huaraches' => 'Huarache',
            default => trim($value),
        };
    }

    private function brandAlias(string $value): string
    {
        $normalized = $this->normalizeText($value);

        return match ($normalized) {
            'flexi dama', 'flexi mujer', 'flexi damas',
            'flexi caballero', 'flexi caballeros', 'flexi hombre' => 'flexi',
            default => $normalized,
        };
    }

    // Las altas de catálogos solo se efectúan desde Paso 0 con autorización explícita.
    private function resolveCategoryId(string $name, mixed $knownId): ?int
    {
        if (trim($name) === '') {
            return $knownId === null ? null : (int) $knownId;
        }

        $normalized = $this->categoryAlias($name);
        $existing = Category::query()->where('is_active', true)->get(['id', 'name'])->first(
            fn ($item) => $this->categoryAlias($item->name) === $normalized
        );
        if (!$existing && $knownId === null) {
            return null; // Categoría original pendiente: guardar el borrador.
        }
        if (!$existing || ($knownId !== null && (int) $knownId !== (int) $existing->id)) {
            throw new RuntimeException('Categoría modificada durante la importación: ' . $name);
        }
        return (int) $existing->id;
    }

    private function resolveSubcategoryId(string $name, mixed $knownId, ?int $categoryId): ?int
    {
        if (trim($name) === '') {
            return null;
        }
        if ($categoryId === null) {
            if ($knownId === null) { return null; }
            throw new RuntimeException('Subcategoría sin categoría padre: ' . $name);
        }

        $existing = Subcategory::query()->where('category_id', $categoryId)
            ->where('is_active', true)->get(['id', 'name'])->first(fn ($item) => $this->normalizeText($item->name) === $this->normalizeText($name));
        if (!$existing && $knownId === null) {
            return null; // Subcategoría original pendiente, sin alta automática.
        }
        if (!$existing || ($knownId !== null && (int) $knownId !== (int) $existing->id)) {
            throw new RuntimeException('Subcategoría modificada durante la importación: ' . $name);
        }
        return (int) $existing->id;
    }

    private function catalogMappedName(string $source, array $mappings, string $prefix = ''): string
    {
        if ($source === '') {
            return '';
        }
        $key = $prefix . $this->normalizeText($source);
        $replacement = $mappings[$key] ?? null;
        return is_string($replacement) && trim($replacement) !== '' ? trim($replacement) : $source;
    }

    private function categoryAlias(string $value): string
    {
        $normalized = $this->normalizeText($value);

        return match ($normalized) {
            'damas', 'dama' => 'mujer',
            'caballeros', 'caballero' => 'hombre',
            'huarrache', 'huarraches', 'huaraches' => 'huarache',
            default => $normalized,
        };
    }

    private function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $transliterated = Str::ascii($value);
        $value = $transliterated !== false ? $transliterated : $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeKey(string $value): string
    {
        $value = $this->normalizeText($value);
        $value = str_replace('*', '', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }
}
