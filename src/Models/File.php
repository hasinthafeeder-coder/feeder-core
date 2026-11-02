<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\FileCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends Model
{
    use SoftDeletes;

    protected $table = 'files';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'uploaded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (File $file): bool {
            $category = (string) $file->category;

            if ($category === FileCategory::PAYMENT_PROOF->value || $category === FileCategory::EXPENSE_DOCUMENT->value) {
                return false;
            }

            return true;
        });
    }
}
