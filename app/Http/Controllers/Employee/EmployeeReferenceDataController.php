<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ShopResource;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;

/**
 * One snapshot call that lets the field app work with no network: the whole
 * shop directory (so an order can be placed for any entry, not just ones
 * browsed earlier) and the active product catalogue. Refreshed by the sync
 * service on login, on app resume and whenever connectivity returns.
 */
class EmployeeReferenceDataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'server_time' => now('UTC')->toIso8601String(),
                'shops' => ShopResource::collection(
                    Shop::query()->with('creator:id,name,employee_code')->orderBy('name')->get()
                ),
                'products' => ProductResource::collection(
                    Product::query()->active()->orderBy('name')->get()
                ),
            ],
        ]);
    }
}
