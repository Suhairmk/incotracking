<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VisitResource;
use App\Models\Visit;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVisitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outcome' => ['nullable', 'string', 'max:24'],
            'customer' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Visit::query()
            ->with('employee')
            ->orderByDesc('server_recorded_at')
            ->orderByDesc('id');

        $from = $validated['from'] ?? $validated['date'] ?? BusinessTime::today();
        $to = $validated['to'] ?? $validated['date'] ?? BusinessTime::today();
        [$start] = BusinessTime::utcBounds($from);
        [, $end] = BusinessTime::utcBounds($to);
        $query->where('server_recorded_at', '>=', $start)->where('server_recorded_at', '<', $end);

        $query
            ->when($validated['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($validated['outcome'] ?? null, fn ($q, $outcome) => $q->where('outcome', $outcome))
            ->when($validated['customer'] ?? null, function ($q, $customer) {
                $q->where('customer_name', 'like', '%'.trim($customer).'%');
            });

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 25))->withQueryString();

        return response()->json([
            'data' => VisitResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    public function show(Visit $visit): JsonResponse
    {
        return response()->json([
            'data' => new VisitResource($visit->load(['employee', 'attendance'])),
        ]);
    }
}
