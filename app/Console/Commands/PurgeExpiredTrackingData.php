<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\LocationPoint;
use App\Models\Visit;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredTrackingData extends Command
{
    protected $signature = 'tracking:purge
        {--dry-run : Report what would be deleted without deleting}
        {--photos : Also purge expired attendance and visit photographs}';

    protected $description = 'Archive/delete expired raw GPS points while preserving attendance summaries';

    public function handle(ActivityLogger $logger): int
    {
        $gpsDays = (int) config('tracking.gps_retention_days', 180);
        $cutoff = now('UTC')->subDays($gpsDays)->startOfDay();
        $dryRun = (bool) $this->option('dry-run');
        $archive = (bool) config('tracking.enable_gps_archival', true);

        $this->info("Raw GPS retention: {$gpsDays} days (cutoff {$cutoff->toDateString()}).");
        $totalPoints = 0;

        Attendance::query()
            ->where('status', AttendanceStatus::Completed->value)
            ->where('work_date', '<', $cutoff->toDateString())
            ->whereHas('locationPoints')
            ->orderBy('id')
            ->chunkById(50, function ($attendances) use (&$totalPoints, $dryRun, $archive, $logger) {
                foreach ($attendances as $attendance) {
                    $count = $attendance->locationPoints()->count();

                    if ($dryRun) {
                        $this->line("[dry-run] attendance {$attendance->id}: {$count} points");
                        $totalPoints += $count;

                        continue;
                    }

                    try {
                        if ($archive) {
                            $this->archiveAttendance($attendance);
                        }

                        $deleted = LocationPoint::query()
                            ->where('attendance_id', $attendance->id)
                            ->delete();

                        $totalPoints += $deleted;
                        $logger->log('retention.gps_purged', null, $attendance, [
                            'deleted_points' => $deleted,
                            'archived' => $archive,
                        ]);
                    } catch (\Throwable $e) {
                        report($e);
                        $this->error("Attendance {$attendance->id}: ".$e->getMessage());
                    }
                }
            });

        $this->info("GPS points processed: {$totalPoints}");

        if ($this->option('photos')) {
            $this->purgePhotos($dryRun, $logger);
        }

        return self::SUCCESS;
    }

    private function archiveAttendance(Attendance $attendance): void
    {
        $disk = Storage::disk(config('filesystems.default'));
        $path = sprintf(
            'archives/gps/%s/attendance-%d.ndjson.gz',
            $attendance->work_date->format('Y'),
            $attendance->id
        );
        $tempPath = tempnam(sys_get_temp_dir(), 'gps_archive_');

        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create a GPS archive file.');
        }

        $gzipPath = $tempPath.'.ndjson.gz';
        @unlink($tempPath);
        $handle = gzopen($gzipPath, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open the GPS archive for writing.');
        }

        try {
            $attendance->locationPoints()
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->chunk(2000, function ($points) use ($handle) {
                    foreach ($points as $point) {
                        gzwrite($handle, json_encode([
                            'client_point_id' => $point->client_point_id,
                            'latitude_e7' => $point->latitude_e7,
                            'longitude_e7' => $point->longitude_e7,
                            'accuracy_meters' => $point->accuracy_meters,
                            'speed_cm_per_second' => $point->speed_cm_per_second,
                            'heading_degrees' => $point->heading_degrees,
                            'recorded_at' => $point->recorded_at?->toIso8601String(),
                            'quality_status' => $point->quality_status?->value,
                            'rejection_reason' => $point->rejection_reason,
                        ], JSON_UNESCAPED_SLASHES)."\n");
                    }
                });
        } finally {
            gzclose($handle);
        }

        $stream = fopen($gzipPath, 'rb');

        if ($stream === false) {
            @unlink($gzipPath);

            throw new \RuntimeException('Unable to read the GPS archive.');
        }

        try {
            $stored = $disk->writeStream($path, $stream);
        } finally {
            fclose($stream);
            @unlink($gzipPath);
        }

        if (! $stored) {
            throw new \RuntimeException('Unable to store the GPS archive.');
        }
    }

    private function purgePhotos(bool $dryRun, ActivityLogger $logger): void
    {
        $days = (int) config('tracking.photo_retention_days', 365);
        $cutoff = now('UTC')->subDays($days);
        $this->info("Photo retention: {$days} days.");
        $disk = Storage::disk(config('filesystems.default'));

        Visit::query()
            ->where('server_recorded_at', '<', $cutoff)
            ->whereNotNull('photo_path')
            ->orderBy('id')
            ->chunkById(100, function ($visits) use ($disk, $dryRun, $logger) {
                foreach ($visits as $visit) {
                    if ($dryRun) {
                        $this->line("[dry-run] visit photo {$visit->id}");

                        continue;
                    }

                    $disk->delete(array_filter([$visit->photo_path, $visit->photo_thumb_path]));
                    $visit->forceFill(['photo_path' => null, 'photo_thumb_path' => null])->save();
                    $logger->log('retention.photo_purged', null, $visit);
                }
            });

        Attendance::query()
            ->where('status', AttendanceStatus::Completed->value)
            ->where('start_time', '<', $cutoff)
            ->where(function ($query) {
                $query->whereNotNull('start_photo_path')
                    ->orWhereNotNull('end_photo_path')
                    ->orWhereNotNull('start_meter_photo_path')
                    ->orWhereNotNull('end_meter_photo_path');
            })
            ->orderBy('id')
            ->chunkById(100, function ($attendances) use ($disk, $dryRun, $logger) {
                foreach ($attendances as $attendance) {
                    if ($dryRun) {
                        $this->line("[dry-run] attendance photos {$attendance->id}");

                        continue;
                    }

                    $disk->delete(array_filter([
                        $attendance->start_photo_path,
                        $attendance->start_photo_thumb_path,
                        $attendance->start_meter_photo_path,
                        $attendance->start_meter_photo_thumb_path,
                        $attendance->end_photo_path,
                        $attendance->end_photo_thumb_path,
                        $attendance->end_meter_photo_path,
                        $attendance->end_meter_photo_thumb_path,
                    ]));
                    $attendance->forceFill([
                        'start_photo_path' => null,
                        'start_photo_thumb_path' => null,
                        'start_meter_photo_path' => null,
                        'start_meter_photo_thumb_path' => null,
                        'end_photo_path' => null,
                        'end_photo_thumb_path' => null,
                        'end_meter_photo_path' => null,
                        'end_meter_photo_thumb_path' => null,
                    ])->save();
                    $logger->log('retention.attendance_photos_purged', null, $attendance);
                }
            });
    }
}
