<?php

namespace Feeder\Core\Contracts\Courier;

use Illuminate\Http\UploadedFile;

/**
 * Courier-isolated invoice / payment-file parser contract.
 *
 * Implementations must not claim native courier invoice compatibility unless a
 * verified column specification exists in the repository.
 */
interface CourierReceiptInvoiceParser
{
    /**
     * Courier.code values this parser serves (e.g. ROYAL).
     *
     * @return list<string>
     */
    public function courierCodes(): array;

    public function formatId(): string;

    public function formatLabel(): string;

    /**
     * Primary template / accept extension: csv|xlsx.
     */
    public function templateFormat(): string;

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array;

    public function supportsTemplateDownload(): bool;

    public function templateUnavailableReason(): ?string;

    public function templateCsv(): string;

    /**
     * @return array{
     *     file_type: string,
     *     file_has_cod_amounts: bool,
     *     file_has_delivery_charges: bool,
     *     rows: list<array{
     *         row_number: int,
     *         tracking_number: string,
     *         order_number: string,
     *         collected_cod: string|null,
     *         delivery_charge: string|null,
     *         notes: string,
     *         invoice_status?: string,
     *         eligible_for_matching?: bool,
     *         skip_reason?: string|null
     *     }>
     * }
     */
    public function parse(UploadedFile $file): array;
}
