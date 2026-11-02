<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LiveLocationResource;
use App\Models\Attendance;
use App\Models\EmployeeLiveLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLiveLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $attendances = Attendance::query()
            ->active()
            ->with(['employee', 'liveLocation'])
            ->whereHas('employee', function ($query) use ($validated) {
                $query->where('is_active', true)
                    ->when($validated['search'] ?? null, function ($q, $search) {
                        $like = '%'.trim($search).'%';
                        $q->where(function ($inner) use ($like) {
                            $inner->where('name', 'like', $like)
                                ->orWhere('employee_code', 'like', $like);
                        });
                    });
            })
            ->orderBy('start_time')
            ->limit(500)
            ->get();

        $locations = $attendances->map(function (Attendance $attendance) {
            $live = $attendance->liveLocation ?? new EmployeeLiveLocation([
                'employee_id' => $attendance->employee_id,
                'attendance_id' => $attendance->id,
                'latitude_e7' => $attendance->start_latitude_e7,
                'longitude_e7' => $attendance->start_longitude_e7,
                'accuracy_meters' => $attendance->start_accuracy,
                'last_recorded_at' => $attendance->start_time,
                'tracking_state' => 'unknown',
            ]);

            $live->setRelation('employee', $attendance->employee);
            $live->setRelation('attendance', $attendance);

            return (new LiveLocationResource($live))->resolve($request);
        })->values();

        return response()->json([
            'data' => $locations,
            'meta' => [
                'count' => $locations->count(),
                'limited' => $attendances->count() >= 500,
                'server_time' => now('UTC')->toIso8601String(),
            ],
        ]);
    }
}
