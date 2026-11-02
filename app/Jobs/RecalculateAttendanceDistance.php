<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Services\DistanceCalculationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateAttendanceDistance implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public function __construct(public int $attendanceId) {}

    public function uniqueId(): string
    {
        return 'attendance-distance-'.$this->attendanceId;
    }

    public function handle(DistanceCalculationService $service): void
    {
        $attendance = Attendance::find($this->attendanceId);

        if ($attendance) {
            $service->recalculate($attendance);
        }
    }
}
