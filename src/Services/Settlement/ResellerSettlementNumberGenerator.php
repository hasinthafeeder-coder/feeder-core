<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Models\ResellerPayment;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Illuminate\Support\Str;

/**
 * Stable commercial numbers for reseller settlement batches, invoices, and payments.
 */
class ResellerSettlementNumberGenerator
{
    public function batchNumber(
        string $marketCode,
        string $settlementDateYmd,
        string $kind,
        int $sequence,
        ?string $reportType = null,
    ): string {
        $market = Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $marketCode) ?? '', 6, ''));
        $date = str_replace('-', '', $settlementDateYmd);
        $kindToken = Str::upper($kind);
        $reportToken = in_array($reportType, [
            ResellerSettlementBatch::REPORT_TYPE_PLUS,
            ResellerSettlementBatch::REPORT_TYPE_MINUS,
        ], true) ? $reportType.'-' : '';

        return Str::limit("RSB-{$market}-{$date}-{$reportToken}{$kindToken}-{$sequence}", 40, '');
    }

    public function invoiceNumber(
        ResellerSettlementBatch $batch,
        int $resellerId,
        string $currencyCode,
    ): string {
        $currency = Str::upper(Str::limit($currencyCode, 3, ''));
        $base = "RSI-{$batch->batch_number}-{$resellerId}-{$currency}";

        if (strlen($base) <= 40) {
            return $base;
        }

        $shortBatch = Str::limit((string) $batch->batch_number, 18, '');

        return Str::limit("RSI-{$shortBatch}-{$resellerId}-{$currency}", 40, '');
    }

    public function ensureUniqueInvoiceNumber(string $candidate): string
    {
        if (! ResellerSettlementInvoice::query()->where('invoice_number', $candidate)->exists()) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $suffix = Str::upper(Str::random(4));
            $next = Str::limit($candidate, 35, '').'-'.$suffix;
            if (! ResellerSettlementInvoice::query()->where('invoice_number', $next)->exists()) {
                return $next;
            }
        }

        return Str::limit($candidate, 29, '').'-'.Str::upper(Str::random(10));
    }

    public function paymentNumber(int $invoiceId, string $entryType): string
    {
        $prefix = $entryType === ResellerPayment::ENTRY_TYPE_REVERSAL ? 'RPR' : 'RP';
        $base = "{$prefix}-{$invoiceId}-".Str::upper(Str::random(8));

        return $this->ensureUniquePaymentNumber(Str::limit($base, 40, ''));
    }

    public function ensureUniquePaymentNumber(string $candidate): string
    {
        if (! ResellerPayment::query()->where('payment_number', $candidate)->exists()) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $suffix = Str::upper(Str::random(4));
            $next = Str::limit($candidate, 35, '').'-'.$suffix;
            if (! ResellerPayment::query()->where('payment_number', $next)->exists()) {
                return $next;
            }
        }

        return Str::limit($candidate, 29, '').'-'.Str::upper(Str::random(10));
    }
}
