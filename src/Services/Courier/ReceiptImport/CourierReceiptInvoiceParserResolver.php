<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Contracts\Courier\CourierReceiptInvoiceParser;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierService;
use Illuminate\Validation\ValidationException;

class CourierReceiptInvoiceParserResolver
{
    /** @var array<string, CourierReceiptInvoiceParser> */
    private array $byCourierCode = [];

    public function __construct(
        FeederCourierPaymentTemplateParser $feederTemplateParser,
        RoyalExpressInvoiceParser $royalExpressParser,
        TransExpressInvoiceParser $transExpressParser,
        FardarInvoiceParser $fardarParser,
    ) {
        foreach ($feederTemplateParser->courierCodes() as $code) {
            $this->byCourierCode[strtoupper($code)] = $feederTemplateParser;
        }

        foreach ($royalExpressParser->courierCodes() as $code) {
            $this->byCourierCode[strtoupper($code)] = $royalExpressParser;
        }

        foreach ($transExpressParser->courierCodes() as $code) {
            $this->byCourierCode[strtoupper($code)] = $transExpressParser;
        }

        foreach ($fardarParser->courierCodes() as $code) {
            $this->byCourierCode[strtoupper($code)] = $fardarParser;
        }
    }

    public function forCourierService(CourierService $service): CourierReceiptInvoiceParser
    {
        $service->loadMissing('courier');
        $courier = $service->courier;

        if (! $courier instanceof Courier || ! $courier->is_active || ! $service->is_active) {
            throw ValidationException::withMessages([
                'courier_service' => ['The selected courier service is not available for import.'],
            ]);
        }

        $code = strtoupper((string) $courier->code);
        $parser = $this->byCourierCode[$code] ?? null;

        if ($parser === null) {
            throw ValidationException::withMessages([
                'courier_service' => [
                    'No verified invoice import format is registered for courier code '.$code
                    .'. Native invoice specifications are required before a parser can be enabled.',
                ],
            ]);
        }

        return $parser;
    }

    public function forCourierCode(string $code): ?CourierReceiptInvoiceParser
    {
        return $this->byCourierCode[strtoupper($code)] ?? null;
    }
}
