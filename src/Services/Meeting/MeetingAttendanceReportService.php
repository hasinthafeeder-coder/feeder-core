<?php

namespace Feeder\Core\Services\Meeting;

use Feeder\Core\Enums\MeetingExcuseStatus;
use Feeder\Core\Enums\ResellerMeetingStatus;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\ResellerMeetingAttendance;
use Feeder\Core\Models\ResellerMeetingExcuse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Attendance and calendar-year compliance reporting.
 *
 * Cancelled meetings never count. Approved excuses exclude absences.
 * More than 3 counted absences only flags for admin review — this service
 * never suspends, restricts, or disables accounts.
 */
class MeetingAttendanceReportService
{
    public const PERMISSION_VIEW = 'meetings.reports.view';

    public function __construct(
        private readonly MeetingEligibilityService $eligibility,
    ) {}

    /**
     * @return array{
     *     meeting: ResellerMeeting,
     *     eligible_count: int,
     *     present_count: int,
     *     excused_count: int,
     *     absent_count: int,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function meetingReport(ResellerMeeting $meeting): array
    {
        $eligibleCompanies = $this->eligibility->eligibleCompaniesFor($meeting);
        $attendances = ResellerMeetingAttendance::query()
            ->where('meeting_id', $meeting->id)
            ->get()
            ->keyBy(fn (ResellerMeetingAttendance $row): int => (int) $row->company_id);
        $excuses = ResellerMeetingExcuse::query()
            ->where('meeting_id', $meeting->id)
            ->where('status', MeetingExcuseStatus::APPROVED->value)
            ->get()
            ->keyBy(fn (ResellerMeetingExcuse $row): int => (int) $row->company_id);

        $rows = [];
        $present = 0;
        $excused = 0;
        $absent = 0;

        foreach ($eligibleCompanies as $company) {
            $attendance = $attendances->get((int) $company->id);
            $excuse = $excuses->get((int) $company->id);

            if ($meeting->isCancelled()) {
                $status = 'CANCELLED_EXCLUDED';
            } elseif ($attendance !== null) {
                $status = 'PRESENT';
                $present++;
            } elseif ($excuse !== null) {
                $status = 'EXCUSED';
                $excused++;
            } else {
                $status = 'ABSENT';
                $absent++;
            }

            $rows[] = [
                'company_id' => (int) $company->id,
                'company_uuid' => $company->uuid,
                'company_name' => $company->name,
                'eligible_from' => $this->eligibility->eligibleFrom($company)?->toDateString(),
                'status' => $status,
                'checked_in_at' => $attendance?->checked_in_at,
                'check_in_method' => $attendance?->check_in_method,
                'excuse_reason' => $excuse?->reason,
                'excuse_approved_at' => $excuse?->approved_at,
            ];
        }

        return [
            'meeting' => $meeting,
            'eligible_count' => $eligibleCompanies->count(),
            'present_count' => $present,
            'excused_count' => $excused,
            'absent_count' => $meeting->isCancelled() ? 0 : $absent,
            'rows' => $rows,
        ];
    }

    /**
     * Calendar-year (January–December) compliance summary per eligible company.
     *
     * @return list<array<string, mixed>>
     */
    public function calendarYearCompliance(int $year): array
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        $meetings = ResellerMeeting::query()
            ->whereBetween('scheduled_at', [$start, $end])
            ->orderBy('scheduled_at')
            ->get();

        /** @var array<int, array<string, mixed>> $byCompany */
        $byCompany = [];

        foreach ($meetings as $meeting) {
            if ($meeting->isCancelled()) {
                continue;
            }

            $report = $this->meetingReport($meeting);

            foreach ($report['rows'] as $row) {
                $companyId = (int) $row['company_id'];

                if (! isset($byCompany[$companyId])) {
                    $byCompany[$companyId] = [
                        'company_id' => $companyId,
                        'company_uuid' => $row['company_uuid'],
                        'company_name' => $row['company_name'],
                        'eligible_from' => $row['eligible_from'],
                        'meetings_eligible' => 0,
                        'present' => 0,
                        'excused' => 0,
                        'absent' => 0,
                        'review_flagged' => false,
                    ];
                }

                if ($row['status'] === 'CANCELLED_EXCLUDED') {
                    continue;
                }

                $byCompany[$companyId]['meetings_eligible']++;

                match ($row['status']) {
                    'PRESENT' => $byCompany[$companyId]['present']++,
                    'EXCUSED' => $byCompany[$companyId]['excused']++,
                    'ABSENT' => $byCompany[$companyId]['absent']++,
                    default => null,
                };
            }
        }

        foreach ($byCompany as &$summary) {
            $summary['review_flagged'] = $summary['absent'] > MeetingEligibilityService::ABSENCE_REVIEW_THRESHOLD;
        }
        unset($summary);

        return array_values($byCompany);
    }

    public function countedAbsencesForCompany(Company $company, int $year): int
    {
        $summaries = collect($this->calendarYearCompliance($year))
            ->firstWhere('company_id', (int) $company->id);

        return (int) ($summaries['absent'] ?? 0);
    }

    public function isFlaggedForReview(Company $company, int $year): bool
    {
        return $this->countedAbsencesForCompany($company, $year)
            > MeetingEligibilityService::ABSENCE_REVIEW_THRESHOLD;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function reviewFlaggedCompanies(int $year): Collection
    {
        return collect($this->calendarYearCompliance($year))
            ->filter(static fn (array $row): bool => (bool) $row['review_flagged'])
            ->values();
    }
}
