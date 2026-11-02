<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\LocationQuality;
use App\Enums\TrackingState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Attendance extends Model
{
    use HasFactory;

    protected $table = 'attendance';

    protected $fillable = [
        'employee_id',
        'work_date',
        'start_time',
        'end_time',
        'start_device_recorded_at',
        'end_device_recorded_at',
        'start_device_id',
        'end_device_id',
        'start_client_id',
        'end_client_id',
        'start_latitude_e7',
        'start_longitude_e7',
        'start_accuracy',
        'end_latitude_e7',
        'end_longitude_e7',
        'end_accuracy',
        'start_photo_path',
        'start_photo_thumb_path',
        'start_meter_photo_path',
        'start_meter_photo_thumb_path',
        'end_photo_path',
        'end_photo_thumb_path',
        'end_meter_photo_path',
        'end_meter_photo_thumb_path',
        'paused_at',
        'paused_device_recorded_at',
        'paused_client_id',
        'resumed_at',
        'total_paused_seconds',
        'total_distance_meters',
        'total_working_seconds',
        'accepted_points_count',
        'rejected_points_count',
        'visits_count',
        'last_location_at',
        'last_heartbeat_at',
        'tracking_state',
        'status',
        'last_processed_point_id',
        'last_processed_recorded_at',
        'last_processed_latitude_e7',
        'last_processed_longitude_e7',
        'last_processed_accuracy',
        'distance_needs_recalc',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'start_device_recorded_at' => 'datetime',
            'end_device_recorded_at' => 'datetime',
            'paused_at' => 'datetime',
            'paused_device_recorded_at' => 'datetime',
            'resumed_at' => 'datetime',
            'total_paused_seconds' => 'integer',
            'last_location_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_processed_recorded_at' => 'datetime',
            'status' => AttendanceStatus::class,
            'tracking_state' => TrackingState::class,
            'distance_needs_recalc' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function locationPoints(): HasMany
    {
        return $this->hasMany(LocationPoint::class);
    }

    public function acceptedLocationPoints(): HasMany
    {
        return $this->locationPoints()->where('quality_status', LocationQuality::Accepted->value);
    }

    public function liveLocation(): HasOne
    {
        return $this->hasOne(EmployeeLiveLocation::class, 'attendance_id');
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('work_date', $date);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AttendanceStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === AttendanceStatus::Active;
    }

    public function isPaused(): bool
    {
        return $this->isActive() && $this->paused_at !== null;
    }

    /**
     * Worked time at a reference moment, i.e. the elapsed span minus every
     * recorded break, including a break that is still open. Completed
     * sessions return their stored total.
     */
    public function workedSeconds(CarbonInterface $now): int
    {
        if ($this->status === AttendanceStatus::Completed) {
            return (int) $this->total_working_seconds;
        }

        $startedAt = $this->start_device_recorded_at ?? $this->start_time;
        if (! $startedAt) {
            return 0;
        }

        $reference = $this->end_time ?? $now;
        $span = max(0, (int) $startedAt->diffInSeconds($reference, false));

        $pausedSeconds = (int) $this->total_paused_seconds;
        if ($this->paused_at !== null) {
            $pauseStart = $this->paused_device_recorded_at ?? $this->paused_at;
            $pausedSeconds += max(0, (int) $pauseStart->diffInSeconds($reference, false));
        }

        return max(0, min($span, 36 * 3600) - min($pausedSeconds, 36 * 3600));
    }
}
