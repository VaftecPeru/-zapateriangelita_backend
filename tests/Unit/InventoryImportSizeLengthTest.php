<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class InventoryImportSizeLengthTest extends TestCase
{
    public function test_import_service_does_not_reject_sizes_above_ten_characters(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/ProductInventoryImportService.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('TALLA supera 10 caracteres', $source);
        $this->assertStringNotContainsString('mb_strlen($size) > 10', $source);
        $this->assertStringContainsString("if (\$size === '')", $source);
        $this->assertSame(11, mb_strlen('18,19,20,21'));
    }
}
