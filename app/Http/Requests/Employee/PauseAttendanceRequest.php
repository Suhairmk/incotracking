<?php

namespace App\Http\Requests\Employee;

use App\Rules\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class PauseAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_client_id' => ['required', new Ulid],
            'pause_client_id' => ['required', new Ulid],
            'device_recorded_at' => ['required', 'date'],
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
