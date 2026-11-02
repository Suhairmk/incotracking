<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetEmployeePasswordRequest;
use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Http\Requests\Admin\UploadPhotoRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\UserResource;
use App\Models\Attendance;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ImageStorageService;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminEmployeeController extends Controller
{
    public function __construct(
        private readonly ImageStorageService $images,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->where('role', UserRole::Employee->value)
            ->when($validated['search'] ?? null, function ($q, $search) {
                $like = '%'.trim($search).'%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('employee_code', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            })
            ->when(($validated['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($validated['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name');

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();

        // One aggregate query for the whole page, never one query per employee.
        $today = BusinessTime::today();
        $attendances = Attendance::query()
            ->whereDate('work_date', $today)
            ->whereIn('employee_id', $paginator->getCollection()->pluck('id'))
            ->get()
            ->keyBy('employee_id');

        $staleAfter = (int) config('tracking.stale_after_minutes', 10);

        foreach ($paginator->getCollection() as $employee) {
            $attendance = $attendances->get($employee->id);
            $employee->setAttribute('today_attendance_id', $attendance?->id);
            $employee->setAttribute('today_status', $this->todayStatus($employee, $attendance, $staleAfter));
            $employee->setAttribute('today_distance_meters', (int) ($attendance?->total_distance_meters ?? 0));
        }

        return response()->json([
            'data' => UserResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $photo = null;

        if ($request->hasFile('photo')) {
            $photo = $this->images->store(
                $request->file('photo'),
                'profiles/'.Str::ulid(),
                'avatar'
            );
        }

        try {
            $employee = User::create([
                'employee_code' => strtoupper(trim($data['employee_code'])),
                'name' => trim($data['name']),
                'email' => filled($data['email'] ?? null) ? strtolower(trim($data['email'])) : null,
                'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
                'password' => $data['password'],
                'role' => UserRole::Employee,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'profile_photo_path' => $photo['path'] ?? null,
                'profile_photo_thumb_path' => $photo['thumb_path'] ?? null,
            ]);
        } catch (\Throwable $e) {
            if ($photo) {
                $this->images->delete($photo['path']);
                $this->images->delete($photo['thumb_path']);
            }

            throw $e;
        }

        $this->logger->log('admin.employee_created', $request->user(), $employee);

        return response()->json([
            'data' => new UserResource($employee),
            'message' => 'Employee created.',
        ], 201);
    }

    public function show(User $employee): JsonResponse
    {
        $this->ensureEmployee($employee);

        // loadCount() names the visit total "visits_count"; the API contract is
        // "visit_count", so normalise it here.
        $employee->loadCount(['attendance', 'visits']);
        $employee->setAttribute('visit_count', (int) $employee->visits_count);

        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', BusinessTime::today())
            ->first();

        // Same live-status attributes as index() so the detail screen shows a
        // real pill (not "Unknown").
        $staleAfter = (int) config('tracking.stale_after_minutes', 10);
        $employee->setAttribute('today_attendance_id', $attendance?->id);
        $employee->setAttribute('today_status', $this->todayStatus($employee, $attendance, $staleAfter));
        $employee->setAttribute('today_distance_meters', (int) ($attendance?->total_distance_meters ?? 0));

        return response()->json([
            'data' => [
                'employee' => new UserResource($employee),
                'today_attendance' => $attendance ? new AttendanceResource($attendance->load('employee')) : null,
            ],
        ]);
    }

    public function update(UpdateEmployeeRequest $request, User $employee): JsonResponse
    {
        $this->ensureEmployee($employee);
        $data = $request->validated();
        $oldPhoto = $employee->profile_photo_path;
        $oldThumb = $employee->profile_photo_thumb_path;
        $photo = null;

        if ($request->hasFile('photo')) {
            $photo = $this->images->store(
                $request->file('photo'),
                "profiles/{$employee->id}",
                (string) Str::ulid()
            );
        }

        $employee->fill([
            'employee_code' => strtoupper(trim($data['employee_code'])),
            'name' => trim($data['name']),
            'email' => filled($data['email'] ?? null) ? strtolower(trim($data['email'])) : null,
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
        ]);

        if (array_key_exists('is_active', $data)) {
            $employee->is_active = (bool) $data['is_active'];
        }

        if ($photo) {
            $employee->profile_photo_path = $photo['path'];
            $employee->profile_photo_thumb_path = $photo['thumb_path'];
        }

        $employee->save();

        if ($photo) {
            $this->images->delete($oldPhoto);
            $this->images->delete($oldThumb);
        }

        if (! $employee->is_active) {
            $employee->tokens()->delete();
        }

        $this->logger->log('admin.employee_updated', $request->user(), $employee, [
            'is_active' => $employee->is_active,
        ]);

        return response()->json([
            'data' => new UserResource($employee),
            'message' => 'Employee updated.',
        ]);
    }

    public function destroy(Request $request, User $employee): JsonResponse
    {
        $this->ensureEmployee($employee);

        $employee->forceFill(['is_active' => false])->save();
        $employee->tokens()->delete();

        $this->logger->log('admin.employee_deactivated', $request->user(), $employee);

        return response()->json(['message' => 'Employee deactivated. Attendance history is preserved.']);
    }

    public function resetPassword(ResetEmployeePasswordRequest $request, User $employee): JsonResponse
    {
        $this->ensureEmployee($employee);

        $employee->forceFill(['password' => Hash::make($request->validated('password'))])->save();
        $employee->tokens()->delete();

        $this->logger->log('admin.employee_password_reset', $request->user(), $employee);

        return response()->json(['message' => 'Password reset. The employee must sign in again.']);
    }

    public function uploadPhoto(UploadPhotoRequest $request, User $employee): JsonResponse
    {
        $this->ensureEmployee($employee);
        $oldPhoto = $employee->profile_photo_path;
        $oldThumb = $employee->profile_photo_thumb_path;

        $photo = $this->images->store(
            $request->file('photo'),
            "profiles/{$employee->id}",
            (string) Str::ulid()
        );

        $employee->forceFill([
            'profile_photo_path' => $photo['path'],
            'profile_photo_thumb_path' => $photo['thumb_path'],
        ])->save();

        $this->images->delete($oldPhoto);
        $this->images->delete($oldThumb);

        $this->logger->log('admin.employee_photo_updated', $request->user(), $employee);

        return response()->json([
            'data' => new UserResource($employee),
            'message' => 'Profile photo updated.',
        ]);
    }

    private function ensureEmployee(User $employee): void
    {
        abort_unless($employee->isEmployee(), 404);
    }

    private function todayStatus(User $employee, ?Attendance $attendance, int $staleAfter): string
    {
        if (! $employee->is_active) {
            return 'inactive';
        }

        if (! $attendance) {
            return 'not_started';
        }

        if ($attendance->status?->value === 'completed') {
            return 'completed';
        }

        if ($attendance->tracking_state?->value === 'interrupted') {
            return 'interrupted';
        }

        if (! $attendance->last_heartbeat_at) {
            return 'starting';
        }

        return $attendance->last_heartbeat_at->lt(now('UTC')->subMinutes($staleAfter))
            ? 'stale'
            : 'working';
    }
}
