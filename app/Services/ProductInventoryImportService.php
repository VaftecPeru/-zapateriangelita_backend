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
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ProductInventoryImportService
{
    private const MAX_MEANINGFUL_ROWS = 5000;
    private const MAX_XLSX_XML_BYTES = 25_000_000;

    private const REQUIRED_HEADERS = [
        'accion',
        'codigo_producto',
        'nombre',
        'marca',
        'categoria',
        'precio',
        'color',
        'talla',
        'stock',
        'estado',
    ];

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
                ['Propuesta V1 · Una fila = una variante de producto (color + talla). El mismo código puede repetirse.'],
                [],
                [
                    'ACCIÓN*', 'CÓDIGO_PRODUCTO*', 'NOMBRE*', 'MODELO', 'MARCA*',
                    'CATEGORÍA*', 'SUBCATEGORÍA', 'PRECIO*', 'PRECIO_OFERTA', 'MATERIAL',
                    'COLOR*', 'TALLA*', 'STOCK*', 'DESCRIPCIÓN', 'IMAGEN_PRINCIPAL',
                    'IMAGEN_2', 'IMAGEN_3', 'ESTADO*', 'OBSERVACIONES', 'VALIDACIÓN',
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

    public function preview(UploadedFile $file): array
    {
        $parsed = $this->readFile($file);

        return $this->analyzeRows(
            $parsed['rows'],
            $parsed['format'],
            $parsed['header_row'],
        );
    }

    public function commit(UploadedFile $file, ?int $userId = null): array
    {
        // El endpoint de commit recibe el archivo original y lo vuelve a
        // interpretar/validar. Nunca se confía en la vista previa del navegador.
        $preview = $this->preview($file);

        if (!$preview['valid']) {
            throw new RuntimeException('El archivo contiene errores. Corrige la vista previa antes de importar.');
        }

        $result = DB::transaction(function () use ($preview, $userId) {
            $created = 0;
            $updated = 0;
            $variants = 0;
            $units = 0;

            foreach ($preview['groups'] as $group) {
                $product = $this->persistGroup($group, $userId);

                if ($group['action'] === 'CREAR') {
                    $created++;
                } else {
                    $updated++;
                }

                $variants += count($group['variants']);
                $units += (int) $group['total_stock'];
            }

            return compact('created', 'updated', 'variants', 'units');
        });

        return [
            'success' => true,
            'message' => 'Inventario importado correctamente.',
            'summary' => $result,
        ];
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
            if (count(array_intersect(self::REQUIRED_HEADERS, $found)) === count(self::REQUIRED_HEADERS)) {
                return [$index, $headerMap];
            }
        }

        throw new RuntimeException(
            'No se encontró la fila de encabezados esperada. Verifica que la plantilla incluya ACCIÓN, CÓDIGO_PRODUCTO, NOMBRE, MARCA, CATEGORÍA, PRECIO, COLOR, TALLA, STOCK y ESTADO.'
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
            'stock' => ['stock', 'stock_talla', 'stock_por_talla', 'cantidad', 'inventario'],
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

    private function analyzeRows(array $rows, string $format, int $headerRow): array
    {
        $categoryLookup = Category::query()->get(['id', 'name'])->keyBy(
            fn ($item) => $this->normalizeText($item->name)
        );
        $brandLookup = Brand::query()->get(['id', 'name'])->keyBy(
            fn ($item) => $this->normalizeText($item->name)
        );
        $subcategories = Subcategory::query()->get(['id', 'category_id', 'name']);

        $existingProducts = Product::query()
            ->whereNotNull('product_code')
            ->get(['id', 'product_code', 'status', 'img', 'images', 'color_images'])
            ->keyBy(fn ($product) => $this->normalizeText($product->product_code));

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

            $action = mb_strtoupper($this->normalizeText($raw['accion'] ?? ''));
            $action = $action === 'CREATE' ? 'CREAR' : ($action === 'UPDATE' ? 'ACTUALIZAR' : $action);
            $code = trim((string) ($raw['codigo_producto'] ?? ''));
            $name = trim((string) ($raw['nombre'] ?? ''));
            $model = trim((string) ($raw['modelo'] ?? ''));
            $brandName = trim((string) ($raw['marca'] ?? ''));
            $categoryName = trim((string) ($raw['categoria'] ?? ''));
            $subcategoryName = trim((string) ($raw['subcategoria'] ?? ''));
            $material = trim((string) ($raw['material'] ?? ''));
            $color = trim((string) ($raw['color'] ?? ''));
            $size = trim((string) ($raw['talla'] ?? ''));
            $description = trim((string) ($raw['descripcion'] ?? ''));
            $observations = trim((string) ($raw['observaciones'] ?? ''));
            $validation = trim((string) ($raw['validacion'] ?? ''));
            $state = mb_strtoupper($this->normalizeText($raw['estado'] ?? ''));

            $price = $this->decimalValue($raw['precio'] ?? null);
            $offerRaw = trim((string) ($raw['precio_oferta'] ?? ''));
            $offerPrice = $offerRaw === '' ? null : $this->decimalValue($raw['precio_oferta'] ?? null);
            $stock = $this->integerValue($raw['stock'] ?? null);

            if (!in_array($action, ['CREAR', 'ACTUALIZAR'], true)) {
                $errors[] = 'ACCIÓN debe ser CREAR o ACTUALIZAR';
            }
            if ($code === '') {
                $errors[] = 'CÓDIGO_PRODUCTO es obligatorio';
            } elseif (mb_strlen($code) > 100) {
                $errors[] = 'CÓDIGO_PRODUCTO supera 100 caracteres';
            }
            if ($name === '') {
                $errors[] = 'NOMBRE es obligatorio';
            } elseif (mb_strlen($name) > 255) {
                $errors[] = 'NOMBRE supera 255 caracteres';
            }
            if (mb_strlen($model) > 120) {
                $errors[] = 'MODELO supera 120 caracteres';
            }

            $categoryNormalized = $this->categoryAlias($categoryName);
            $category = $categoryLookup->get($categoryNormalized);
            if ($categoryName === '') {
                $errors[] = 'CATEGORÍA es obligatoria';
            } elseif (!$category) {
                $errors[] = 'Categoría no registrada: ' . $categoryName;
            }

            $brand = $brandLookup->get($this->normalizeText($brandName));
            if ($brandName === '') {
                $errors[] = 'MARCA es obligatoria';
            } elseif (!$brand) {
                $errors[] = 'Marca no registrada: ' . $brandName;
            }

            $subcategory = null;
            if ($subcategoryName !== '' && $category) {
                $subcategory = $subcategories->first(fn ($item) =>
                    (int) $item->category_id === (int) $category->id
                    && $this->normalizeText($item->name) === $this->normalizeText($subcategoryName)
                );

                if (!$subcategory) {
                    $errors[] = 'Subcategoría no registrada para ' . $category->name . ': ' . $subcategoryName;
                }
            }

            if ($price === null || $price <= 0) {
                $errors[] = 'PRECIO debe ser numérico y mayor que 0';
            }
            if ($offerRaw !== '' && ($offerPrice === null || $offerPrice <= 0)) {
                $errors[] = 'PRECIO_OFERTA debe ser numérico y mayor que 0';
            } elseif ($offerPrice !== null && $price !== null && $offerPrice >= $price) {
                $errors[] = 'PRECIO_OFERTA debe ser menor que PRECIO';
            }

            if ($color === '') {
                $errors[] = 'COLOR es obligatorio';
            } elseif (mb_strlen($color) > 50) {
                $errors[] = 'COLOR supera 50 caracteres';
            }
            if ($size === '') {
                $errors[] = 'TALLA es obligatoria';
            } elseif (mb_strlen($size) > 10) {
                $errors[] = 'TALLA supera 10 caracteres';
            }
            if ($stock === null || $stock < 0) {
                $errors[] = 'STOCK debe ser un entero mayor o igual a 0';
            }

            if (!in_array($state, ['ACTIVO', 'INACTIVO', 'ACTIVE', 'INACTIVE'], true)) {
                $errors[] = 'ESTADO debe ser ACTIVO o INACTIVO';
            }
            $isActive = in_array($state, ['ACTIVO', 'ACTIVE'], true);

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

            $normalizedRows[] = [
                'row' => (int) $source['row'],
                'action' => $action,
                'code' => $code,
                'name' => $name,
                'model' => $model,
                'brand' => $brandName,
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
                'description' => $description,
                'images' => array_values(array_unique($images)),
                'is_active' => $isActive,
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
                $variantKey = $this->normalizeText($row['color']) . '|' . $this->normalizeText($row['size']);
                if ($variantKey !== '|' && isset($seenVariants[$variantKey])) {
                    $groupErrors[] = 'Variante duplicada: ' . $row['color'] . ' / talla ' . $row['size'];
                }
                $seenVariants[$variantKey] = true;
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

            foreach ($groupRows as $row) {
                if ($row['color'] !== '' && !in_array($row['color'], $colors, true)) {
                    $colors[] = $row['color'];
                }
                if ($row['size'] !== '' && !in_array($row['size'], $sizes, true)) {
                    $sizes[] = $row['size'];
                }
                if ($row['color'] !== '' && $row['size'] !== '') {
                    $colorSizes[$row['color']] ??= [];
                    if (!in_array($row['size'], $colorSizes[$row['color']], true)) {
                        $colorSizes[$row['color']][] = $row['size'];
                    }
                }
                if ($row['images']) {
                    $colorImages[$row['color']] = array_values(array_unique(array_merge(
                        $colorImages[$row['color']] ?? [],
                        $row['images'],
                    )));
                    $allImages = array_values(array_unique(array_merge($allImages, $row['images'])));
                }

                if ($row['color'] !== '' && $row['size'] !== '') {
                    $variants[] = [
                        'color' => $row['color'],
                        'size' => $row['size'],
                        'stock' => (int) $row['stock'],
                    ];
                    $totalStock += (int) $row['stock'];
                }
            }

            $groups[] = [
                'code' => $first['code'],
                'action' => $first['action'],
                'name' => $first['name'],
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
                'is_active' => $first['is_active'],
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

        return [
            'valid' => $errorRows === 0 && $validGroups > 0,
            'format' => $format,
            'header_row' => $headerRow,
            'summary' => [
                'rows' => count($normalizedRows),
                'products' => count($groups),
                'variants' => array_sum(array_map(fn ($group) => count($group['variants']), $groups)),
                'valid_products' => $validGroups,
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

        $attributes = [
            'product_code' => $group['code'],
            'name' => $group['name'],
            'model' => $group['model'] !== '' ? $group['model'] : null,
            'brand_id' => $group['brand_id'],
            'category_id' => $group['category_id'],
            'subcategory_id' => $group['subcategory_id'],
            'price' => $price,
            'discounted_price' => $offerPrice,
            'discount' => $discount,
            'stock' => (int) $group['total_stock'],
            'size' => implode('|', $group['sizes']),
            'color' => implode('|', $group['colors']),
            'material' => $group['material'] !== '' ? $group['material'] : null,
            'description' => $group['description'] !== '' ? $group['description'] : null,
            'status' => $marketingStatus,
            'is_active' => (bool) $group['is_active'],
            'color_sizes' => $group['color_sizes'] ?: null,
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

        $this->replaceVariantInventory($product, $group['variants']);
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
        $reason = 'Importación masiva ' . $action . ' XLSX/CSV V1';

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
    }

    private function variantKey(string $color, string $size): string
    {
        return $this->normalizeText($color) . '|' . $this->normalizeText($size);
    }

    private function replaceVariantInventory(Product $product, array $variants): void
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

        $product->forceFill(['stock' => $totalStock])->saveQuietly();
    }

    private function sameGeneralData(array $left, array $right): bool
    {
        foreach ([
            'action', 'name', 'model', 'brand_id', 'category_id', 'subcategory_id',
            'material', 'description', 'is_active',
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
            'stock', 'descripcion', 'imagen_principal', 'imagen_2', 'imagen_3', 'estado',
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

    private function categoryAlias(string $value): string
    {
        $normalized = $this->normalizeText($value);

        return match ($normalized) {
            'damas', 'dama' => 'mujer',
            'caballeros', 'caballero' => 'hombre',
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
