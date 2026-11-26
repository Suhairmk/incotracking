<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonthlySalesTarget;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Monthly sales targets and the performance against them. Achievement is
 * measured on delivered orders at their taxable value (ex-GST).
 */
class AdminTargetController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Every active field executive with their target, achieved sales,
     * remaining amount and achievement percentage for one month.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $month = MonthlySalesTarget::normalizeMonth(
            $validated['month'] ?? now()->startOfMonth()->toDateString()
        );

        return response()->json([
            'data' => $this->reports->performanceForMonth(
                $month,
                $validated['employee_id'] ?? null,
            ),
            'meta' => [
                'month' => $month,
            ],
        ]);
    }

    /**
     * Create or replace one employee's target for a month.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('users', 'id')
                ->where('role', 'employee')->where('is_active', true)],
            'month' => ['required', 'date_format:Y-m-d'],
            'target_paise' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ]);

        $target = MonthlySalesTarget::updateOrCreate(
            [
                'employee_id' => (int) $validated['employee_id'],
                'month' => MonthlySalesTarget::normalizeMonth($validated['month']),
            ],
            [
                'target_paise' => (int) $validated['target_paise'],
                'updated_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'data' => [
                'employee_id' => $target->employee_id,
                'month' => $target->month->toDateString(),
                'target_paise' => $target->target_paise,
            ],
            'message' => 'Target saved.',
        ], 201);
    }

    /**
     * Remove a target: the employee simply reports as "no target set".
     */
    public function destroy(Request $request, int $target): JsonResponse
    {
        $row = MonthlySalesTarget::query()
            ->whereKey($target)
            ->firstOrFail();

        $row->delete();

        return response()->json(['message' => 'Target removed.']);
    }
}
