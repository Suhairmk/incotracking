<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\TrackingState;
use App\Models\Attendance;
use App\Models\EmployeeLiveLocation;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AttendanceService
{
    public function __construct(
        private readonly ImageStorageService $images,
        private readonly DistanceCalculationService $distance,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return array{attendance: Attendance, created: bool}
     */
    public function start(User $employee, array $data, UploadedFile $photo, UploadedFile $meterPhoto): array
    {
        $clientId = Str::upper($data['start_client_id']);

        $existing = Attendance::query()
            ->where('employee_id', $employee->id)
            ->where('start_client_id', $clientId)
            ->first();

        if ($existing) {
            return ['attendance' => $existing->load('employee'), 'created' => false];
        }

        $now = CarbonImmutable::now('UTC');
        $deviceRecordedAt = CarbonImmutable::parse($data['device_recorded_at'], 'UTC');

        // The business work date follows the day the employee actually started
        // work on the device, so an offline start synchronized days later is
        // filed under the correct day.
        $workDate = $deviceRecordedAt->setTimezone(BusinessTime::timezone())->toDateString();

        $sameDay = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($sameDay) {
            throw new ConflictHttpException('An attendance session already exists for today.');
        }

        $stored = $this->images->store(
            $photo,
            "attendance/{$employee->id}",
            $clientId
        );

        try {
            $meterStored = $this->images->store(
                $meterPhoto,
                "attendance/{$employee->id}",
                $clientId.'_meter'
            );
        } catch (\Throwable $e) {
            // The selfie is already on disk; do not leave it behind when the
            // meter reading could not be processed.
            $this->images->delete($stored['path']);
            $this->images->delete($stored['thumb_path']);
            throw $e;
        }

        try {
            $attendance = DB::transaction(function () use ($employee, $data, $clientId, $now, $deviceRecordedAt, $workDate, $stored, $meterStored) {
                return Attendance::create([
                    'employee_id' => $employee->id,
                    'work_date' => $workDate,
                    'start_time' => $now,
                    'start_device_recorded_at' => $deviceRecordedAt,
                    'start_device_id' => $data['device_id'] ?? null,
                    'start_client_id' => $clientId,
                    'start_latitude_e7' => Geo::toE7((float) $data['latitude']),
                    'start_longitude_e7' => Geo::toE7((float) $data['longitude']),
                    'start_accuracy' => (int) $data['accuracy_meters'],
                    'start_photo_path' => $stored['path'],
                    'start_photo_thumb_path' => $stored['thumb_path'],
                    'start_meter_photo_path' => $meterStored['path'],
                    'start_meter_photo_thumb_path' => $meterStored['thumb_path'],
                    'status' => AttendanceStatus::Active,
                    'tracking_state' => TrackingState::Unknown,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->images->delete($stored['path']);
            $this->images->delete($stored['thumb_path']);
            $this->images->delete($meterStored['path']);
            $this->images->delete($meterStored['thumb_path']);

            $attendance = Attendance::query()
                ->where('employee_id', $employee->id)
                ->where('start_client_id', $clientId)
                ->first();

            if ($attendance) {
                return ['attendance' => $attendance->load('employee'), 'created' => false];
            }

            throw new ConflictHttpException('An attendance session already exists for today.');
        }

        $this->logger->log('attendance.started', $employee, $attendance, [
            'work_date' => $workDate,
            'accuracy_meters' => (int) $data['accuracy_meters'],
        ]);

        return ['attendance' => $attendance->load('employee'), 'created' => true];
    }

    public function end(User $employee, array $data, UploadedFile $photo, UploadedFile $meterPhoto): Attendance
    {
        $startClientId = Str::upper($data['start_client_id']);
        $endClientId = Str::upper($data['end_client_id']);

        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->where('start_client_id', $startClientId)
            ->first();

        if (! $attendance) {
            throw new NotFoundHttpException('Active attendance session not found.');
        }

        if ($attendance->end_client_id === $endClientId) {
            return $attendance->load('employee');
        }

        if ($attendance->status === AttendanceStatus::Completed) {
            throw new ConflictHttpException('This attendance session has already ended.');
        }

        $serverNow = CarbonImmutable::now('UTC');
        $deviceAt = CarbonImmutable::parse($data['device_recorded_at'], 'UTC');
        $stored = $this->images->store(
            $photo,
            "attendance/{$employee->id}",
            $endClientId
        );

        try {
            $meterStored = $this->images->store(
                $meterPhoto,
                "attendance/{$employee->id}",
                $endClientId.'_meter'
            );
        } catch (\Throwable $e) {
            $this->images->delete($stored['path']);
            $this->images->delete($stored['thumb_path']);
            throw $e;
        }

        try {
            $attendance = DB::transaction(function () use (
                $attendance,
                $data,
                $endClientId,
                $serverNow,
                $deviceAt,
                $stored,
                $meterStored
            ) {
                /** @var Attendance $locked */
                $locked = Attendance::query()->lockForUpdate()->findOrFail($attendance->id);

                if ($locked->status === AttendanceStatus::Completed) {
                    throw new ConflictHttpException('This attendance session has already ended.');
                }

                $startedAt = $locked->start_device_recorded_at ?? $locked->start_time;
                $effectiveEnd = $deviceAt->lessThanOrEqualTo($serverNow->addMinutes(2))
                    ? $deviceAt
                    : $serverNow;
                $workingSeconds = max(0, (int) $startedAt->diffInSeconds($effectiveEnd, false));
                $workingSeconds = min($workingSeconds, 36 * 3600);

                // Break time never counts as work: subtract every closed pause
                // plus the pause that is still open when the day is closed.
                $pausedSeconds = (int) $locked->total_paused_seconds;
                if ($locked->paused_at !== null) {
                    $pauseStart = $locked->paused_device_recorded_at ?? $locked->paused_at;
                    $pausedSeconds += max(0, (int) $pauseStart->diffInSeconds($effectiveEnd, false));
                }
                $pausedSeconds = min($pausedSeconds, 36 * 3600);
                $workingSeconds = max(0, $workingSeconds - $pausedSeconds);

                $locked->forceFill([
                    'end_time' => $serverNow,
                    'end_device_recorded_at' => $deviceAt,
                    'end_device_id' => $data['device_id'] ?? null,
                    'end_client_id' => $endClientId,
                    'end_latitude_e7' => Geo::toE7((float) $data['latitude']),
                    'end_longitude_e7' => Geo::toE7((float) $data['longitude']),
                    'end_accuracy' => (int) $data['accuracy_meters'],
                    'end_photo_path' => $stored['path'],
                    'end_photo_thumb_path' => $stored['thumb_path'],
                    'end_meter_photo_path' => $meterStored['path'],
                    'end_meter_photo_thumb_path' => $meterStored['thumb_path'],
                    'total_working_seconds' => $workingSeconds,
                    'total_paused_seconds' => $pausedSeconds,
                    'status' => AttendanceStatus::Completed,
                    'tracking_state' => TrackingState::Completed,
                    'last_heartbeat_at' => $serverNow,
                ])->save();

                return $locked;
            });
        } catch (\Throwable $e) {
            // Nothing was written, so the freshly stored photos must not be
            // left behind on disk.
            $this->images->delete($stored['path']);
            $this->images->delete($stored['thumb_path']);
            $this->images->delete($meterStored['path']);
            $this->images->delete($meterStored['thumb_path']);
            throw $e;
        }

        try {
            $attendance = $this->distance->recalculate($attendance);
        } catch (\Throwable $e) {
            $this->distance->markForRecalculation($attendance);
            $attendance->forceFill(['status' => AttendanceStatus::Completed, 'tracking_state' => TrackingState::Completed])->save();
            report($e);
        }

        EmployeeLiveLocation::query()
            ->where('employee_id', $employee->id)
            ->update([
                'tracking_state' => TrackingState::Completed->value,
                'last_heartbeat_at' => $serverNow,
                'last_received_at' => $serverNow,
                'updated_at' => $serverNow,
            ]);

        $this->logger->log('attendance.ended', $employee, $attendance, [
            'total_distance_meters' => $attendance->total_distance_meters,
            'total_working_seconds' => $attendance->total_working_seconds,
            'total_paused_seconds' => (int) $attendance->total_paused_seconds,
        ]);

        return $attendance->load('employee');
    }

    public function pause(User $employee, array $data): Attendance
    {
        $startClientId = Str::upper($data['start_client_id']);
        $pauseClientId = Str::upper($data['pause_client_id']);
        $deviceAt = CarbonImmutable::parse($data['device_recorded_at'], 'UTC');
        $serverNow = CarbonImmutable::now('UTC');

        $attendance = $this->findOpenSession($employee, $startClientId);

        if ($attendance->isPaused()) {
            return $attendance->load('employee');
        }

        DB::transaction(function () use ($attendance, $pauseClientId, $deviceAt, $serverNow) {
            /** @var Attendance $locked */
            $locked = Attendance::query()->lockForUpdate()->findOrFail($attendance->id);

            if ($locked->status === AttendanceStatus::Completed) {
                throw new ConflictHttpException('This attendance session has already ended.');
            }

            if ($locked->paused_at === null) {
                $locked->forceFill([
                    'paused_at' => $serverNow,
                    'paused_device_recorded_at' => $deviceAt,
                    'paused_client_id' => $pauseClientId,
                ])->save();
            }

            return $locked;
        });

        $this->logger->log('attendance.paused', $employee, $attendance, [
            'pause_client_id' => $pauseClientId,
        ]);

        return $attendance->fresh(['employee']);
    }

    public function resume(User $employee, array $data): Attendance
    {
        $startClientId = Str::upper($data['start_client_id']);
        $deviceAt = CarbonImmutable::parse($data['device_recorded_at'], 'UTC');
        $serverNow = CarbonImmutable::now('UTC');

        $attendance = $this->findOpenSession($employee, $startClientId);

        if (! $attendance->isPaused()) {
            return $attendance->load('employee');
        }

        DB::transaction(function () use ($attendance, $deviceAt, $serverNow) {
            /** @var Attendance $locked */
            $locked = Attendance::query()->lockForUpdate()->findOrFail($attendance->id);

            if ($locked->paused_at === null) {
                return $locked;
            }

            $pauseStart = $locked->paused_device_recorded_at ?? $locked->paused_at;
            $pausedSeconds = max(0, (int) $pauseStart->diffInSeconds($deviceAt, false));

            $locked->forceFill([
                'total_paused_seconds' => (int) $locked->total_paused_seconds + $pausedSeconds,
                'paused_at' => null,
                'paused_device_recorded_at' => null,
                'paused_client_id' => null,
                'resumed_at' => $serverNow,
            ])->save();

            return $locked;
        });

        $attendance = $attendance->fresh(['employee']);

        $this->logger->log('attendance.resumed', $employee, $attendance, [
            'total_paused_seconds' => (int) $attendance->total_paused_seconds,
        ]);

        return $attendance;
    }

    private function findOpenSession(User $employee, string $startClientId): Attendance
    {
        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->where('start_client_id', $startClientId)
            ->first();

        if (! $attendance) {
            throw new NotFoundHttpException('Active attendance session not found.');
        }

        if ($attendance->status === AttendanceStatus::Completed) {
            throw new ConflictHttpException('This attendance session has already ended.');
        }

        return $attendance;
    }
}
