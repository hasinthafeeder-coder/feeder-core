<?php

namespace Tests\Unit\Support;

use Feeder\Core\Support\CommercialIdentifier;
use PHPUnit\Framework\TestCase;

class ProductVariantBarcodeGeneratorTest extends TestCase
{
    public function test_commercial_product_barcode_format_helpers(): void
    {
        $this->assertSame('100001', CommercialIdentifier::formatProductBarcode(1, 1));
        $this->assertSame(6, strlen(CommercialIdentifier::formatProductBarcode(2, 99999)));
    }
}
