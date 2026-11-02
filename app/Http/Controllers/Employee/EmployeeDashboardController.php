<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\UserResource;
use App\Models\Attendance;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = BusinessTime::today();

        $active = Attendance::query()
            ->where('employee_id', $user->id)
            ->active()
            ->latest('start_time')
            ->first();

        $attendance = $active
            ?? Attendance::query()
                ->where('employee_id', $user->id)
                ->whereDate('work_date', $today)
                ->latest('start_time')
                ->first();

        $attendance?->load('employee');

        $status = match (true) {
            $attendance === null => 'not_started',
            $attendance->isActive() => 'working',
            default => 'completed',
        };

        $live = $attendance?->liveLocation;

        return response()->json([
            'data' => [
                'business_date' => $today,
                'server_time' => now('UTC')->toIso8601String(),
                'employee' => new UserResource($user),
                'attendance' => $attendance ? new AttendanceResource($attendance) : null,
                'today' => [
                    'status' => $status,
                    'distance_meters' => (int) ($attendance?->total_distance_meters ?? 0),
                    'distance_km' => round((float) ($attendance?->total_distance_meters ?? 0) / 1000, 3),
                    'visits' => (int) ($attendance?->visits_count ?? 0),
                    'accepted_points' => (int) ($attendance?->accepted_points_count ?? 0),
                    'working_seconds' => $attendance?->workedSeconds(now('UTC')) ?? 0,
                    'last_location_at' => $attendance?->last_location_at?->toIso8601String(),
                    'last_heartbeat_at' => $attendance?->last_heartbeat_at?->toIso8601String(),
                    'tracking_state' => $attendance?->tracking_state?->value,
                ],
                'live_location' => $live ? [
                    'latitude' => $live->latitude_e7 !== null ? $live->latitude_e7 / 10_000_000 : null,
                    'longitude' => $live->longitude_e7 !== null ? $live->longitude_e7 / 10_000_000 : null,
                    'accuracy_meters' => $live->accuracy_meters,
                    'last_recorded_at' => $live->last_recorded_at?->toIso8601String(),
                    'tracking_state' => $live->tracking_state?->value,
                ] : null,
            ],
        ]);
    }
}
