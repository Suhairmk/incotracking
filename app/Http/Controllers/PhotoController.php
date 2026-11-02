<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller
{
    public function attendance(Request $request, Attendance $attendance, string $type): StreamedResponse
    {
        $this->authorize('view', $attendance);

        $path = match ($type) {
            'start' => $attendance->start_photo_path,
            'start_thumb' => $attendance->start_photo_thumb_path ?? $attendance->start_photo_path,
            'start_meter' => $attendance->start_meter_photo_path,
            'start_meter_thumb' => $attendance->start_meter_photo_thumb_path ?? $attendance->start_meter_photo_path,
            'end' => $attendance->end_photo_path,
            'end_thumb' => $attendance->end_photo_thumb_path ?? $attendance->end_photo_path,
            'end_meter' => $attendance->end_meter_photo_path,
            'end_meter_thumb' => $attendance->end_meter_photo_thumb_path ?? $attendance->end_meter_photo_path,
            default => abort(404),
        };

        return $this->streamImage($path);
    }

    public function visit(Request $request, Visit $visit, string $variant = 'full'): StreamedResponse
    {
        $this->authorize('view', $visit);

        $path = $variant === 'thumb'
            ? ($visit->photo_thumb_path ?? $visit->photo_path)
            : $visit->photo_path;

        return $this->streamImage($path);
    }

    public function profile(Request $request, User $user, string $variant = 'full'): StreamedResponse
    {
        $this->authorize('view', $user);

        $path = $variant === 'thumb'
            ? ($user->profile_photo_thumb_path ?? $user->profile_photo_path)
            : $user->profile_photo_path;

        return $this->streamImage($path);
    }

    private function streamImage(?string $path): StreamedResponse
    {
        if (! $path) {
            abort(404, 'Photo not found.');
        }

        $disk = Storage::disk(config('filesystems.default'));

        if (! $disk->exists($path)) {
            abort(404, 'Photo not found.');
        }

        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) $disk->size($path),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
