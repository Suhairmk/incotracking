<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The administrator's catalogue: the field app sells only what is listed
 * here, so the product list is admin-managed and never deleted outright —
 * orders keep referencing rows after an item leaves the catalogue.
 */
class AdminProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()->orderBy('name');

        if (filled($validated['q'] ?? null)) {
            $query->where('name', 'like', '%'.trim($validated['q']).'%');
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 50))->withQueryString();

        return response()->json([
            'data' => ProductResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $attributes = $this->attributes($request->validated());

        // Reflect the column default (active) in the response: a model built
        // from the request alone has no value for it until it is re-read.
        $attributes['is_active'] ??= true;

        $product = Product::create($attributes);

        return response()->json([
            'data' => new ProductResource($product),
            'message' => 'Product added to the catalogue.',
        ], 201);
    }

    public function update(StoreProductRequest $request, Product $product): JsonResponse
    {
        $product->update($this->attributes($request->validated()));

        return response()->json([
            'data' => new ProductResource($product),
            'message' => 'Product updated.',
        ]);
    }

    /**
     * Retires an item from the catalogue without touching the orders that
     * already sold it.
     */
    public function destroy(Product $product): JsonResponse
    {
        $product->update(['is_active' => false]);

        return response()->json([
            'data' => new ProductResource($product),
            'message' => 'Product removed from the catalogue.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $attributes = [
            'name' => trim($validated['name']),
            'unit' => trim($validated['unit']),
            'price_paise' => (int) $validated['price_paise'],
        ];

        // Absent on update means "leave the flag alone"; on create the
        // column default (active) applies.
        if (array_key_exists('is_active', $validated)) {
            $attributes['is_active'] = (bool) $validated['is_active'];
        }

        return $attributes;
    }
}
