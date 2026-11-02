<?php

namespace Feeder\Core\Services\Expense;

use DateTimeImmutable;
use Feeder\Core\Enums\ApplicationType;
use Feeder\Core\Enums\CompanyExpenseStatus;
use Feeder\Core\Enums\FileCategory;
use Feeder\Core\Models\CompanyExpense;
use Feeder\Core\Models\Currency;
use Feeder\Core\Models\File;
use Feeder\Core\Models\User;
use Feeder\Core\Services\FileService;
use Feeder\Core\Services\UuidService;
use Feeder\Core\Support\DecimalAmount;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Company operating-expense ledger.
 *
 * Records only expenses an administrator enters. Courier remittance, supplier
 * invoices, reseller settlements, and bank-transfer fees stay on their own
 * records and are never copied here.
 *
 * A posted amount, currency, creator, and original timestamps are immutable.
 * Voiding keeps the row and excludes it from posted totals. A correction is a
 * new posted expense that points at the voided original.
 */
class CompanyExpenseLedgerService
{
    public const PERMISSION_VIEW = 'expenses.view';

    public const PERMISSION_CREATE = 'expenses.create';

    public const PERMISSION_VOID = 'expenses.void';

    public function __construct(
        private readonly CompanyExpenseReferenceGenerator $references,
        private readonly FileService $files,
    ) {}

    public function record(User $actor, RecordCompanyExpenseData $data, ?UploadedFile $document = null): CompanyExpense
    {
        $amount = DecimalAmount::normalizePositive($data->amount, 'amount');
        $expenseDate = $this->expenseDate($data->expenseDate);
        $description = $this->requiredText($data->description, 'description', 2000);
        $payee = $this->optionalText($data->payee, 'payee', 200);
        $currency = $this->activeCurrency($data->currencyId);
        $currencyCode = strtoupper(trim((string) $currency->iso_code));
        $documentReference = $document === null
            ? null
            : $this->storeSupportingDocument($actor, $document);

        $attempts = 0;

        while ($attempts < 5) {
            $attempts++;

            try {
                return DB::transaction(function () use (
                    $actor,
                    $data,
                    $amount,
                    $expenseDate,
                    $description,
                    $payee,
                    $currency,
                    $currencyCode,
                    $documentReference,
                ): CompanyExpense {
                    $corrects = $this->lockVoidedCorrectionTarget(
                        $data->correctsExpenseReference,
                        (int) $currency->id,
                        $currencyCode,
                    );

                    return CompanyExpense::query()->create([
                        'expense_reference' => $this->references->next(new DateTimeImmutable('now')),
                        'expense_date' => $expenseDate,
                        'category' => $data->category,
                        'description' => $description,
                        'payee' => $payee,
                        'currency_id' => (int) $currency->id,
                        'currency_code' => $currencyCode,
                        'amount' => $amount,
                        'supporting_document_file_uuid' => $documentReference['uuid'] ?? null,
                        'supporting_document_original_name' => $documentReference['original_name'] ?? null,
                        'status' => CompanyExpenseStatus::POSTED,
                        'created_by' => (int) $actor->id,
                        'corrects_expense_id' => $corrects?->id,
                    ]);
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempts >= 5) {
                    throw $exception;
                }
            } catch (QueryException $exception) {
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);

                if ($driverCode !== 1062 || $attempts >= 5) {
                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages([
            'expense_reference' => ['The expense reference could not be allocated. Try again.'],
        ]);
    }

    public function void(CompanyExpense $expense, User $actor, string $reason): CompanyExpense
    {
        $reason = $this->requiredText($reason, 'void_reason', 1000);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'void_reason' => ['Enter a void reason of at least 3 characters.'],
            ]);
        }

        return DB::transaction(function () use ($expense, $actor, $reason): CompanyExpense {
            $locked = CompanyExpense::query()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'expense' => ['The expense could not be found.'],
                ]);
            }

            if ($locked->status !== CompanyExpenseStatus::POSTED) {
                throw ValidationException::withMessages([
                    'expense' => ['Only a posted expense can be voided.'],
                ]);
            }

            $locked->status = CompanyExpenseStatus::VOIDED;
            $locked->voided_by = (int) $actor->id;
            $locked->voided_at = now();
            $locked->void_reason = $reason;
            $locked->save();

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Posted totals only. Voided rows are omitted. Each currency is totaled
     * on its own and is never converted or combined with another currency.
     *
     * @return array<string, string>
     */
    public function postedTotalsByCurrency(): array
    {
        $rows = DB::table('company_expenses')
            ->select('currency_code')
            ->selectRaw('SUM(amount) as total')
            ->where('status', CompanyExpenseStatus::POSTED->value)
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $code = strtoupper(trim((string) $row->currency_code));
            $totals[$code] = DecimalAmount::fromStored($row->total);
        }

        return $totals;
    }

    /**
     * @return Collection<int, Currency>
     */
    public function activeCurrencies(): Collection
    {
        return Currency::query()
            ->where('is_active', true)
            ->orderBy('iso_code')
            ->get();
    }

    /**
     * Active currencies, plus any currency already used by an expense so a
     * historical filter can still name it after it is deactivated.
     *
     * @return Collection<int, Currency>
     */
    public function currenciesForFilter(): Collection
    {
        $usedIds = CompanyExpense::query()->distinct()->pluck('currency_id');

        return Currency::query()
            ->where(function ($query) use ($usedIds): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $usedIds);
            })
            ->orderBy('iso_code')
            ->get();
    }

    public function loadForDisplay(CompanyExpense $expense): CompanyExpense
    {
        $expense->load([
            'currency',
            'creator',
            'voidedBy',
            'correctsExpense',
            'corrections',
        ]);

        return $expense;
    }

    /**
     * Expense documents use the shared file server. Other file categories,
     * including payment proofs, keep their existing access rules.
     */
    public function authorizeSupportingDocument(User $actor, string $fileUuid): void
    {
        $fileUuid = strtoupper(trim($fileUuid));

        if ($fileUuid === '') {
            return;
        }

        $file = File::query()->where('uuid', $fileUuid)->first();

        if ($file === null || (string) $file->category !== FileCategory::EXPENSE_DOCUMENT->value) {
            return;
        }

        if (! $actor->hasPermission(self::PERMISSION_VIEW)) {
            abort(403);
        }
    }

    private function activeCurrency(int $currencyId): Currency
    {
        $currency = Currency::query()->whereKey($currencyId)->first();

        if ($currency === null || ! $currency->is_active || blank($currency->iso_code)) {
            throw ValidationException::withMessages([
                'currency_id' => ['Choose an active currency.'],
            ]);
        }

        $code = strtoupper(trim((string) $currency->iso_code));

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw ValidationException::withMessages([
                'currency_id' => ['The selected currency is not a valid ISO code.'],
            ]);
        }

        return $currency;
    }

    private function lockVoidedCorrectionTarget(?string $reference, int $currencyId, string $currencyCode): ?CompanyExpense
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return null;
        }

        $target = CompanyExpense::query()
            ->where('expense_reference', $reference)
            ->lockForUpdate()
            ->first();

        if ($target === null || $target->status !== CompanyExpenseStatus::VOIDED) {
            throw ValidationException::withMessages([
                'corrects_expense_reference' => ['A voided expense with that reference was not found.'],
            ]);
        }

        if ((int) $target->currency_id !== $currencyId || (string) $target->currency_code !== $currencyCode) {
            throw ValidationException::withMessages([
                'corrects_expense_reference' => ['A correction must use the same currency as the voided expense.'],
            ]);
        }

        $alreadyCorrected = CompanyExpense::query()
            ->where('corrects_expense_id', $target->id)
            ->lockForUpdate()
            ->exists();

        if ($alreadyCorrected) {
            throw ValidationException::withMessages([
                'corrects_expense_reference' => ['This voided expense already has a correction.'],
            ]);
        }

        return $target;
    }

    /**
     * @return array{uuid: string, original_name: string}
     */
    private function storeSupportingDocument(User $actor, UploadedFile $document): array
    {
        $uploadedBy = is_string($actor->uuid) && strlen($actor->uuid) === 10
            ? $actor->uuid
            : null;

        try {
            $response = $this->files->upload(
                $document,
                ApplicationType::ADMIN->value,
                'COMPANY_EXPENSE',
                UuidService::generate(),
                FileCategory::EXPENSE_DOCUMENT->value,
                $uploadedBy,
                ['purpose' => 'company_expense_supporting_document'],
            );
        } catch (RequestException|ConnectionException|Throwable) {
            throw ValidationException::withMessages([
                'supporting_document' => ['Unable to store the supporting document. Try again.'],
            ]);
        }

        $uuid = strtoupper(trim((string) data_get($response, 'file.uuid')));

        if ($uuid === '' || preg_match('/^[A-Z0-9]{10}$/', $uuid) !== 1) {
            throw ValidationException::withMessages([
                'supporting_document' => ['Unable to store the supporting document. Try again.'],
            ]);
        }

        $file = File::query()->where('uuid', $uuid)->first();

        if ($file === null || (string) $file->category !== FileCategory::EXPENSE_DOCUMENT->value || (string) $file->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'supporting_document' => ['The supporting document could not be linked.'],
            ]);
        }

        $originalName = trim((string) ($file->original_name ?: data_get($response, 'file.original_name')));

        if ($originalName === '') {
            throw ValidationException::withMessages([
                'supporting_document' => ['The supporting document could not be linked.'],
            ]);
        }

        return [
            'uuid' => $uuid,
            'original_name' => mb_substr($originalName, 0, 255),
        ];
    }

    private function expenseDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors['warning_count'] ?? 0) > 0
            || ($errors['error_count'] ?? 0) > 0
            || $parsed->format('Y-m-d') !== trim($date)
        ) {
            throw ValidationException::withMessages([
                'expense_date' => ['A valid expense date is required.'],
            ]);
        }

        return $parsed->format('Y-m-d');
    }

    private function requiredText(string $value, string $field, int $max): string
    {
        $text = trim($value);

        if ($text === '') {
            throw ValidationException::withMessages([
                $field => ['This field is required.'],
            ]);
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([
                $field => ["Must be {$max} characters or fewer."],
            ]);
        }

        return $text;
    }

    private function optionalText(?string $value, string $field, int $max): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([
                $field => ["Must be {$max} characters or fewer."],
            ]);
        }

        return $text;
    }
}
