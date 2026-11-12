<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\ResellerMeetingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResellerMeeting extends Model
{
    protected $table = 'reseller_meetings';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function bannerFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'banner_file_uuid', 'uuid');
    }

    public function checkInTokens(): HasMany
    {
        return $this->hasMany(ResellerMeetingCheckInToken::class, 'meeting_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(ResellerMeetingAttendance::class, 'meeting_id');
    }

    public function excuses(): HasMany
    {
        return $this->hasMany(ResellerMeetingExcuse::class, 'meeting_id');
    }

    public function isCancelled(): bool
    {
        $status = $this->status;

        if ($status instanceof ResellerMeetingStatus) {
            return $status === ResellerMeetingStatus::CANCELLED;
        }

        return $status === ResellerMeetingStatus::CANCELLED->value;
    }

    public function isScheduled(): bool
    {
        $status = $this->status;

        if ($status instanceof ResellerMeetingStatus) {
            return $status === ResellerMeetingStatus::SCHEDULED;
        }

        return $status === ResellerMeetingStatus::SCHEDULED->value;
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status' => ResellerMeetingStatus::class,
        ];
    }
}
