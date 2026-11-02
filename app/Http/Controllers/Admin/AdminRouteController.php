<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LocationQuality;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\LocationPointResource;
use App\Http\Resources\VisitResource;
use App\Models\Attendance;
use App\Models\LocationPoint;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRouteController extends Controller
{
    public function show(Request $request, User $employee): JsonResponse
    {
        abort_unless($employee->isEmployee(), 404);

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:100', 'max:2000'],
        ]);

        $date = BusinessTime::normalizeDate($validated['date'] ?? null);

        $attendance = Attendance::query()
            ->with('employee')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->first();

        if (! $attendance) {
            return response()->json([
                'message' => 'No attendance record found for this employee and date.',
                'data' => null,
            ], 404);
        }

        $points = LocationPoint::query()
            ->where('attendance_id', $attendance->id)
            ->where('quality_status', LocationQuality::Accepted->value)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 1000), ['id', 'latitude_e7', 'longitude_e7', 'accuracy_meters', 'speed_cm_per_second', 'heading_degrees', 'recorded_at', 'quality_status', 'rejection_reason'], 'page', (int) ($validated['page'] ?? 1));

        $visits = $attendance->visits()
            ->orderBy('server_recorded_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => [
                'attendance' => (new AttendanceResource($attendance))->resolve($request),
                'visits' => VisitResource::collection($visits)->resolve($request),
                'points' => LocationPointResource::collection($points->getCollection())->resolve($request),
                'meta' => [
                    'date' => $date,
                    'current_page' => $points->currentPage(),
                    'last_page' => $points->lastPage(),
                    'per_page' => $points->perPage(),
                    'total_points' => $points->total(),
                    'visits_count' => $visits->count(),
                ],
            ],
        ]);
    }
}
