<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Employee\EmployeeOrderController;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The administrator's view of the orders queue: same filtering and status
 * machinery as the field app (it lives in the employee controller), only the
 * ownership scope is dropped so every executive's orders appear, plus the
 * attribution filter.
 */
class AdminOrderController extends EmployeeOrderController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,delivered,cancelled'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Null viewer: every order is in scope.
        $query = $this->filteredQuery($validated, null)
            ->when($validated['employee_id'] ?? null, fn ($q, int $id) => $q->where('employee_id', $id));

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();

        return response()->json([
            'data' => OrderResource::collection($paginator->getCollection()),
            'meta' => $this->meta($validated, $paginator),
        ]);
    }
}
