<?php

namespace Feeder\Core\Services\Expense;

use DateTimeInterface;
use Feeder\Core\Models\CompanyExpense;
use Illuminate\Validation\ValidationException;

/**
 * Daily expense references: EXP-YYYYMMDD-0001.
 *
 * Must be called inside a transaction so the latest reference row is locked.
 */
class CompanyExpenseReferenceGenerator
{
    public function next(DateTimeInterface $recordedOn): string
    {
        $prefix = 'EXP-'.$recordedOn->format('Ymd').'-';

        $latest = CompanyExpense::query()
            ->where('expense_reference', 'like', $prefix.'%')
            ->orderByDesc('expense_reference')
            ->lockForUpdate()
            ->value('expense_reference');

        $sequence = 1;

        if (is_string($latest) && preg_match('/^'.preg_quote($prefix, '/').'(\d{4})$/', $latest, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        if ($sequence > 9999) {
            throw ValidationException::withMessages([
                'expense_reference' => ['The daily expense reference sequence is exhausted.'],
            ]);
        }

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
