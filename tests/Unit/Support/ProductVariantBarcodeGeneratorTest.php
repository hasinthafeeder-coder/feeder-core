<?php

namespace Tests\Unit\Support;

use Feeder\Core\Support\ProductVariantBarcodeGenerator;
use PHPUnit\Framework\TestCase;

class ProductVariantBarcodeGeneratorTest extends TestCase
{
    public function test_generated_barcode_uses_auto_prefix_and_expected_length(): void
    {
        $barcode = ProductVariantBarcodeGenerator::generateUnique(reservedBarcodes: []);

        $this->assertStringStartsWith('AUTO-', $barcode);
        $this->assertSame(17, strlen($barcode));
    }

    public function test_generated_barcode_avoids_reserved_values(): void
    {
        $reserved = ['AUTO-RESERVED1234'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $barcode = ProductVariantBarcodeGenerator::generateUnique(reservedBarcodes: $reserved);

            $this->assertNotSame('AUTO-RESERVED1234', $barcode);
        }
    }
}
