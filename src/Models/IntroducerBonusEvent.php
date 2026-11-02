<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntroducerBonusEvent extends Model
{
    public const TYPE_GENERATED = 'generated';

    public const TYPE_REBUILT = 'rebuilt';

    public const TYPE_PAYMENT_RECORDED = 'payment_recorded';

    public const TYPE_TARGET_EXCEPTION_UPDATED = 'target_exception_updated';

    protected $table = 'introducer_bonus_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IntroducerBonusMonthlyBatch::class, 'batch_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
