<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\CompanyExpenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Blocks bulk edits and deletes of ledger rows.
 *
 * A model save may update only the void columns, and only while the row is
 * still posted. Amount, currency, and the other posted fields are rejected.
 */
class CompanyExpenseBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values)
    {
        $allowed = ['status', 'voided_by', 'voided_at', 'void_reason', 'updated_at'];
        $forbidden = array_diff(array_keys($values), $allowed);

        if ($forbidden !== []) {
            throw ValidationException::withMessages([
                'expense' => ['A posted expense cannot be edited. Void it and record a correction.'],
            ]);
        }

        if (array_key_exists('status', $values)) {
            $status = $values['status'];
            $statusValue = $status instanceof CompanyExpenseStatus ? $status->value : (string) $status;

            if ($statusValue !== CompanyExpenseStatus::VOIDED->value) {
                throw ValidationException::withMessages([
                    'status' => ['An expense can only move from posted to voided.'],
                ]);
            }

            $reason = trim((string) ($values['void_reason'] ?? ''));

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'void_reason' => ['A void reason is required.'],
                ]);
            }
        }

        $matchesFinalExpense = $this->clone()
            ->where('status', '!=', CompanyExpenseStatus::POSTED->value)
            ->exists();

        if ($matchesFinalExpense) {
            throw ValidationException::withMessages([
                'expense' => ['A voided expense is final and cannot be changed.'],
            ]);
        }

        return parent::update($values);
    }

    public function delete()
    {
        throw ValidationException::withMessages([
            'expense' => ['Expense records cannot be deleted.'],
        ]);
    }
}
