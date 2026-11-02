<?php

namespace App\Http\Requests\Employee;

use App\Rules\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class EndAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_client_id' => ['required', new Ulid],
            'end_client_id' => ['required', new Ulid],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['required', 'numeric', 'between:0,65535'],
            'device_recorded_at' => ['required', 'date'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'photo' => [
                'required',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:'.(int) config('tracking.photo.max_upload_mb', 8) * 1024,
            ],
            'meter_photo' => [
                'required',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:'.(int) config('tracking.photo.max_upload_mb', 8) * 1024,
            ],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->filled('device_recorded_at')) {
                    $time = CarbonImmutable::parse($this->input('device_recorded_at'), 'UTC');
                    $maxDays = (int) config('tracking.history_max_days', 30);
                    if ($time->lt(now('UTC')->subDays($maxDays)) || $time->gt(now('UTC')->addMinutes(5))) {
                        $validator->errors()->add(
                            'device_recorded_at',
                            'The device time is outside the permitted synchronization window.'
                        );
                    }
                }
            },
        ];
    }
}
