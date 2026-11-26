<?php

namespace App\Models;

use App\Enums\OdometerClaimStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One work session's odometer reading, awaiting (or holding) an
 * administrator's verdict.
 *
 * The reading is always stored beside the GPS distance of the same session
 * rather than instead of it: GPS is measured, the odometer is claimed, and
 * only an approved claim may be quoted as travelled kilometres.
 */
class OdometerClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'attendance_id',
        'work_date',
        'odometer_meters',
        'previous_odometer_meters',
        'claimed_distance_meters',
        'gps_distance_meters',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'odometer_meters' => 'integer',
            'previous_odometer_meters' => 'integer',
            'claimed_distance_meters' => 'integer',
            'gps_distance_meters' => 'integer',
            'status' => OdometerClaimStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === OdometerClaimStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === OdometerClaimStatus::Approved;
    }

    /**
     * The most recent approved reading for this employee before the given
     * work date — the baseline every claimed distance is measured from.
     */
    public static function latestApprovedReadingBefore(int $employeeId, string $workDate): ?self
    {
        return self::query()
            ->where('employee_id', $employeeId)
            ->where('status', OdometerClaimStatus::Approved->value)
            ->whereDate('work_date', '<', $workDate)
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Recomputes the claimed distance against the latest approved reading
     * taken strictly before this claim's own day, so a back-dated or
     * re-approved claim always resolves against the right baseline.
     */
    public function refreshClaimedDistance(): void
    {
        $previous = self::latestApprovedReadingBefore(
            (int) $this->employee_id,
            $this->work_date->format('Y-m-d'),
        );

        $this->previous_odometer_meters = $previous?->odometer_meters;
        $this->claimed_distance_meters = $previous === null
            ? null
            : max(0, $this->odometer_meters - $previous->odometer_meters);
    }
}
