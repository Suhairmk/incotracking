<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\MonthlySalesTarget;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function dailyQuery(string $date, ?int $employeeId = null): Builder
    {
        return DB::table('attendance as a')
            ->join('users as u', 'u.id', '=', 'a.employee_id')
            ->whereDate('a.work_date', $date)
            ->when($employeeId, fn ($q, $id) => $q->where('a.employee_id', $id))
            ->select([
                'a.id',
                'a.employee_id',
                'u.employee_code',
                'u.name as employee_name',
                'u.profile_photo_path',
                'a.work_date',
                'a.start_time',
                'a.end_time',
                'a.start_latitude_e7',
                'a.start_longitude_e7',
                'a.end_latitude_e7',
                'a.end_longitude_e7',
                'a.total_distance_meters',
                'a.total_working_seconds',
                'a.accepted_points_count',
                'a.visits_count',
                'a.start_photo_path',
                'a.start_meter_photo_path',
                'a.end_photo_path',
                'a.end_meter_photo_path',
                'a.status',
            ])
            ->orderBy('u.name')
            ->orderBy('a.start_time');
    }

    public function attendanceQuery(
        string $from,
        string $to,
        ?int $employeeId = null,
        ?string $status = null,
    ): Builder {
        return DB::table('attendance as a')
            ->join('users as u', 'u.id', '=', 'a.employee_id')
            ->whereDate('a.work_date', '>=', $from)
            ->whereDate('a.work_date', '<=', $to)
            ->when($employeeId, fn ($q, $id) => $q->where('a.employee_id', $id))
            ->when($status, fn ($q, $value) => $q->where('a.status', $value))
            ->select([
                'a.id',
                'a.employee_id',
                'u.employee_code',
                'u.name as employee_name',
                'a.work_date',
                'a.start_time',
                'a.end_time',
                'a.start_latitude_e7',
                'a.start_longitude_e7',
                'a.end_latitude_e7',
                'a.end_longitude_e7',
                'a.total_distance_meters',
                'a.total_working_seconds',
                'a.accepted_points_count',
                'a.rejected_points_count',
                'a.visits_count',
                'a.start_photo_path',
                'a.start_meter_photo_path',
                'a.end_photo_path',
                'a.end_meter_photo_path',
                'a.status',
            ])
            ->orderByDesc('a.work_date')
            ->orderByDesc('a.start_time');
    }

    public function distanceQuery(
        string $from,
        string $to,
        ?int $employeeId = null,
        string $groupBy = 'day',
    ): Builder {
        // Approved odometer claims ride along on the same session so the
        // report can show measured GPS kilometres beside the vehicle's own
        // reading. One claim per attendance keeps the join row-preserving.
        $query = DB::table('attendance as a')
            ->join('users as u', 'u.id', '=', 'a.employee_id')
            ->leftJoin('odometer_claims as oc', function ($join): void {
                $join->on('oc.attendance_id', '=', 'a.id')
                    ->where('oc.status', '=', 'approved');
            })
            ->whereDate('a.work_date', '>=', $from)
            ->whereDate('a.work_date', '<=', $to)
            ->when($employeeId, fn ($q, $id) => $q->where('a.employee_id', $id));

        if ($groupBy === 'employee') {
            return $query
                ->groupBy('a.employee_id', 'u.employee_code', 'u.name')
                ->selectRaw(
                    'a.employee_id, u.employee_code, u.name AS employee_name,
                     NULL AS work_date,
                     SUM(a.total_distance_meters) AS distance_meters,
                     SUM(oc.claimed_distance_meters) AS odometer_meters,
                     SUM(a.accepted_points_count) AS accepted_points,
                     SUM(a.visits_count) AS visits,
                     COUNT(DISTINCT a.work_date) AS days_worked,
                     COUNT(*) AS sessions'
                )
                ->orderByDesc('distance_meters');
        }

        return $query
            ->groupBy('a.employee_id', 'u.employee_code', 'u.name', 'a.work_date')
            ->selectRaw(
                'a.employee_id, u.employee_code, u.name AS employee_name,
                 a.work_date,
                 SUM(a.total_distance_meters) AS distance_meters,
                 SUM(oc.claimed_distance_meters) AS odometer_meters,
                 SUM(a.accepted_points_count) AS accepted_points,
                 SUM(a.visits_count) AS visits,
                 1 AS days_worked,
                 COUNT(*) AS sessions'
            )
            ->orderByDesc('a.work_date')
            ->orderByDesc('distance_meters');
    }

    public function distanceTotals(string $from, string $to, ?int $employeeId = null): array
    {
        $row = DB::table('attendance')
            ->leftJoin('odometer_claims as oc', function ($join): void {
                $join->on('oc.attendance_id', '=', 'attendance.id')
                    ->where('oc.status', '=', 'approved');
            })
            ->whereDate('attendance.work_date', '>=', $from)
            ->whereDate('attendance.work_date', '<=', $to)
            ->when($employeeId, fn ($q, $id) => $q->where('attendance.employee_id', $id))
            ->selectRaw(
                'COALESCE(SUM(attendance.total_distance_meters), 0) AS distance_meters,
                 COALESCE(SUM(oc.claimed_distance_meters), 0) AS odometer_meters,
                 COALESCE(SUM(attendance.visits_count), 0) AS visits,
                 COALESCE(SUM(attendance.accepted_points_count), 0) AS accepted_points,
                 COUNT(DISTINCT attendance.employee_id) AS employees,
                 COUNT(DISTINCT attendance.work_date) AS days'
            )
            ->first();

        return [
            'distance_meters' => (int) ($row->distance_meters ?? 0),
            'distance_km' => round((int) ($row->distance_meters ?? 0) / 1000, 3),
            'odometer_meters' => (int) ($row->odometer_meters ?? 0),
            'odometer_km' => round((int) ($row->odometer_meters ?? 0) / 1000, 3),
            'visits' => (int) ($row->visits ?? 0),
            'accepted_points' => (int) ($row->accepted_points ?? 0),
            'employees' => (int) ($row->employees ?? 0),
            'days' => (int) ($row->days ?? 0),
        ];
    }

    public function visitsQuery(
        string $from,
        string $to,
        ?int $employeeId = null,
        ?string $outcome = null,
        ?string $customer = null,
    ): Builder {
        [$start] = BusinessTime::utcBounds($from);
        [, $end] = BusinessTime::utcBounds($to);

        return DB::table('visits as v')
            ->join('users as u', 'u.id', '=', 'v.employee_id')
            ->where('v.server_recorded_at', '>=', $start)
            ->where('v.server_recorded_at', '<', $end)
            ->when($employeeId, fn ($q, $id) => $q->where('v.employee_id', $id))
            ->when($outcome, fn ($q, $value) => $q->where('v.outcome', $value))
            ->when($customer, function ($q, $value) {
                $q->where('v.customer_name', 'like', '%'.trim($value).'%');
            })
            ->select([
                'v.id',
                'v.employee_id',
                'u.employee_code',
                'u.name as employee_name',
                'v.customer_name',
                'v.contact_person',
                'v.contact_number',
                'v.outcome',
                'v.remarks',
                'v.latitude_e7',
                'v.longitude_e7',
                'v.accuracy_meters',
                'v.device_recorded_at',
                'v.server_recorded_at',
            ])
            ->orderByDesc('v.server_recorded_at')
            ->orderByDesc('v.id');
    }

    public function formatAttendanceRow(object $row): array
    {
        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'employee_code' => $row->employee_code,
            'employee_name' => $row->employee_name,
            'work_date' => (string) $row->work_date,
            'start_time' => $this->iso($row->start_time),
            'end_time' => $this->iso($row->end_time),
            'total_distance_meters' => (int) $row->total_distance_meters,
            'total_distance_km' => round((int) $row->total_distance_meters / 1000, 3),
            'total_working_seconds' => (int) $row->total_working_seconds,
            'total_working_hours' => round((int) $row->total_working_seconds / 3600, 2),
            'accepted_points_count' => (int) ($row->accepted_points_count ?? 0),
            'rejected_points_count' => (int) ($row->rejected_points_count ?? 0),
            'visits_count' => (int) ($row->visits_count ?? 0),
            'status' => $row->status,
            'start_photo_url' => $row->start_photo_path
                ? route('photos.attendance', ['attendance' => $row->id, 'type' => 'start'], false)
                : null,
            'start_meter_photo_url' => $row->start_meter_photo_path
                ? route('photos.attendance', ['attendance' => $row->id, 'type' => 'start_meter'], false)
                : null,
            'end_photo_url' => $row->end_photo_path
                ? route('photos.attendance', ['attendance' => $row->id, 'type' => 'end'], false)
                : null,
            'end_meter_photo_url' => $row->end_meter_photo_path
                ? route('photos.attendance', ['attendance' => $row->id, 'type' => 'end_meter'], false)
                : null,
        ];
    }

    public function formatDailyRow(object $row): array
    {
        return $this->formatAttendanceRow($row);
    }

    public function formatDistanceRow(object $row): array
    {
        return [
            'employee_id' => $row->employee_id,
            'employee_code' => $row->employee_code,
            'employee_name' => $row->employee_name,
            'work_date' => $row->work_date ? (string) $row->work_date : null,
            'distance_meters' => (int) $row->distance_meters,
            'distance_km' => round((int) $row->distance_meters / 1000, 3),
            // Approved odometer reading for the same days; null-safe because
            // a period can hold GPS distance with no claims at all.
            'odometer_meters' => (int) ($row->odometer_meters ?? 0),
            'odometer_km' => round((int) ($row->odometer_meters ?? 0) / 1000, 3),
            'accepted_points' => (int) $row->accepted_points,
            'visits' => (int) $row->visits,
            'days_worked' => (int) $row->days_worked,
            'sessions' => (int) $row->sessions,
        ];
    }

    public function formatVisitRow(object $row): array
    {
        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'employee_code' => $row->employee_code,
            'employee_name' => $row->employee_name,
            'customer_name' => $row->customer_name,
            'contact_person' => $row->contact_person,
            'contact_number' => $row->contact_number,
            'outcome' => $row->outcome,
            'remarks' => $row->remarks,
            'latitude' => (int) $row->latitude_e7 / 10_000_000,
            'longitude' => (int) $row->longitude_e7 / 10_000_000,
            'accuracy_meters' => (int) $row->accuracy_meters,
            'device_recorded_at' => $this->iso($row->device_recorded_at),
            'server_recorded_at' => $this->iso($row->server_recorded_at),
            'photo_url' => route('photos.visit', ['visit' => $row->id, 'variant' => 'full'], false),
            'photo_thumb_url' => route('photos.visit', ['visit' => $row->id, 'variant' => 'thumb'], false),
        ];
    }

    /**
     * Order report rows: one row per order with its shop, field staff and
     * money split, filtered by business date (the placement day in the
     * company timezone).
     */
    public function ordersQuery(
        string $from,
        string $to,
        ?int $employeeId = null,
        ?string $status = null,
    ): Builder {
        return DB::table('orders as o')
            ->join('shops as s', 's.id', '=', 'o.shop_id')
            ->join('users as u', 'u.id', '=', 'o.employee_id')
            ->whereDate('o.business_date', '>=', $from)
            ->whereDate('o.business_date', '<=', $to)
            ->when($employeeId, fn ($q, $id) => $q->where('o.employee_id', $id))
            ->when($status, fn ($q, $value) => $q->where('o.status', $value))
            ->select([
                'o.id',
                'o.order_number',
                'o.status',
                'o.business_date',
                'o.subtotal_paise',
                'o.tax_paise',
                'o.tax_rate_bp',
                'o.total_paise',
                'o.delivered_at',
                's.id as shop_id',
                's.name as shop_name',
                's.location_name as shop_location',
                'u.id as employee_id',
                'u.employee_code',
                'u.name as employee_name',
            ])
            ->orderByDesc('o.business_date')
            ->orderByDesc('o.id');
    }

    /**
     * Order volume per employee for a date range, grouped by day or by
     * month. Delivered orders are counted separately so a report can show
     * what was actually sold.
     */
    public function orderCountsQuery(
        string $from,
        string $to,
        ?int $employeeId = null,
        string $groupBy = 'day',
    ): Builder {
        $query = DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.employee_id')
            ->whereDate('o.business_date', '>=', $from)
            ->whereDate('o.business_date', '<=', $to)
            ->when($employeeId, fn ($q, $id) => $q->where('o.employee_id', $id));

        $period = $groupBy === 'month'
            ? "DATE_FORMAT(o.business_date, '%Y-%m')"
            : 'o.business_date';

        return $query
            ->groupByRaw("{$period}, o.employee_id, u.employee_code, u.name")
            ->selectRaw(
                "{$period} AS period,
                 o.employee_id,
                 u.employee_code,
                 u.name AS employee_name,
                 COUNT(*) AS orders,
                 SUM(o.status = 'delivered') AS delivered,
                 SUM(o.status = 'pending') AS pending,
                 SUM(o.status = 'cancelled') AS cancelled,
                 COALESCE(SUM(o.subtotal_paise), 0) AS sales_paise,
                 COALESCE(SUM(o.tax_paise), 0) AS tax_paise"
            )
            ->orderBy('period')
            ->orderBy('employee_name');
    }

    /**
     * Monthly target versus achieved sales for every field executive:
     * delivered orders only, at their taxable value (ex-GST), so the
     * achievement percentage compares like with like.
     *
     * @return array<int, array<string, mixed>>
     */
    public function performanceForMonth(string $month, ?int $employeeId = null): array
    {
        $month = MonthlySalesTarget::normalizeMonth($month);
        $start = CarbonImmutable::parse($month)->startOfMonth()->toDateString();
        $end = CarbonImmutable::parse($month)->endOfMonth()->toDateString();

        $achieved = DB::table('orders')
            ->where('status', 'delivered')
            ->whereBetween('business_date', [$start, $end])
            ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id))
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COALESCE(SUM(subtotal_paise), 0) AS achieved_paise, COUNT(*) AS delivered_orders')
            ->get()
            ->keyBy('employee_id');

        $counts = DB::table('orders')
            ->whereBetween('business_date', [$start, $end])
            ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id))
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) AS total_orders')
            ->get()
            ->keyBy('employee_id');

        $targets = MonthlySalesTarget::query()
            ->where('month', $month)
            ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id))
            ->get()
            ->keyBy('employee_id');

        return User::query()
            ->where('role', UserRole::Employee)
            ->where('is_active', true)
            ->when($employeeId, fn ($q, $id) => $q->where('id', $employeeId))
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($targets, $achieved, $counts, $month): array {
                $target = (int) ($targets->get($user->id)?->target_paise ?? 0);
                $achievedPaise = (int) ($achieved->get($user->id)->achieved_paise ?? 0);
                $remaining = max(0, $target - $achievedPaise);

                return [
                    'employee_id' => $user->id,
                    'employee_code' => $user->employee_code,
                    'employee_name' => $user->name,
                    'month' => $month,
                    'target_id' => $targets->get($user->id)?->id,
                    'target_paise' => $target,
                    'achieved_paise' => $achievedPaise,
                    'remaining_paise' => $remaining,
                    // A target of zero reports zero rather than infinity:
                    // the UI shows "no target set" for that row.
                    'achievement_pct' => $target > 0
                        ? round($achievedPaise * 100 / $target, 1)
                        : 0.0,
                    'delivered_orders' => (int) ($achieved->get($user->id)->delivered_orders ?? 0),
                    'total_orders' => (int) ($counts->get($user->id)->total_orders ?? 0),
                    'target_set' => $targets->has($user->id),
                ];
            })
            ->all();
    }

    private function iso(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
    }
}
