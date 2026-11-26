<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Employee\EmployeeShopController;
use App\Http\Resources\ShopResource;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ValidWhatsAppNumber;
use App\Services\ActivityLogger;
use App\Support\WhatsAppNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The administrator view of the shared shop directory. Filtering lives in the
 * employee controller so both roles answer "nearby" and "search" identically;
 * only the attribution filter, the access list and the field-visibility
 * switch are added here.
 */
class AdminShopController extends EmployeeShopController
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'in:shop,dealer'],
            'mine' => ['nullable', 'boolean'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
            'visible' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filteredQuery($validated, $request->user());

        // The access badge on each row reads from the staff list, eager
        // loaded once for the page instead of once per shop.
        $query->with('staff');

        $query->when($validated['created_by'] ?? null, fn ($q, $id) => $q->where('created_by', $id));

        if (array_key_exists('visible', $validated)) {
            $query->where('is_visible_to_field', (bool) $validated['visible']);
        }

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

    /**
     * The administrator's correction for one shop: the WhatsApp number.
     *
     * Executives edit their shops in full through the field form; this
     * endpoint exists so an administrator can fill in the number a shop
     * recorded before the field existed, or one that arrived through the
     * bulk Excel import, without gaining the power to rewrite the rest of
     * the entry.
     */
    public function update(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('update', $shop);

        $validated = $request->validate([
            'whatsapp_number' => ['required', 'string', 'max:20', new ValidWhatsAppNumber],
        ]);

        $shop->update([
            'whatsapp_number' => WhatsAppNumber::normalize($validated['whatsapp_number']),
        ]);

        $this->logger->log('admin.shop_updated', $request->user(), $shop, [
            'whatsapp_number' => $shop->whatsapp_number,
        ]);

        return response()->json([
            'data' => new ShopResource($shop->fresh()),
            'message' => 'WhatsApp number saved.',
        ]);
    }

    /**
     * Who may open this shop, and whether it shows in the field directory at
     * all — the payload behind the administrator's access screen.
     */
    public function showAccess(Shop $shop): JsonResponse
    {
        return response()->json([
            'data' => $this->accessPayload($shop),
        ]);
    }

    /**
     * Grant or withdraw the shop for selected executives, and hide or show it
     * in the field directory. Both are replaced in one call so the access
     * screen can send its whole state rather than a diff.
     */
    public function updateAccess(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'is_visible_to_field' => ['sometimes', 'boolean'],
            'employee_ids' => ['sometimes', 'array'],
            // Only real field staff can be granted a shop: administrators
            // already see everything and would never appear in this list.
            'employee_ids.*' => [Rule::exists('users', 'id')->where('role', 'employee')],
        ]);

        $changed = [];

        if (array_key_exists('is_visible_to_field', $validated)) {
            $visible = (bool) $validated['is_visible_to_field'];
            if ($shop->is_visible_to_field !== $visible) {
                $shop->update(['is_visible_to_field' => $visible]);
                $changed['is_visible_to_field'] = $visible;
            }
        }

        if (array_key_exists('employee_ids', $validated)) {
            $ids = collect($validated['employee_ids'])->map(fn ($id) => (int) $id)->unique()->values();
            $shop->staff()->sync($ids->all());
            $changed['employee_ids'] = $ids->all();
        }

        if ($changed !== []) {
            $this->logger->log('admin.shop_access_updated', $request->user(), $shop, $changed);
        }

        return response()->json([
            'data' => $this->accessPayload($shop->fresh()),
            'message' => 'Shop access updated.',
        ]);
    }

    /**
     * The access screen payload: visibility plus every granted executive.
     *
     * @return array<string, mixed>
     */
    private function accessPayload(Shop $shop): array
    {
        return [
            'shop_id' => $shop->id,
            'shop_name' => $shop->name,
            'is_visible_to_field' => (bool) $shop->is_visible_to_field,
            'staff' => $shop->staff()
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.employee_code', 'users.is_active'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'employee_code' => $user->employee_code,
                    'is_active' => (bool) $user->is_active,
                ])
                ->values(),
        ];
    }
}
