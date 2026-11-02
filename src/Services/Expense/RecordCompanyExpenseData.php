<?php

namespace Feeder\Core\Services\Expense;

use Feeder\Core\Enums\CompanyExpenseCategory;

/**
 * Administrator-supplied expense fields. The ledger assigns the reference,
 * status, currency snapshot, creator, and timestamps.
 */
final class RecordCompanyExpenseData
{
    public function __construct(
        public readonly string $expenseDate,
        public readonly CompanyExpenseCategory $category,
        public readonly string $description,
        public readonly string $amount,
        public readonly int $currencyId,
        public readonly ?string $payee = null,
        public readonly ?string $correctsExpenseReference = null,
    ) {}
}
