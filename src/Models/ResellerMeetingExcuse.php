<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\MeetingExcuseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerMeetingExcuse extends Model
{
    protected $table = 'reseller_meeting_excuses';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ResellerMeeting::class, 'meeting_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isApproved(): bool
    {
        $status = $this->status;

        if ($status instanceof MeetingExcuseStatus) {
            return $status === MeetingExcuseStatus::APPROVED;
        }

        return $status === MeetingExcuseStatus::APPROVED->value;
    }

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'status' => MeetingExcuseStatus::class,
        ];
    }
}
