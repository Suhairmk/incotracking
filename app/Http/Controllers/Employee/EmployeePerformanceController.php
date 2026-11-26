<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\MonthlySalesTarget;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in field executive's own target and achievement for a month:
 * delivered orders only, at their taxable value (ex-GST).
 */
class EmployeePerformanceController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $month = MonthlySalesTarget::normalizeMonth(
            $validated['month'] ?? now()->startOfMonth()->toDateString()
        );

        $rows = $this->reports->performanceForMonth($month, $request->user()->id);

        return response()->json([
            'data' => $rows[0] ?? [
                'employee_id' => $request->user()->id,
                'month' => $month,
                'target_id' => null,
                'target_paise' => 0,
                'achieved_paise' => 0,
                'remaining_paise' => 0,
                'achievement_pct' => 0.0,
                'delivered_orders' => 0,
                'total_orders' => 0,
                'target_set' => false,
            ],
            'meta' => [
                'month' => $month,
            ],
        ]);
    }
}
