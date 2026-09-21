<?php

namespace Feeder\Core\Models;

use Feeder\Core\Support\CredentialsEncrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupplierCourierAccount extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'supplier_id',
        'courier_id',
        'account_label',
        'credentials_encrypted',
        'meta_json',
        'is_active',
        'is_default',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'credentials_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SupplierCourierAccount $account): void {
            if (empty($account->uuid)) {
                $account->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function setCredentials(array $credentials): void
    {
        $this->credentials_encrypted = app(CredentialsEncrypter::class)
            ->encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    public function hasEncryptedCredentials(): bool
    {
        return $this->credentials_encrypted !== null && $this->credentials_encrypted !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getCredentials(): array
    {
        if (! $this->hasEncryptedCredentials()) {
            return [];
        }

        try {
            $decoded = json_decode(
                app(CredentialsEncrypter::class)->decryptString((string) $this->credentials_encrypted),
                true
            );
        } catch (DecryptException $exception) {
            Log::warning('Failed to decrypt supplier courier account credentials.', [
                'supplier_courier_account_id' => $this->id,
                'supplier_id' => $this->supplier_id,
                'courier_id' => $this->courier_id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Safe for Admin presentation — never throws on corrupt / wrong-key ciphertext.
     *
     * @return array{credentials: array<string, mixed>, unreadable: bool}
     */
    public function presentableCredentials(): array
    {
        if (! $this->hasEncryptedCredentials()) {
            return [
                'credentials' => [],
                'unreadable' => false,
            ];
        }

        try {
            return [
                'credentials' => $this->getCredentials(),
                'unreadable' => false,
            ];
        } catch (DecryptException) {
            return [
                'credentials' => [],
                'unreadable' => true,
            ];
        }
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
