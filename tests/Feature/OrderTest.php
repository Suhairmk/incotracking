<?php

namespace Tests\Feature;

use App\Models\NotificationMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->employee = User::factory()->create();
        $this->shop = Shop::factory()->create(['created_by' => $this->employee->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderPayload(array $overrides = []): array
    {
        return [
            'client_order_id' => (string) Str::ulid(),
            'shop_id' => $this->shop->id,
            'notes' => 'Deliver before noon',
            'device_created_at' => now()->subMinutes(3)->toIso8601String(),
            'items' => [
                ['product_id' => 0, 'quantity' => 2, 'unit_price_paise' => 10000],
                ['product_id' => 0, 'quantity' => 3, 'unit_price_paise' => 2500],
            ],
            ...$overrides,
        ];
    }

    public function test_an_executive_places_an_order_and_the_administrators_are_notified(): void
    {
        $first = Product::factory()->create(['name' => 'Sunflower Oil 1L', 'unit' => 'ltr', 'price_paise' => 10000]);
        $second = Product::factory()->create(['name' => 'Detergent Powder 1kg', 'unit' => 'kg', 'price_paise' => 2500]);

        Sanctum::actingAs($this->employee);

        $payload = $this->orderPayload();
        $payload['items'][0]['product_id'] = $first->id;
        $payload['items'][1]['product_id'] = $second->id;

        $response = $this->postJson('/api/v1/employee/orders', $payload)->assertCreated();

        $response->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Pending')
            ->assertJsonPath('data.total_paise', 27500)
            ->assertJsonPath('data.shop.id', $this->shop->id)
            ->assertJsonPath('data.employee.id', $this->employee->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product_name', 'Sunflower Oil 1L')
            ->assertJsonPath('data.items.0.line_total_paise', 20000)
            ->assertJsonPath('data.items.1.line_total_paise', 7500);

        $orderNumber = $response->json('order_number') ?? $response->json('data.order_number');
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-\d{6}$/', $orderNumber);

        $this->assertDatabaseHas('orders', [
            'order_number' => $orderNumber,
            'shop_id' => $this->shop->id,
            'employee_id' => $this->employee->id,
            'status' => 'pending',
            'total_paise' => 27500,
            'client_order_id' => $payload['client_order_id'],
        ]);

        // Every active administrator gets an in-app notification row; the
        // push attempt itself is skipped while Firebase is disabled.
        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $this->admin->id,
            'title' => 'New order '.$orderNumber,
        ]);

        $message = NotificationMessage::query()->where('user_id', $this->admin->id)->sole();
        $this->assertSame('order', $message->data['type']);
        $this->assertSame($response->json('data.id') ?? $response->json('id'), $message->data['order_id']);
    }

    public function test_re_uploading_the_same_client_order_id_records_one_order_and_one_notification(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs($this->employee);

        $payload = $this->orderPayload();
        $payload['items'][0]['product_id'] = $product->id;
        $payload['items'] = [$payload['items'][0]];

        $first = $this->postJson('/api/v1/employee/orders', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/employee/orders', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.order_number'), $second->json('data.order_number'));
        $this->assertSame('Order already recorded.', $second->json('message'));

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, NotificationMessage::query()->where('user_id', $this->admin->id)->count());
    }

    public function test_order_input_is_validated(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs($this->employee);

        $this->postJson('/api/v1/employee/orders', $this->orderPayload(['items' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $payload = $this->orderPayload();
        $payload['items'][0]['product_id'] = $product->id;
        $payload['items'][0]['quantity'] = 0;
        $this->postJson('/api/v1/employee/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);

        $payload = $this->orderPayload();
        $payload['items'][0]['product_id'] = 999999;
        $this->postJson('/api/v1/employee/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id']);

        $payload = $this->orderPayload(['shop_id' => 999999]);
        $payload['items'][0]['product_id'] = $product->id;
        $this->postJson('/api/v1/employee/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shop_id']);

        $this->postJson('/api/v1/employee/orders', $this->orderPayload(['client_order_id' => 'too-short']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_order_id']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_quoted_price_is_kept_even_when_the_catalogue_moves_on(): void
    {
        $product = Product::factory()->create(['price_paise' => 10000]);
        Sanctum::actingAs($this->employee);

        $payload = $this->orderPayload();
        $payload['items'] = [['product_id' => $product->id, 'quantity' => 2, 'unit_price_paise' => 9000]];

        $response = $this->postJson('/api/v1/employee/orders', $payload)->assertCreated();
        $this->assertSame(18000, $response->json('data.total_paise'));

        // The catalogue reprices (or is retired) while the order sits in the
        // queue: the synced order still says what the agent sold it for.
        $product->update(['price_paise' => 12500, 'is_active' => false]);

        $this->getJson('/api/v1/employee/orders/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.total_paise', 18000)
            ->assertJsonPath('data.items.0.unit_price_paise', 9000);
    }

    public function test_only_the_creator_and_administrators_can_see_an_order(): void
    {
        $product = Product::factory()->create();
        $colleague = User::factory()->create();

        Sanctum::actingAs($this->employee);

        $payload = $this->orderPayload();
        $payload['items'] = [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => 5000]];
        $order = $this->postJson('/api/v1/employee/orders', $payload)->assertCreated()->json('data.id');

        // The creator's own list carries it…
        $this->getJson('/api/v1/employee/orders')->assertOk()->assertJsonCount(1, 'data');

        // …a colleague sees nothing and cannot open it directly…
        Sanctum::actingAs($colleague);
        $this->getJson('/api/v1/employee/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/employee/orders/{$order}")->assertForbidden();

        // …while an administrator sees every order.
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/orders/{$order}")->assertOk()->assertJsonPath('data.id', $order);
    }

    public function test_administrators_filter_orders_by_status_employee_and_search_term(): void
    {
        $other = User::factory()->create();
        $product = Product::factory()->create();

        $place = function (User $who) use ($product): int {
            Sanctum::actingAs($who);
            $payload = $this->orderPayload();
            $payload['items'] = [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => 1000]];

            return $this->postJson('/api/v1/employee/orders', $payload)->assertCreated()->json('data.id');
        };

        $mine = $place($this->employee);
        $theirs = $place($other);
        $delivered = $place($other);
        Order::query()->whereKey($delivered)->update(['status' => 'delivered']);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/orders?status=pending')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $theirs);

        $this->getJson('/api/v1/admin/orders?status=delivered')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $delivered);

        $this->getJson('/api/v1/admin/orders?employee_id='.$other->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/admin/orders?employee_id='.$this->employee->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine);

        $number = Order::query()->whereKey($mine)->sole()->order_number;
        $this->getJson('/api/v1/admin/orders?q='.urlencode($number))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine);

        // The executive's own list applies the same filters to their slice.
        Sanctum::actingAs($this->employee);
        $this->getJson('/api/v1/employee/orders?status=delivered')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_creator_or_an_administrator_can_mark_a_pending_order_delivered(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs($this->employee);

        $payload = $this->orderPayload();
        $payload['items'] = [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => 1000]];
        $id = $this->postJson('/api/v1/employee/orders', $payload)->assertCreated()->json('data.id');

        // The agent who placed it may mark it delivered from the field.
        $delivered = $this->patchJson("/api/v1/employee/orders/{$id}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.delivered_by.id', $this->employee->id);

        $this->assertNotNull($delivered->json('data.delivered_at'));
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'delivered', 'delivered_by' => $this->employee->id]);

        // Delivered is final: nothing can move it again.
        $this->patchJson("/api/v1/employee/orders/{$id}/status", ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_an_administrator_can_mark_any_order_delivered_but_a_colleague_cannot(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs($this->employee);

        $payload = $this->orderPayload();
        $payload['items'] = [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => 1000]];
        $id = $this->postJson('/api/v1/employee/orders', $payload)->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/employee/orders/{$id}/status", ['status' => 'delivered'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/admin/orders/{$id}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.delivered_by.id', $this->admin->id);

        // Repeating the same status is a harmless no-op for a retried tap.
        $this->patchJson("/api/v1/admin/orders/{$id}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->patchJson("/api/v1/admin/orders/{$id}/status", ['status' => 'bogus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_a_pending_order_can_be_cancelled_by_its_creator_or_an_administrator(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs($this->employee);

        $payload = $this->orderPayload();
        $payload['items'] = [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => 1000]];
        $id = $this->postJson('/api/v1/employee/orders', $payload)->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/employee/orders/{$id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by.id', $this->employee->id);

        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'cancelled', 'cancelled_by' => $this->employee->id]);

        // A cancelled order is settled too.
        $this->patchJson("/api/v1/employee/orders/{$id}/status", ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_the_dashboard_reports_the_pending_order_count(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs($this->employee);

        $payload = $this->orderPayload();
        $payload['items'] = [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => 1000]];
        $id = $this->postJson('/api/v1/employee/orders', $payload)->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.pending_orders', 1);

        $this->patchJson("/api/v1/admin/orders/{$id}/status", ['status' => 'delivered'])->assertOk();

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.pending_orders', 0);
    }
}
