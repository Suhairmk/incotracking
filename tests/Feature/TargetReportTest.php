<?php

namespace Tests\Feature;

use App\Models\MonthlySalesTarget;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TargetReportTest extends TestCase
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
     * Place an order and move it to the requested status.
     */
    private function placeOrder(int $paise, string $status, string $businessDate): int
    {
        $product = Product::factory()->create(['price_paise' => $paise]);

        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/v1/employee/orders', [
            'client_order_id' => (string) Str::ulid(),
            'shop_id' => $this->shop->id,
            'device_created_at' => now()->toIso8601String(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => $paise]],
        ])->assertCreated()->json('data.id');

        Order::query()->whereKey($id)->update([
            'status' => $status,
            'business_date' => $businessDate,
            'delivered_at' => $status === 'delivered' ? now() : null,
        ]);

        return $id;
    }

    public function test_the_admin_sets_a_target_and_sees_the_performance_against_it(): void
    {
        $month = now()->startOfMonth()->toDateString();

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/admin/targets', [
            'employee_id' => $this->employee->id,
            'month' => $month,
            'target_paise' => 500000,
        ])->assertCreated()->assertJsonPath('data.target_paise', 500000);

        // Two delivered orders (ex-GST) and one that is still pending.
        $this->placeOrder(150000, 'delivered', now()->toDateString());
        $this->placeOrder(250000, 'delivered', now()->toDateString());
        $this->placeOrder(900000, 'pending', now()->toDateString());

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/targets?month='.$month)->assertOk();

        $row = collect($response->json('data'))
            ->firstWhere('employee_id', $this->employee->id);

        $this->assertNotNull($row);
        $this->assertSame(500000, $row['target_paise']);
        $this->assertSame(400000, $row['achieved_paise']);
        $this->assertSame(100000, $row['remaining_paise']);
        $this->assertEqualsWithDelta(80.0, $row['achievement_pct'], 0.001);
        $this->assertSame(2, $row['delivered_orders']);
        $this->assertSame(3, $row['total_orders']);
        $this->assertTrue($row['target_set']);
        // The admin UI edits and deletes through this id.
        $this->assertNotNull($row['target_id']);
    }

    public function test_achievement_counts_delivered_orders_at_taxable_value_excluding_gst(): void
    {
        $month = now()->startOfMonth()->toDateString();

        // A taxed order: 100000 taxable + 18% GST = 118000 charged, but only
        // the taxable value may count towards the target.
        $product = Product::factory()->create(['price_paise' => 100000]);
        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/v1/employee/orders', [
            'client_order_id' => (string) Str::ulid(),
            'shop_id' => $this->shop->id,
            'device_created_at' => now()->toIso8601String(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => 100000]],
        ])->assertCreated()->json('data.id');

        Order::query()->whereKey($id)->update([
            'status' => 'delivered',
            'business_date' => now()->toDateString(),
            'delivered_at' => now(),
        ]);

        $order = Order::query()->whereKey($id)->sole();
        $this->assertSame(118000, $order->total_paise);
        $this->assertSame(100000, $order->subtotal_paise);

        Sanctum::actingAs($this->admin);
        $row = collect($this->getJson('/api/v1/admin/targets?month='.$month)->assertOk()->json('data'))
            ->firstWhere('employee_id', $this->employee->id);

        $this->assertSame(100000, $row['achieved_paise']);
    }

    public function test_a_row_without_a_target_reports_zero_and_no_target_set(): void
    {
        Sanctum::actingAs($this->admin);
        $row = collect($this->getJson('/api/v1/admin/targets')->assertOk()->json('data'))
            ->firstWhere('employee_id', $this->employee->id);

        $this->assertSame(0, $row['target_paise']);
        $this->assertEqualsWithDelta(0.0, $row['achievement_pct'], 0.001);
        $this->assertFalse($row['target_set']);
    }

    public function test_updating_a_target_replaces_it_rather_than_adding_a_second_row(): void
    {
        $month = now()->startOfMonth()->toDateString();
        Sanctum::actingAs($this->admin);

        $payload = [
            'employee_id' => $this->employee->id,
            'month' => $month,
            'target_paise' => 100000,
        ];

        $this->putJson('/api/v1/admin/targets', $payload)->assertCreated();
        $this->putJson('/api/v1/admin/targets', [...$payload, 'target_paise' => 250000])
            ->assertCreated()
            ->assertJsonPath('data.target_paise', 250000);

        $this->assertDatabaseCount('monthly_sales_targets', 1);
        $this->assertDatabaseHas('monthly_sales_targets', [
            'employee_id' => $this->employee->id,
            'target_paise' => 250000,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_a_target_can_be_removed(): void
    {
        $target = MonthlySalesTarget::factory()->create([
            'employee_id' => $this->employee->id,
            'target_paise' => 100000,
        ]);

        Sanctum::actingAs($this->admin);
        $this->deleteJson('/api/v1/admin/targets/'.$target->id)->assertOk();

        $this->assertDatabaseCount('monthly_sales_targets', 0);
    }

    public function test_a_field_executive_sees_only_their_own_performance(): void
    {
        $month = now()->startOfMonth()->toDateString();
        $colleague = User::factory()->create();

        MonthlySalesTarget::factory()->create([
            'employee_id' => $this->employee->id,
            'month' => $month,
            'target_paise' => 300000,
        ]);
        MonthlySalesTarget::factory()->create([
            'employee_id' => $colleague->id,
            'month' => $month,
            'target_paise' => 900000,
        ]);

        $this->placeOrder(120000, 'delivered', now()->toDateString());

        Sanctum::actingAs($this->employee);
        $row = $this->getJson('/api/v1/employee/performance?month='.$month)
            ->assertOk()
            ->json('data');

        $this->assertSame($this->employee->id, $row['employee_id']);
        $this->assertSame(300000, $row['target_paise']);
        $this->assertSame(120000, $row['achieved_paise']);
        $this->assertEqualsWithDelta(40.0, $row['achievement_pct'], 0.001);
    }

    public function test_a_field_executive_cannot_touch_targets(): void
    {
        Sanctum::actingAs($this->employee);

        $this->putJson('/api/v1/admin/targets', [
            'employee_id' => $this->employee->id,
            'month' => now()->startOfMonth()->toDateString(),
            'target_paise' => 1,
        ])->assertForbidden();

        $this->getJson('/api/v1/admin/targets')->assertForbidden();
    }

    public function test_order_counts_are_reported_per_employee_by_day_and_month(): void
    {
        $this->placeOrder(10000, 'delivered', now()->toDateString());
        $this->placeOrder(20000, 'pending', now()->toDateString());

        Sanctum::actingAs($this->admin);

        $byDay = $this->getJson('/api/v1/admin/reports/order-counts')
            ->assertOk()
            ->json('data');

        $mine = collect($byDay)->firstWhere('employee_id', $this->employee->id);
        $this->assertSame(2, $mine['orders']);
        $this->assertSame(1, $mine['delivered']);
        $this->assertSame(1, $mine['pending']);
        $this->assertSame(30000, $mine['sales_paise']);

        $byMonth = $this->getJson('/api/v1/admin/reports/order-counts?group_by=month')
            ->assertOk()
            ->json('data');

        $mineMonth = collect($byMonth)->firstWhere('employee_id', $this->employee->id);
        $this->assertSame(now()->format('Y-m'), $mineMonth['period']);
        $this->assertSame(2, $mineMonth['orders']);
    }

    public function test_the_order_report_lists_orders_with_their_money_split(): void
    {
        $this->placeOrder(50000, 'delivered', now()->toDateString());

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/reports/orders')->assertOk();

        $row = $response->json('data.0');
        $this->assertSame($this->employee->id, $row['employee_id']);
        $this->assertSame($this->shop->id, $row['shop_id']);
        $this->assertSame('delivered', $row['status']);
        $this->assertNotEmpty($row['items']);
        $this->assertSame(now()->toDateString(), $row['business_date']);

        // Filterable by field staff.
        $this->getJson('/api/v1/admin/reports/orders?employee_id='.User::factory()->create()->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_reports_are_csv_exportable(): void
    {
        $this->placeOrder(50000, 'delivered', now()->toDateString());

        Sanctum::actingAs($this->admin);
        $this->get('/api/v1/admin/reports/orders?export=csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->get('/api/v1/admin/reports/order-counts?export=csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
}
