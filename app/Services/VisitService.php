<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Visit;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VisitService
{
    public function __construct(
        private readonly ImageStorageService $images,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return array{visit: Visit, created: bool}
     */
    public function create(User $employee, Attendance $attendance, array $data, UploadedFile $photo): array
    {
        $clientId = Str::upper($data['client_visit_id']);

        $existing = Visit::query()
            ->where('employee_id', $employee->id)
            ->where('client_visit_id', $clientId)
            ->first();

        if ($existing) {
            return ['visit' => $existing->load('employee'), 'created' => false];
        }

        if ($attendance->status !== AttendanceStatus::Active) {
            throw new ConflictHttpException('Visits can only be added during an active work session.');
        }

        $stored = $this->images->store(
            $photo,
            "visits/{$employee->id}",
            $clientId
        );

        $serverNow = CarbonImmutable::now('UTC');

        try {
            $visit = DB::transaction(function () use ($employee, $attendance, $data, $clientId, $serverNow, $stored) {
                $visit = Visit::create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $attendance->id,
                    'client_visit_id' => $clientId,
                    'customer_name' => $data['customer_name'],
                    'contact_person' => $data['contact_person'] ?? null,
                    'contact_number' => $data['contact_number'] ?? null,
                    'photo_path' => $stored['path'],
                    'photo_thumb_path' => $stored['thumb_path'],
                    'latitude_e7' => Geo::toE7((float) $data['latitude']),
                    'longitude_e7' => Geo::toE7((float) $data['longitude']),
                    'accuracy_meters' => (int) round((float) $data['accuracy_meters']),
                    'device_recorded_at' => CarbonImmutable::parse($data['device_recorded_at'], 'UTC'),
                    'server_recorded_at' => $serverNow,
                    'remarks' => $data['remarks'] ?? null,
                    'outcome' => $data['outcome'],
                ]);

                $attendance->increment('visits_count');

                return $visit;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = Visit::query()
                ->where('employee_id', $employee->id)
                ->where('client_visit_id', $clientId)
                ->first();

            if ($existing) {
                return ['visit' => $existing->load('employee'), 'created' => false];
            }

            throw $e;
        }

        $this->logger->log('visit.created', $employee, $visit, [
            'attendance_id' => $attendance->id,
            'outcome' => $data['outcome'],
        ]);

        return ['visit' => $visit->load('employee'), 'created' => true];
    }
}
