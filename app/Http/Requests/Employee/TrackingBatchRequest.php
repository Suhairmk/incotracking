<?php

namespace App\Http\Requests\Employee;

use App\Enums\TrackingState;
use App\Rules\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrackingBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_client_id' => ['required', new Ulid],
            'batch_id' => ['nullable', new Ulid],
            'tracking_state' => [
                'required',
                Rule::in([
                    TrackingState::Unknown->value,
                    TrackingState::Moving->value,
                    TrackingState::Stationary->value,
                    TrackingState::Interrupted->value,
                ]),
            ],
            'heartbeat' => ['sometimes', 'boolean'],
            'points' => ['present', 'array', 'max:'.(int) config('tracking.batch_max_points', 500)],
            'points.*.client_point_id' => ['required', new Ulid, 'distinct'],
            'points.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'points.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy_meters' => ['required', 'numeric', 'between:0,65535'],
            'points.*.speed_kmh' => ['nullable', 'numeric', 'between:0,500'],
            'points.*.heading_degrees' => ['nullable', 'numeric', 'between:0,360'],
            'points.*.recorded_at' => ['required', 'date'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $maxDays = (int) config('tracking.history_max_days', 30);
                $earliest = now('UTC')->subDays($maxDays);
                $latest = now('UTC')->addMinutes(5);

                foreach ((array) $this->input('points', []) as $index => $point) {
                    if (! isset($point['recorded_at'])) {
                        continue;
                    }

                    try {
                        $time = CarbonImmutable::parse($point['recorded_at'], 'UTC');
                    } catch (\Throwable) {
                        continue;
                    }

                    if ($time->lt($earliest) || $time->gt($latest)) {
                        $validator->errors()->add(
                            "points.{$index}.recorded_at",
                            'The GPS sample time is outside the permitted synchronization window.'
                        );
                    }
                }
            },
        ];
    }
}
