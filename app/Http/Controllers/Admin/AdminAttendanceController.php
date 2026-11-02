<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\VisitResource;
use App\Models\Attendance;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:active,completed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = $validated['from'] ?? BusinessTime::today();
        $to = $validated['to'] ?? BusinessTime::today();

        $query = Attendance::query()
            ->with('employee')
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to)
            ->when($validated['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('work_date')
            ->orderByDesc('start_time');

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 25))->withQueryString();

        return response()->json([
            'data' => AttendanceResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    public function show(Attendance $attendance): JsonResponse
    {
        $attendance->load('employee');
        $visits = $attendance->visits()
            ->with('employee')
            ->orderByDesc('server_recorded_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => [
                'attendance' => new AttendanceResource($attendance),
                'visits' => VisitResource::collection($visits),
            ],
        ]);
    }
}
