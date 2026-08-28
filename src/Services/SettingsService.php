<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\Setting;
use Illuminate\Support\Str;

class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getForMarket($key, null, $default);
    }

    public function getForMarket(string $key, ?int $marketId, mixed $default = null): mixed
    {
        $setting = Setting::query()
            ->where('key', $key)
            ->when(
                $marketId === null,
                fn ($query) => $query->whereNull('market_id'),
                fn ($query) => $query->where('market_id', $marketId)
            )
            ->first();

        if ($setting === null) {
            return $default;
        }

        return $setting->value;
    }

    public function set(string $key, mixed $value, ?string $group = 'system', ?string $description = null): Setting
    {
        $existing = Setting::query()
            ->where('key', $key)
            ->whereNull('market_id')
            ->first();

        $payload = [
            'uuid' => $existing?->uuid ?? (string) Str::uuid(),
            'key' => $key,
            'market_id' => null,
            'group' => $group ?? 'system',
            'description' => $description,
            'value' => $this->normalizeValue($value),
        ];

        return Setting::query()->updateOrCreate(
            ['key' => $key, 'market_id' => null],
            $payload
        );
    }

    public function setForMarket(
        string $key,
        int $marketId,
        mixed $value,
        ?string $group = 'system',
        ?string $description = null
    ): Setting {
        $existing = Setting::query()
            ->where('key', $key)
            ->where('market_id', $marketId)
            ->first();

        $payload = [
            'uuid' => $existing?->uuid ?? (string) Str::uuid(),
            'key' => $key,
            'market_id' => $marketId,
            'group' => $group ?? 'system',
            'description' => $description,
            'value' => $this->normalizeValue($value),
        ];

        return Setting::query()->updateOrCreate(
            ['key' => $key, 'market_id' => $marketId],
            $payload
        );
    }

    public function exists(string $key): bool
    {
        return $this->existsForMarket($key, null);
    }

    public function existsForMarket(string $key, ?int $marketId): bool
    {
        return Setting::query()
            ->where('key', $key)
            ->when(
                $marketId === null,
                fn ($query) => $query->whereNull('market_id'),
                fn ($query) => $query->where('market_id', $marketId)
            )
            ->exists();
    }

    protected function normalizeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
