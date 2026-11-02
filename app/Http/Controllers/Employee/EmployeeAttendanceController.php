<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EndAttendanceRequest;
use App\Http\Requests\Employee\PauseAttendanceRequest;
use App\Http\Requests\Employee\ResumeAttendanceRequest;
use App\Http\Requests\Employee\StartAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Services\AttendanceService;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendanceService) {}

    public function today(Request $request): JsonResponse
    {
        $attendance = Attendance::query()
            ->where('employee_id', $request->user()->id)
            ->whereDate('work_date', BusinessTime::today())
            ->latest('start_time')
            ->first();

        return response()->json([
            'data' => $attendance ? new AttendanceResource($attendance->load('employee')) : null,
        ]);
    }

    public function start(StartAttendanceRequest $request): JsonResponse
    {
        $result = $this->attendanceService->start(
            $request->user(),
            $request->validated(),
            $request->file('photo'),
            $request->file('meter_photo')
        );

        return response()->json([
            'data' => new AttendanceResource($result['attendance']),
            'meta' => ['created' => $result['created']],
            'message' => $result['created'] ? 'Work started.' : 'Attendance session restored.',
        ], $result['created'] ? 201 : 200);
    }

    public function end(EndAttendanceRequest $request): JsonResponse
    {
        $attendance = $this->attendanceService->end(
            $request->user(),
            $request->validated(),
            $request->file('photo'),
            $request->file('meter_photo')
        );

        return response()->json([
            'data' => new AttendanceResource($attendance),
            'message' => 'Work ended. Daily summary is ready.',
        ]);
    }

    public function pause(PauseAttendanceRequest $request): JsonResponse
    {
        $attendance = $this->attendanceService->pause(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data' => new AttendanceResource($attendance),
            'message' => 'Work day paused. Your break is not counted as working time.',
        ]);
    }

    public function resume(ResumeAttendanceRequest $request): JsonResponse
    {
        $attendance = $this->attendanceService->resume(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data' => new AttendanceResource($attendance),
            'message' => 'Welcome back. Tracking continues from now.',
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:active,completed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Attendance::query()
            ->where('employee_id', $request->user()->id)
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->whereDate('work_date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->whereDate('work_date', '<=', $to))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('work_date')
            ->orderByDesc('start_time');

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();

        return response()->json([
            'data' => AttendanceResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorize('view', $attendance);

        return response()->json([
            'data' => new AttendanceResource($attendance->load('employee')),
        ]);
    }
}
