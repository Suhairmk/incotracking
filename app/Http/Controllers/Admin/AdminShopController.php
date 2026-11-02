<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Employee\EmployeeShopController;
use App\Http\Resources\ShopResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The administrator view of the shared shop directory. Filtering lives in the
 * employee controller so both roles answer "nearby" and "search" identically;
 * only the attribution filter is added here.
 */
class AdminShopController extends EmployeeShopController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'in:shop,dealer'],
            'mine' => ['nullable', 'boolean'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filteredQuery($validated, $request->user()->id);

        $query->when($validated['created_by'] ?? null, fn ($q, $id) => $q->where('created_by', $id));

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 25))->withQueryString();

        return response()->json([
            'data' => ShopResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'latitude' => isset($validated['latitude']) ? (float) $validated['latitude'] : null,
                'longitude' => isset($validated['longitude']) ? (float) $validated['longitude'] : null,
                'radius_km' => isset($validated['radius_km']) ? (float) $validated['radius_km'] : null,
            ],
        ]);
    }
}
