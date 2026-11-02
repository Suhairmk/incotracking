<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Enums\TrackingState;
use App\Models\Attendance;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DetectTrackingInterruptions extends Command
{
    protected $signature = 'tracking:detect-interruptions';

    protected $description = 'Mark active sessions with stale heartbeats as interrupted and notify admins';

    public function handle(ActivityLogger $logger, NotificationService $notifications): int
    {
        $minutes = (int) config('tracking.stale_after_minutes', 10);
        $threshold = now('UTC')->subMinutes($minutes);
        $detected = 0;

        Attendance::query()
            ->where('status', AttendanceStatus::Active->value)
            ->where(function ($query) use ($threshold) {
                $query->where('last_heartbeat_at', '<', $threshold)
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNull('last_heartbeat_at')->where('start_time', '<', $threshold);
                    });
            })
            ->where('tracking_state', '!=', TrackingState::Interrupted->value)
            ->with('employee')
            ->orderBy('id')
            ->chunkById(100, function ($attendances) use ($logger, $notifications, &$detected, $minutes) {
                foreach ($attendances as $attendance) {
                    $attendance->forceFill(['tracking_state' => TrackingState::Interrupted])->save();
                    $logger->log('tracking.interrupted', null, $attendance, [
                        'last_heartbeat_at' => $attendance->last_heartbeat_at?->toIso8601String(),
                    ]);
                    $detected++;

                    $cacheKey = 'tracking-interruption-notified:'.$attendance->id;

                    if (Cache::add($cacheKey, true, now()->addMinutes(60))) {
                        $notifications->notifyAdmins(
                            'Tracking interrupted',
                            "{$attendance->employee?->name} has not sent a tracking heartbeat for {$minutes} minutes.",
                            [
                                'type' => 'tracking_interrupted',
                                'attendance_id' => $attendance->id,
                                'employee_id' => $attendance->employee_id,
                            ]
                        );
                    }
                }
            });

        $this->info("Sessions marked interrupted: {$detected}");

        return self::SUCCESS;
    }
}
