<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\MeetingCheckInMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerMeetingAttendance extends Model
{
    protected $table = 'reseller_meeting_attendances';

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(ResellerMeetingCheckInToken::class, 'token_id');
    }

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'check_in_method' => MeetingCheckInMethod::class,
        ];
    }
}
