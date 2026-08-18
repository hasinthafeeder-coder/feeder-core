<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\Setting;
use Illuminate\Support\Str;

class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = Setting::query()->where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        return $setting->value;
    }

    public function set(string $key, mixed $value, ?string $group = 'system', ?string $description = null): Setting
    {
        $payload = [
            'uuid' => (string) Str::uuid(),
            'key' => $key,
            'group' => $group ?? 'system',
            'description' => $description,
            'value' => $this->normalizeValue($value),
        ];

        return Setting::query()->updateOrCreate(
            ['key' => $key],
            $payload
        );
    }

    public function exists(string $key): bool
    {
        return Setting::query()->where('key', $key)->exists();
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
