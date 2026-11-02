<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VisitOutcome;
use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function daily(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'export' => ['nullable', 'in:csv'],
        ]);

        $date = BusinessTime::normalizeDate($validated['date'] ?? null);
        $employeeId = $validated['employee_id'] ?? null;
        $query = $this->reports->dailyQuery($date, $employeeId);

        if (($validated['export'] ?? null) === 'csv') {
            return $this->csv(
                "daily-activity-{$date}.csv",
                ['Date', 'Employee Code', 'Employee', 'Start', 'End', 'Working Hours', 'Distance (km)', 'Visits', 'Accepted Points', 'Status'],
                $query->cursor(),
                fn (object $row) => [
                    $row->work_date,
                    $row->employee_code,
                    $row->employee_name,
                    $row->start_time,
                    $row->end_time,
                    round((int) $row->total_working_seconds / 3600, 2),
                    round((int) $row->total_distance_meters / 1000, 3),
                    (int) $row->visits_count,
                    (int) $row->accepted_points_count,
                    $row->status,
                ]
            );
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($row) => $this->reports->formatDailyRow($row))->values(),
            'meta' => $this->meta($paginator->currentPage(), $paginator->lastPage(), $paginator->perPage(), $paginator->total(), [
                'date' => $date,
            ]),
        ]);
    }

    public function attendance(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'in:active,completed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'export' => ['nullable', 'in:csv'],
        ]);

        [$from, $to] = $this->safeRange($validated);

        $query = $this->reports->attendanceQuery(
            $from,
            $to,
            $validated['employee_id'] ?? null,
            $validated['status'] ?? null,
        );

        if (($validated['export'] ?? null) === 'csv') {
            return $this->csv(
                "attendance-report-{$from}-to-{$to}.csv",
                ['Date', 'Employee Code', 'Employee', 'Start', 'End', 'Working Hours', 'Distance (km)', 'Visits', 'Accepted Points', 'Rejected Points', 'Status'],
                $query->cursor(),
                fn (object $row) => [
                    $row->work_date,
                    $row->employee_code,
                    $row->employee_name,
                    $row->start_time,
                    $row->end_time,
                    round((int) $row->total_working_seconds / 3600, 2),
                    round((int) $row->total_distance_meters / 1000, 3),
                    (int) $row->visits_count,
                    (int) $row->accepted_points_count,
                    (int) $row->rejected_points_count,
                    $row->status,
                ]
            );
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($row) => $this->reports->formatAttendanceRow($row))
                ->values(),
            'meta' => $this->meta($paginator->currentPage(), $paginator->lastPage(), $paginator->perPage(), $paginator->total(), [
                'from' => $from,
                'to' => $to,
            ]),
        ]);
    }

    public function distance(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'group_by' => ['nullable', 'in:day,employee'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'export' => ['nullable', 'in:csv'],
        ]);

        [$from, $to] = $this->safeRange($validated);
        $groupBy = $validated['group_by'] ?? 'day';
        $employeeId = $validated['employee_id'] ?? null;
        $query = $this->reports->distanceQuery($from, $to, $employeeId, $groupBy);
        $totals = $this->reports->distanceTotals($from, $to, $employeeId);

        if (($validated['export'] ?? null) === 'csv') {
            return $this->csv(
                "distance-report-{$from}-to-{$to}-by-{$groupBy}.csv",
                ['Date', 'Employee Code', 'Employee', 'Distance (km)', 'Accepted Points', 'Visits', 'Days', 'Sessions'],
                $query->cursor(),
                fn (object $row) => [
                    $row->work_date ?? 'All dates',
                    $row->employee_code,
                    $row->employee_name,
                    round((int) $row->distance_meters / 1000, 3),
                    (int) $row->accepted_points,
                    (int) $row->visits,
                    (int) $row->days_worked,
                    (int) $row->sessions,
                ]
            );
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($row) => $this->reports->formatDistanceRow($row))
                ->values(),
            'meta' => $this->meta($paginator->currentPage(), $paginator->lastPage(), $paginator->perPage(), $paginator->total(), [
                'from' => $from,
                'to' => $to,
                'group_by' => $groupBy,
                'totals' => $totals,
            ]),
        ]);
    }

    public function visits(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'outcome' => ['nullable', Rule::enum(VisitOutcome::class)],
            'customer' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'export' => ['nullable', 'in:csv'],
        ]);

        [$from, $to] = $this->safeRange($validated);

        $query = $this->reports->visitsQuery(
            $from,
            $to,
            $validated['employee_id'] ?? null,
            $validated['outcome'] ?? null,
            $validated['customer'] ?? null,
        );

        if (($validated['export'] ?? null) === 'csv') {
            return $this->csv(
                "visit-report-{$from}-to-{$to}.csv",
                ['Visit Time', 'Employee Code', 'Employee', 'Customer', 'Contact Person', 'Contact Number', 'Outcome', 'Latitude', 'Longitude', 'Accuracy (m)', 'Remarks'],
                $query->cursor(),
                fn (object $row) => [
                    $row->server_recorded_at,
                    $row->employee_code,
                    $row->employee_name,
                    $row->customer_name,
                    $row->contact_person,
                    $row->contact_number,
                    $row->outcome,
                    round((int) $row->latitude_e7 / 10_000_000, 7),
                    round((int) $row->longitude_e7 / 10_000_000, 7),
                    (int) $row->accuracy_meters,
                    $row->remarks,
                ]
            );
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($row) => $this->reports->formatVisitRow($row))
                ->values(),
            'meta' => $this->meta($paginator->currentPage(), $paginator->lastPage(), $paginator->perPage(), $paginator->total(), [
                'from' => $from,
                'to' => $to,
            ]),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function safeRange(array $validated): array
    {
        $today = BusinessTime::today();
        $from = $validated['from'] ?? CarbonImmutable::parse($today)->subDays(29)->toDateString();
        $to = $validated['to'] ?? $today;

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 92) {
            throw ValidationException::withMessages([
                'to' => 'Report ranges are limited to 92 days. Narrow the date range.',
            ]);
        }

        return [$from, $to];
    }

    private function meta(int $page, int $lastPage, int $perPage, int $total, array $extra = []): array
    {
        return array_merge([
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ], $extra);
    }

    private function csv(string $filename, array $headers, iterable $rows, callable $mapper): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $mapper) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($value) => $this->csvCell($value), $mapper($row)));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function csvCell(mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;

        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = "'".$value;
        }

        return $value;
    }
}
