<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\CreateVisitRequest;
use App\Http\Resources\VisitResource;
use App\Models\Attendance;
use App\Models\Visit;
use App\Services\VisitService;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmployeeVisitController extends Controller
{
    public function __construct(private readonly VisitService $visitService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outcome' => ['nullable', 'string', 'max:24'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Visit::query()
            ->where('employee_id', $request->user()->id)
            ->orderByDesc('server_recorded_at')
            ->orderByDesc('id');

        if (isset($validated['from'])) {
            [$start] = BusinessTime::utcBounds($validated['from']);
            $query->where('server_recorded_at', '>=', $start);
        }

        if (isset($validated['to'])) {
            [, $end] = BusinessTime::utcBounds($validated['to']);
            $query->where('server_recorded_at', '<', $end);
        }

        if (! isset($validated['from']) && ! isset($validated['to'])) {
            $date = $validated['date'] ?? BusinessTime::today();
            [$start, $end] = BusinessTime::utcBounds($date);
            $query->where('server_recorded_at', '>=', $start)
                ->where('server_recorded_at', '<', $end);
        }

        if (isset($validated['outcome'])) {
            $query->where('outcome', $validated['outcome']);
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();

        return response()->json([
            'data' => VisitResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(CreateVisitRequest $request): JsonResponse
    {
        $data = $request->validated();
        $attendance = Attendance::query()
            ->where('employee_id', $request->user()->id)
            ->where('start_client_id', Str::upper($data['start_client_id']))
            ->first();

        if (! $attendance) {
            return response()->json([
                'message' => 'Attendance session not found for this visit.',
            ], 404);
        }

        $this->authorize('view', $attendance);

        $result = $this->visitService->create(
            $request->user(),
            $attendance,
            $data,
            $request->file('photo')
        );

        return response()->json([
            'data' => new VisitResource($result['visit']),
            'meta' => ['created' => $result['created']],
            'message' => $result['created'] ? 'Visit saved.' : 'Visit already recorded.',
        ], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        return response()->json([
            'data' => new VisitResource($visit->load(['employee', 'attendance'])),
        ]);
    }
}
