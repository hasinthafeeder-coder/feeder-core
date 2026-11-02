<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Models\SupplierPayment;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Illuminate\Support\Str;

/**
 * Stable commercial numbers for settlement batches, invoices, and payment ledger rows.
 */
class SupplierSettlementNumberGenerator
{
    public function batchNumber(
        string $marketCode,
        string $settlementDateYmd,
        string $kind,
        int $sequence,
    ): string {
        $market = Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $marketCode) ?? '', 6, ''));
        $date = str_replace('-', '', $settlementDateYmd);
        $kindToken = Str::upper($kind);

        return Str::limit("SSB-{$market}-{$date}-{$kindToken}-{$sequence}", 40, '');
    }

    public function invoiceNumber(
        SupplierSettlementBatch $batch,
        int $supplierCompanyId,
        string $currencyCode,
    ): string {
        $currency = Str::upper(Str::limit($currencyCode, 3, ''));
        $base = "SSI-{$batch->batch_number}-{$supplierCompanyId}-{$currency}";

        if (strlen($base) <= 40) {
            return $base;
        }

        $shortBatch = Str::limit((string) $batch->batch_number, 18, '');

        return Str::limit("SSI-{$shortBatch}-{$supplierCompanyId}-{$currency}", 40, '');
    }

    public function ensureUniqueInvoiceNumber(string $candidate): string
    {
        if (! SupplierSettlementInvoice::query()->where('invoice_number', $candidate)->exists()) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $suffix = Str::upper(Str::random(4));
            $next = Str::limit($candidate, 35, '').'-'.$suffix;
            if (! SupplierSettlementInvoice::query()->where('invoice_number', $next)->exists()) {
                return $next;
            }
        }

        return Str::limit($candidate, 29, '').'-'.Str::upper(Str::random(10));
    }

    public function paymentNumber(int $invoiceId, string $entryType): string
    {
        $prefix = $entryType === SupplierPayment::ENTRY_TYPE_REVERSAL ? 'SPR' : 'SP';
        $base = "{$prefix}-{$invoiceId}-".Str::upper(Str::random(8));

        return $this->ensureUniquePaymentNumber(Str::limit($base, 40, ''));
    }

    public function ensureUniquePaymentNumber(string $candidate): string
    {
        if (! SupplierPayment::query()->where('payment_number', $candidate)->exists()) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $suffix = Str::upper(Str::random(4));
            $next = Str::limit($candidate, 35, '').'-'.$suffix;
            if (! SupplierPayment::query()->where('payment_number', $next)->exists()) {
                return $next;
            }
        }

        return Str::limit($candidate, 29, '').'-'.Str::upper(Str::random(10));
    }
}
