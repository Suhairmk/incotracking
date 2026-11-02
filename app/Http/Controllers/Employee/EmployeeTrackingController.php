<?php

namespace App\Http\Controllers\Employee;

use App\Enums\TrackingState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\TrackingBatchRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Services\LocationBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class EmployeeTrackingController extends Controller
{
    public function __construct(private readonly LocationBatchService $batchService) {}

    public function batch(TrackingBatchRequest $request): JsonResponse
    {
        if (! config('tracking.enabled', true)) {
            return response()->json([
                'message' => 'Tracking ingestion is temporarily disabled.',
            ], 503);
        }

        $data = $request->validated();
        $attendance = Attendance::query()
            ->where('employee_id', $request->user()->id)
            ->where('start_client_id', Str::upper($data['start_client_id']))
            ->first();

        if (! $attendance) {
            return response()->json([
                'message' => 'Attendance session not found for this tracking batch.',
            ], 404);
        }

        $this->authorize('view', $attendance);

        $result = $this->batchService->ingest(
            $attendance,
            $data['points'] ?? [],
            TrackingState::from($data['tracking_state']),
            $data['batch_id'] ?? null,
            (bool) ($data['heartbeat'] ?? false),
        );

        return response()->json([
            'data' => [
                'acknowledged_ids' => $result['acknowledged_ids'],
                'duplicate_count' => $result['duplicate_count'],
                'rejected' => $result['rejected'],
                'accepted_count' => $result['accepted_count'],
                'server_time' => $result['server_time']->toIso8601String(),
                'batch_id' => $result['batch_id'],
                'attendance' => new AttendanceResource($result['attendance']),
            ],
        ]);
    }
}
