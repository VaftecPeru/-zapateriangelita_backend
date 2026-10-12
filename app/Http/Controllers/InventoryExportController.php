<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InventoryExportController extends Controller
{
    public function excel(Request $request): Response
    {
        [$products, $rows, $summary] = $this->inventorySnapshot($request);
        $generatedAt = now();
        $storeName = $this->storeName();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        $xml .= '<Styles>'
            . '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/>'
            . '<Font ss:FontName="Calibri" ss:Size="10"/></Style>'
            . '<Style ss:ID="Title"><Font ss:FontName="Calibri" ss:Size="18" ss:Bold="1" ss:Color="#E30613"/></Style>'
            . '<Style ss:ID="Subtitle"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#666666"/></Style>'
            . '<Style ss:ID="Header"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="#E30613" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>'
            . '<Style ss:ID="Number"><NumberFormat ss:Format="0"/></Style>'
            . '<Style ss:ID="Money"><NumberFormat ss:Format="$#,##0.00"/></Style>'
            . '<Style ss:ID="Low"><Font ss:Color="#B45309" ss:Bold="1"/></Style>'
            . '<Style ss:ID="Out"><Font ss:Color="#B91C1C" ss:Bold="1"/></Style>'
            . '</Styles>' . "\n";
        $xml .= '<Worksheet ss:Name="Inventario"><Table>'
            . '<Column ss:Width="45"/><Column ss:Width="90"/><Column ss:Width="190"/><Column ss:Width="95"/>'
            . '<Column ss:Width="95"/><Column ss:Width="65"/><Column ss:Width="75"/>'
            . '<Column ss:Width="75"/><Column ss:Width="80"/><Column ss:Width="95"/>' . "\n";

        $xml .= $this->excelRow([
            [$storeName, 'String', 'Title', 10],
        ]);
        $xml .= $this->excelRow([
            ['REPORTE PROFESIONAL DE INVENTARIO POR TALLA', 'String', 'Subtitle', 10],
        ]);
        $xml .= $this->excelRow([
            ['Generado: ' . $generatedAt->format('d/m/Y H:i'), 'String', 'Subtitle', 10],
        ]);
        $xml .= $this->excelRow([
            ['Productos: ' . $summary['products'], 'String', 'Subtitle', 2],
            ['Unidades disponibles: ' . $summary['units'], 'String', 'Subtitle', 2],
            ['Stock bajo: ' . $summary['low_stock_products'], 'String', 'Subtitle', 2],
            ['Agotados: ' . $summary['out_of_stock_products'], 'String', 'Subtitle', 4],
        ]);
        $xml .= '<Row ss:Height="8"></Row>' . "\n";
        $xml .= $this->excelRow(array_map(
            fn (string $header) => [$header, 'String', 'Header', 1],
            ['N°', 'Código', 'Producto', 'Categoría', 'Marca', 'Talla', 'Stock talla', 'Stock total', 'Precio MXN', 'Estado']
        ));

        foreach ($rows as $index => $row) {
            $statusStyle = $row['status'] === 'Agotado' ? 'Out' : ($row['status'] === 'Stock bajo' ? 'Low' : 'Default');
            $xml .= $this->excelRow([
                [$index + 1, 'Number', 'Number', 1],
                [$row['code'], 'String', 'Default', 1],
                [$row['name'], 'String', 'Default', 1],
                [$row['category'], 'String', 'Default', 1],
                [$row['brand'], 'String', 'Default', 1],
                [$row['size'], 'String', 'Default', 1],
                [$row['size_stock'], 'Number', 'Number', 1],
                [$row['total_stock'], 'Number', 'Number', 1],
                [$row['price'], 'Number', 'Money', 1],
                [$row['status'], 'String', $statusStyle, 1],
            ]);
        }

        $xml .= '</Table>'
            . '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            . '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>6</SplitHorizontal><TopRowBottomPane>6</TopRowBottomPane>'
            . '<ProtectObjects>False</ProtectObjects><ProtectScenarios>False</ProtectScenarios>'
            . '</WorksheetOptions></Worksheet></Workbook>';

        $filename = 'inventario-zapateria-angelita-' . $generatedAt->format('Ymd-His') . '.xls';

        return response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pdf(Request $request): Response
    {
        [, $rows, $summary] = $this->inventorySnapshot($request);
        $generatedAt = now();
        $pdf = $this->buildPdf($rows, $summary, $generatedAt->format('d/m/Y H:i'));
        $filename = 'inventario-zapateria-angelita-' . $generatedAt->format('Ymd-His') . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function inventorySnapshot(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'stock_state' => ['nullable', 'in:available,low,out'],
            'active_state' => ['nullable', 'in:active,inactive'],
        ]);

        $query = Product::query()
            ->with([
                'brand:id,name',
                'category:id,name',
                'sizes:id,product_id,size,stock',
            ]);

        $this->applyFilters($query, $validated);

        $products = $query
            ->orderBy('name')
            ->orderBy('product_code')
            ->get();

        $rows = [];
        foreach ($products as $product) {
            $sizes = $product->sizes
                ->sortBy(fn ($item) => str_pad((string) $item->size, 20, '0', STR_PAD_LEFT), SORT_NATURAL)
                ->values();

            if ($sizes->isEmpty()) {
                $stock = max(0, (int) $product->stock);
                $rows[] = $this->reportRow(
                    $product,
                    trim((string) $product->size) ?: '—',
                    $stock,
                );
                continue;
            }

            foreach ($sizes as $size) {
                $rows[] = $this->reportRow(
                    $product,
                    (string) $size->size,
                    max(0, (int) $size->stock),
                );
            }
        }

        $summary = [
            'products' => $products->count(),
            'units' => (int) $products->sum(fn ($product) => max(0, (int) $product->stock)),
            'low_stock_products' => $products->filter(
                fn ($product) => (int) $product->stock >= 1 && (int) $product->stock <= 5
            )->count(),
            'out_of_stock_products' => $products->filter(
                fn ($product) => (int) $product->stock <= 0
            )->count(),
        ];

        return [$products, $rows, $summary];
    }

    private function applyFilters(Builder $query, array $validated): void
    {
        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('product_code', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('model', 'like', '%' . $search . '%')
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', '%' . $search . '%'));
            });
        }

        if (!empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        if (!empty($validated['brand_id'])) {
            $query->where('brand_id', $validated['brand_id']);
        }

        if (($validated['stock_state'] ?? null) === 'available') {
            $query->where('stock', '>', 5);
        } elseif (($validated['stock_state'] ?? null) === 'low') {
            $query->whereBetween('stock', [1, 5]);
        } elseif (($validated['stock_state'] ?? null) === 'out') {
            $query->where('stock', '<=', 0);
        }

        if (($validated['active_state'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($validated['active_state'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }
    }

    private function reportRow(Product $product, string $size, int $sizeStock): array
    {
        return [
            'code' => (string) ($product->product_code ?: '—'),
            'name' => (string) $product->name,
            'category' => (string) ($product->category?->name ?: 'Sin categoría'),
            'brand' => (string) ($product->brand?->name ?: 'Sin marca'),
            'size' => $size,
            'size_stock' => $sizeStock,
            'total_stock' => max(0, (int) $product->stock),
            'price' => (float) ($product->discounted_price ?: $product->price ?: 0),
            'status' => $sizeStock <= 0 ? 'Agotado' : ($sizeStock <= 5 ? 'Stock bajo' : 'Disponible'),
        ];
    }

    private function storeName(): string
    {
        $name = trim((string) config('app.name', 'Zapatería Angelita'));
        return $name !== '' ? mb_strtoupper($name, 'UTF-8') : 'ZAPATERÍA ANGELITA';
    }

    private function excelRow(array $cells): string
    {
        $xml = '<Row>';
        foreach ($cells as [$value, $type, $style, $mergeAcross]) {
            $merge = $mergeAcross > 1 ? ' ss:MergeAcross="' . ($mergeAcross - 1) . '"' : '';
            $xml .= '<Cell ss:StyleID="' . $style . '"' . $merge . '><Data ss:Type="' . $type . '">'
                . htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '</Data></Cell>';
        }
        return $xml . '</Row>' . "\n";
    }

    private function buildPdf(array $rows, array $summary, string $generatedAt): string
    {
        $rowsPerPage = 27;
        $chunks = array_chunk($rows, $rowsPerPage);
        if (!$chunks) {
            $chunks = [[]];
        }

        $streams = [];
        $pageCount = count($chunks);

        foreach ($chunks as $pageIndex => $chunk) {
            $stream = '';
            $stream .= "q 0.89 0.02 0.08 rg 36 568 720 4 re f Q\n";
            $stream .= $this->pdfText(36, 582, 17, $this->storeName(), true);
            $stream .= $this->pdfText(36, 558, 11, 'REPORTE PROFESIONAL DE INVENTARIO POR TALLA', true);
            $stream .= $this->pdfText(36, 542, 8, 'Generado: ' . $generatedAt);
            $stream .= $this->pdfText(
                36,
                526,
                8,
                sprintf(
                    'Productos: %d   |   Unidades: %d   |   Stock bajo: %d   |   Agotados: %d',
                    $summary['products'],
                    $summary['units'],
                    $summary['low_stock_products'],
                    $summary['out_of_stock_products'],
                )
            );

            $stream .= "q 0.95 0.95 0.95 rg 36 496 720 20 re f Q\n";
            $headers = [
                [40, 'N°'],
                [62, 'Código'],
                [118, 'Producto'],
                [270, 'Categoría'],
                [350, 'Marca'],
                [422, 'Talla'],
                [464, 'Stock talla'],
                [530, 'Total'],
                [574, 'Precio'],
                [638, 'Estado'],
            ];
            foreach ($headers as [$x, $label]) {
                $stream .= $this->pdfText($x, 503, 7.5, $label, true);
            }

            $y = 482;
            foreach ($chunk as $rowIndex => $row) {
                $sequence = ($pageIndex * $rowsPerPage) + $rowIndex + 1;
                $stream .= "q 0.88 0.88 0.88 RG 36 " . ($y - 4) . " m 756 " . ($y - 4) . " l S Q\n";
                $stream .= $this->pdfText(40, $y, 7.2, (string) $sequence);
                $stream .= $this->pdfText(62, $y, 7.2, $this->truncate($row['code'], 10));
                $stream .= $this->pdfText(118, $y, 7.2, $this->truncate($row['name'], 28));
                $stream .= $this->pdfText(270, $y, 7.2, $this->truncate($row['category'], 13));
                $stream .= $this->pdfText(350, $y, 7.2, $this->truncate($row['brand'], 11));
                $stream .= $this->pdfText(422, $y, 7.2, $this->truncate($row['size'], 7));
                $stream .= $this->pdfText(470, $y, 7.2, (string) $row['size_stock']);
                $stream .= $this->pdfText(536, $y, 7.2, (string) $row['total_stock']);
                $stream .= $this->pdfText(574, $y, 7.2, '$' . number_format((float) $row['price'], 2));
                $stream .= $this->pdfText(638, $y, 7.2, $this->truncate($row['status'], 15));
                $y -= 16;
            }

            if (!$chunk) {
                $stream .= $this->pdfText(36, 466, 9, 'No hay productos que coincidan con los filtros seleccionados.');
            }

            $stream .= $this->pdfText(36, 24, 7, 'Zapatería Angelita · Control de inventario por talla');
            $stream .= $this->pdfText(710, 24, 7, 'Pág. ' . ($pageIndex + 1) . '/' . $pageCount);
            $streams[] = $stream;
        }

        return $this->assemblePdf($streams);
    }

    private function pdfText(float $x, float $y, float $size, string $text, bool $bold = false): string
    {
        $font = $bold ? 'F2' : 'F1';
        return sprintf(
            "BT /%s %.1f Tf 1 0 0 1 %.1f %.1f Tm (%s) Tj ET\n",
            $font,
            $size,
            $x,
            $y,
            $this->escapePdfText($text),
        );
    }

    private function escapePdfText(string $text): string
    {
        $encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\\(', '\\)', ' ', ' '],
            $encoded,
        );
    }

    private function truncate(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return mb_strlen($value, 'UTF-8') <= $length
            ? $value
            : mb_substr($value, 0, max(1, $length - 1), 'UTF-8') . '…';
    }

    private function assemblePdf(array $streams): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $pageObjectNumbers = [];
        $objectNumber = 5;

        foreach ($streams as $stream) {
            $pageObject = $objectNumber++;
            $contentObject = $objectNumber++;
            $pageObjectNumbers[] = $pageObject;

            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $contentObject,
            );
            $objects[$contentObject] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $kids = implode(' ', array_map(fn ($number) => $number . ' 0 R', $pageObjectNumbers));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageObjectNumbers) . ' >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];

        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxObject = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($number = 1; $number <= $maxObject; $number++) {
            $offset = $offsets[$number] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }
}
