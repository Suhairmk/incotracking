<?php

namespace App\Http\Controllers\Employee;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\BusinessTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders are placed from the field — online, or from the offline queue once
 * connectivity returns — and can be marked delivered by the executive who
 * created them. Administrators read every order through AdminOrderController,
 * which subclasses this one so filtering behaves identically for both roles.
 */
class EmployeeOrderController extends Controller
{
    /**
     * Relations every response carries, so lists and details render without
     * N+1 queries.
     */
    protected const RELATIONS = [
        'shop',
        'employee:id,name,employee_code',
        'items',
        'deliveredBy:id,name',
        'cancelledBy:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,delivered,cancelled'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->filteredQuery($validated, $request->user()->id)
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        return response()->json([
            'data' => OrderResource::collection($paginator->getCollection()),
            'meta' => $this->meta($validated, $paginator),
        ]);
    }

    /**
     * Places an order. The device-supplied client id makes the call safe to
     * repeat: an offline upload that timed out but actually landed is
     * recognised as the same order, not a second one, and only a genuinely
     * new order notifies the administrators.
     */
    public function store(StoreOrderRequest $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        $order = Order::query()->where('client_order_id', $data['client_order_id'])->first();
        $created = false;

        if ($order === null) {
            try {
                $order = DB::transaction(fn (): Order => $this->insertOrder($data, $actor->id));
                $created = true;
            } catch (UniqueConstraintViolationException) {
                // A concurrent retry of the same upload won the race.
                $order = Order::query()->where('client_order_id', $data['client_order_id'])->firstOrFail();
            }
        }

        if ($created) {
            $notifications->notifyAdmins(
                'New order '.$order->order_number,
                sprintf(
                    '%s placed an order for %s — ₹%s (%d item%s).',
                    $order->employee?->name ?? 'An executive',
                    $order->shop?->name ?? 'a shop',
                    number_format($order->total_paise / 100, 2, '.', ','),
                    $order->items()->count(),
                    $order->items()->count() === 1 ? '' : 's',
                ),
                [
                    'type' => 'order',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'shop_id' => $order->shop_id,
                    'total_paise' => $order->total_paise,
                ],
            );
        }

        return response()->json([
            'data' => new OrderResource($order->load(self::RELATIONS)),
            'message' => $created ? 'Order placed.' : 'Order already recorded.',
        ], $created ? 201 : 200);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json([
            'data' => new OrderResource($order->load(self::RELATIONS)),
        ]);
    }

    /**
     * `pending` is the only state that moves: it can become delivered (by an
     * administrator or the executive who placed it) or cancelled while still
     * outstanding. Repeating a status that already applies is a no-op so a
     * retried tap never errors.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        $validated = $request->validate([
            'status' => ['required', 'in:delivered,cancelled'],
        ]);

        $target = OrderStatus::from($validated['status']);
        $order = $this->changeStatus($order, $request->user(), $target);

        return response()->json([
            'data' => new OrderResource($order->load(self::RELATIONS)),
            'message' => 'Order marked '.$target->label().'.',
        ]);
    }

    /**
     * The scope shared by both roles: an executive's view is limited to the
     * orders they placed, an administrator's sees everything and adds the
     * employee filter.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function filteredQuery(array $validated, ?int $viewerId = null): Builder
    {
        $query = Order::query()->with(self::RELATIONS)->orderByDesc('id');

        if ($viewerId !== null) {
            $query->where('employee_id', $viewerId);
        }

        $query->when($validated['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status));
        $query->when($validated['shop_id'] ?? null, fn (Builder $q, int $shopId) => $q->where('shop_id', $shopId));

        if (filled($validated['q'] ?? null)) {
            $term = '%'.trim($validated['q']).'%';

            $query->where(function (Builder $query) use ($term): void {
                $query->where('order_number', 'like', $term)
                    ->orWhereHas('shop', fn (Builder $q) => $q->where('name', 'like', $term))
                    ->orWhereHas('employee', fn (Builder $q) => $q->where('name', 'like', $term));
            });
        }

        if (filled($validated['from'] ?? null)) {
            [$start] = BusinessTime::utcBounds((string) $validated['from']);
            $query->where('created_at', '>=', $start);
        }

        if (filled($validated['to'] ?? null)) {
            [, $end] = BusinessTime::utcBounds((string) $validated['to']);
            $query->where('created_at', '<', $end);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  LengthAwarePaginator  $paginator
     * @return array<string, mixed>
     */
    protected function meta(array $validated, $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertOrder(array $data, int $employeeId): Order
    {
        $products = Product::query()
            ->whereIn('id', array_column($data['items'], 'product_id'))
            ->get()
            ->keyBy('id');

        $order = Order::create([
            'client_order_id' => strtoupper($data['client_order_id']),
            'shop_id' => (int) $data['shop_id'],
            'employee_id' => $employeeId,
            'status' => OrderStatus::Pending,
            'total_paise' => 0,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'device_created_at' => $data['device_created_at'],
            'server_recorded_at' => now('UTC'),
        ]);

        $total = 0;

        foreach ($data['items'] as $line) {
            $product = $products->get((int) $line['product_id']);

            if ($product === null) {
                // Deleted between validation and write: fail the whole order
                // rather than record a line nobody can attribute.
                throw ValidationException::withMessages([
                    'items' => 'A product in this order no longer exists.',
                ]);
            }

            $quantity = (int) $line['quantity'];
            $unitPrice = (int) $line['unit_price_paise'];
            $lineTotal = $quantity * $unitPrice;
            $total += $lineTotal;

            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => $product->unit,
                'unit_price_paise' => $unitPrice,
                'quantity' => $quantity,
                'line_total_paise' => $lineTotal,
            ]);
        }

        $order->update([
            'total_paise' => $total,
            // Built from the auto-increment id, so it is unique without a
            // sequence table and still sorts chronologically.
            'order_number' => sprintf(
                'ORD-%s-%06d',
                str_replace('-', '', BusinessTime::today()),
                $order->id,
            ),
        ]);

        return $order->fresh();
    }

    protected function changeStatus(Order $order, User $actor, OrderStatus $target): Order
    {
        if ($order->status === $target) {
            return $order;
        }

        if (! $order->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Only a pending order can change status; this one is '.$order->status->label().'.',
            ]);
        }

        $attributes = ['status' => $target];

        if ($target === OrderStatus::Delivered) {
            $attributes['delivered_at'] = now('UTC');
            $attributes['delivered_by'] = $actor->id;
        } else {
            $attributes['cancelled_at'] = now('UTC');
            $attributes['cancelled_by'] = $actor->id;
        }

        $order->fill($attributes)->save();

        return $order;
    }
}
