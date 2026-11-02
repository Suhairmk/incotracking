<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreShopRequest;
use App\Http\Resources\ShopResource;
use App\Models\Shop;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeShopController extends Controller
{
    /**
     * The shared directory: every signed-in user sees every shop, filtered by
     * one free-text term (name or area), type, ownership and/or distance from
     * a supplied position.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'in:shop,dealer'],
            'mine' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filteredQuery($validated, $request->user()->id);

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();

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

    public function store(StoreShopRequest $request): JsonResponse
    {
        $data = $request->validated();

        $shop = Shop::create([
            'created_by' => $request->user()->id,
            'name' => trim($data['name']),
            'type' => $data['type'],
            'location_name' => trim($data['location_name']),
            'address' => $this->nullIfEmpty($data['address'] ?? null),
            'phone' => $this->nullIfEmpty($data['phone'] ?? null),
            'notes' => $this->nullIfEmpty($data['notes'] ?? null),
            'latitude_e7' => Geo::toE7((float) $data['latitude']),
            'longitude_e7' => Geo::toE7((float) $data['longitude']),
            'accuracy_meters' => isset($data['accuracy_meters']) ? (int) $data['accuracy_meters'] : null,
        ]);

        return response()->json([
            'data' => new ShopResource($shop->load('creator')),
            'message' => 'Shop added to the directory.',
        ], 201);
    }

    public function show(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('view', $shop);

        return response()->json([
            'data' => new ShopResource($shop->load('creator')),
        ]);
    }

    public function update(StoreShopRequest $request, Shop $shop): JsonResponse
    {
        $this->authorize('update', $shop);

        $data = $request->validated();

        $shop->update([
            'name' => trim($data['name']),
            'type' => $data['type'],
            'location_name' => trim($data['location_name']),
            'address' => $this->nullIfEmpty($data['address'] ?? null),
            'phone' => $this->nullIfEmpty($data['phone'] ?? null),
            'notes' => $this->nullIfEmpty($data['notes'] ?? null),
            'latitude_e7' => Geo::toE7((float) $data['latitude']),
            'longitude_e7' => Geo::toE7((float) $data['longitude']),
            'accuracy_meters' => isset($data['accuracy_meters']) ? (int) $data['accuracy_meters'] : null,
        ]);

        return response()->json([
            'data' => new ShopResource($shop->fresh('creator')),
            'message' => 'Shop updated.',
        ]);
    }

    public function destroy(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('delete', $shop);
        $shop->delete();

        return response()->json(['message' => 'Shop removed from the directory.']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function filteredQuery(array $validated, ?int $viewerId = null): Builder
    {
        $query = Shop::query()->with('creator');

        // A single search term matches the shop name or the area it sits in,
        // so one search box covers both lookups. Grouped to keep the OR from
        // bleeding into the type, ownership and distance clauses below.
        if (filled($validated['q'] ?? null)) {
            $term = '%'.trim($validated['q']).'%';

            $query->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('location_name', 'like', $term);
            });
        }

        $query->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type));

        if ($viewerId !== null && ($validated['mine'] ?? false)) {
            $query->where('created_by', $viewerId);
        }

        if (isset($validated['latitude'], $validated['longitude'])) {
            $latitude = (float) $validated['latitude'];
            $longitude = (float) $validated['longitude'];
            $radiusMeters = (float) ($validated['radius_km'] ?? 10) * 1000;
            $origin = [$latitude, $latitude, $longitude];

            $query->select('shops.*')
                ->selectRaw($this->distanceSql().' as distance_meters', $origin)
                ->whereRaw($this->distanceSql().' <= ?', [...$origin, $radiusMeters])
                ->orderByRaw($this->distanceSql().' asc', $origin);
        } else {
            $query->orderBy('name');
        }

        return $query;
    }

    /**
     * Great-circle distance in metres from a fixed point to each row, kept in
     * SQL so ordering and the radius cut-off both happen in the database.
     * Bindings, in order: origin latitude, origin latitude, origin longitude.
     */
    private function distanceSql(): string
    {
        return '6371000 * 2 * ASIN(SQRT(
                POWER(SIN((latitude_e7 / 10000000 - ?) * PI() / 180 / 2), 2) +
                COS(? * PI() / 180) * COS(latitude_e7 / 10000000 * PI() / 180) *
                POWER(SIN((longitude_e7 / 10000000 - ?) * PI() / 180 / 2), 2)
            ))';
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $trimmed = $value === null ? null : trim($value);

        return $trimmed === null || $trimmed === '' ? null : $trimmed;
    }
}
