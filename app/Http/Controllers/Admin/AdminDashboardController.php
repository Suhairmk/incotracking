<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Visit;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = BusinessTime::normalizeDate($validated['date'] ?? null);
        $staleAfter = (int) config('tracking.stale_after_minutes', 10);
        $staleBefore = now('UTC')->subMinutes($staleAfter);

        $activeEmployees = User::query()
            ->where('role', UserRole::Employee->value)
            ->where('is_active', true)
            ->count();
        $inactiveEmployees = User::query()
            ->where('role', UserRole::Employee->value)
            ->where('is_active', false)
            ->count();

        $summary = Attendance::query()
            ->whereDate('work_date', $date)
            ->selectRaw(
                "COUNT(*) AS total_sessions,
                 COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS working,
                 COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
                 COALESCE(SUM(total_distance_meters), 0) AS distance_meters,
                 COALESCE(SUM(visits_count), 0) AS visits"
            )
            ->first();

        $interrupted = Attendance::query()
            ->whereDate('work_date', $date)
            ->where('status', AttendanceStatus::Active->value)
            ->where(function ($query) use ($staleBefore) {
                $query->where('tracking_state', 'interrupted')
                    ->orWhere('last_heartbeat_at', '<', $staleBefore)
                    ->orWhere(function ($q) use ($staleBefore) {
                        $q->whereNull('last_heartbeat_at')->where('start_time', '<', $staleBefore);
                    });
            })
            ->count();

        [$dayStart, $dayEnd] = BusinessTime::utcBounds($date);
        $visits = Visit::query()
            ->where('server_recorded_at', '>=', $dayStart)
            ->where('server_recorded_at', '<', $dayEnd)
            ->count();

        $totalSessions = (int) ($summary->total_sessions ?? 0);

        return response()->json([
            'data' => [
                'date' => $date,
                'server_time' => now('UTC')->toIso8601String(),
                'total_employees' => $activeEmployees,
                'inactive_employees' => $inactiveEmployees,
                'working' => (int) ($summary->working ?? 0),
                'completed' => (int) ($summary->completed ?? 0),
                'not_started' => max(0, $activeEmployees - $totalSessions),
                'tracking_interrupted' => $interrupted,
                'visits_today' => $visits,
                'distance_km_today' => round((int) ($summary->distance_meters ?? 0) / 1000, 3),
                'distance_meters_today' => (int) ($summary->distance_meters ?? 0),
                'visits_from_attendance' => (int) ($summary->visits ?? 0),
            ],
        ]);
    }
}
