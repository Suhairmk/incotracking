<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OdometerClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OdometerClaimResource;
use App\Models\OdometerClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The administrator's odometer review queue: every claim, GPS versus
 * reading side by side, approved or rejected with a note.
 *
 * Approval locks the reading (it becomes the baseline for the employee's
 * next claim) and stamps who decided it; rejection sends it back for
 * correction. Neither verdict ever rewrites the GPS distance — that stays
 * measured, not claimed.
 */
class AdminOdometerClaimController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $paginator = OdometerClaim::query()
            ->with(['employee', 'attendance', 'reviewer'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->whereDate('work_date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->whereDate('work_date', '<=', $to))
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->withQueryString();

        $counts = OdometerClaim::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => OdometerClaimResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'counts' => [
                    'pending' => (int) ($counts['pending'] ?? 0),
                    'approved' => (int) ($counts['approved'] ?? 0),
                    'rejected' => (int) ($counts['rejected'] ?? 0),
                ],
            ],
        ]);
    }

    public function approve(Request $request, OdometerClaim $odometerClaim): JsonResponse
    {
        $this->authorize('review', $odometerClaim);

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($odometerClaim->isApproved()) {
            return response()->json([
                'data' => new OdometerClaimResource($odometerClaim),
                'message' => 'This claim is already approved.',
            ]);
        }

        $odometerClaim->forceFill([
            'status' => OdometerClaimStatus::Approved,
            'admin_notes' => $validated['admin_notes'] ?? $odometerClaim->admin_notes,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now('UTC'),
        ]);

        // Baselines shift when an earlier claim is approved after a later
        // one, so every claim of this employee is re-resolved on approve.
        $odometerClaim->save();
        $this->refreshEmployeeChain((int) $odometerClaim->employee_id);

        return response()->json([
            'data' => new OdometerClaimResource($odometerClaim->fresh(['employee', 'attendance', 'reviewer'])),
            'message' => 'Reading approved.',
        ]);
    }

    public function reject(Request $request, OdometerClaim $odometerClaim): JsonResponse
    {
        $this->authorize('review', $odometerClaim);

        $validated = $request->validate([
            'admin_notes' => ['required', 'string', 'max:1000'],
        ]);

        $odometerClaim->forceFill([
            'status' => OdometerClaimStatus::Rejected,
            'admin_notes' => $validated['admin_notes'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now('UTC'),
        ])->save();

        $this->refreshEmployeeChain((int) $odometerClaim->employee_id);

        return response()->json([
            'data' => new OdometerClaimResource($odometerClaim->fresh(['employee', 'attendance', 'reviewer'])),
            'message' => 'Reading rejected. The executive can correct and resubmit it.',
        ]);
    }

    /**
     * A claim's distance is measured from the previous *approved* reading,
     * so every verdict re-runs the chain for that employee in date order —
     * otherwise approving an older claim out of turn would leave the newer
     * ones quoting a stale baseline.
     */
    private function refreshEmployeeChain(int $employeeId): void
    {
        OdometerClaim::query()
            ->where('employee_id', $employeeId)
            ->orderBy('work_date')
            ->orderBy('id')
            ->get()
            ->each(function (OdometerClaim $claim) use ($employeeId): void {
                $previous = OdometerClaim::latestApprovedReadingBefore(
                    $employeeId,
                    $claim->work_date->format('Y-m-d'),
                );

                $claim->previous_odometer_meters = $previous?->odometer_meters;
                $claim->claimed_distance_meters = $previous === null
                    ? null
                    : max(0, $claim->odometer_meters - $previous->odometer_meters);
                $claim->save();
            });
    }
}
