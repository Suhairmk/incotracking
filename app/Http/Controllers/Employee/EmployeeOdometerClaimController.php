<?php

namespace App\Http\Controllers\Employee;

use App\Enums\OdometerClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\OdometerClaimRequest;
use App\Http\Resources\OdometerClaimResource;
use App\Models\Attendance;
use App\Models\OdometerClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The field executive's own odometer claims: one reading per work session,
 * submitted against the GPS distance the app measured for the same session.
 *
 * A reading may be corrected freely while it is pending or rejected; once an
 * administrator approves it, it becomes the baseline for every later claim
 * and is locked.
 */
class EmployeeOdometerClaimController extends Controller
{
    /**
     * Submit or correct the reading for one session of the caller's own.
     */
    public function store(OdometerClaimRequest $request, Attendance $attendance): JsonResponse
    {
        $this->authorize('view', $attendance);

        $readingMeters = $request->odometerMeters();
        $claim = OdometerClaim::query()->where('attendance_id', $attendance->id)->first();

        if ($claim !== null && $claim->isApproved()) {
            return response()->json([
                'message' => 'This reading has already been approved and can no longer be changed.',
                'data' => new OdometerClaimResource($claim),
            ], 422);
        }

        $baseline = OdometerClaim::latestApprovedReadingBefore(
            (int) $attendance->employee_id,
            $attendance->work_date->format('Y-m-d'),
        );

        // Odometers never run backwards: the reading must at least reach the
        // last approved one, otherwise the claimed distance would be
        // negative and the report would lose kilometres.
        if ($baseline !== null && $readingMeters < $baseline->odometer_meters) {
            return response()->json([
                'message' => 'The reading is lower than the last approved reading of '
                    .round($baseline->odometer_meters / 1000, 1).' km.',
            ], 422);
        }

        $claim ??= new OdometerClaim([
            'employee_id' => $attendance->employee_id,
            'attendance_id' => $attendance->id,
        ]);

        $claim->forceFill([
            'work_date' => $attendance->work_date,
            'odometer_meters' => $readingMeters,
            'gps_distance_meters' => (int) $attendance->total_distance_meters,
            'status' => OdometerClaimStatus::Pending,
            'admin_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
        $claim->refreshClaimedDistance();
        $claim->save();

        return response()->json([
            'data' => new OdometerClaimResource($claim->load(['employee', 'attendance'])),
            'message' => $claim->wasRecentlyCreated
                ? 'Reading submitted for approval.'
                : 'Reading updated and sent for approval again.',
        ], $claim->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * The caller's own claim history, newest first — what the attendance
     * list shows a badge against.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'attendance_id' => ['nullable', 'integer', 'exists:attendance,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = OdometerClaim::query()
            ->where('employee_id', $request->user()->id)
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['attendance_id'] ?? null, fn ($q, $id) => $q->where('attendance_id', $id))
            ->with(['attendance', 'reviewer'])
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        return response()->json([
            'data' => OdometerClaimResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
