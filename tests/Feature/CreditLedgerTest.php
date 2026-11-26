<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopCreditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
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
     * Place an order as the employee, which also writes its credit sale.
     */
    private function placeOrder(int $pricePaise): Order
    {
        $product = Product::factory()->create(['price_paise' => $pricePaise]);

        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/v1/employee/orders', [
            'client_order_id' => (string) Str::ulid(),
            'shop_id' => $this->shop->id,
            'device_created_at' => now()->toIso8601String(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => $pricePaise]],
        ])->assertCreated()->json('data.id');

        return Order::query()->findOrFail($id);
    }

    public function test_a_new_order_writes_a_read_only_credit_sale_and_updates_the_balance(): void
    {
        $order = $this->placeOrder(100000);

        // 100000 taxable + 18% GST = 118000 owed by the shop.
        $this->assertDatabaseHas('shop_credit_entries', [
            'shop_id' => $this->shop->id,
            'type' => ShopCreditEntry::TYPE_SALE,
            'amount_paise' => 118000,
            'order_id' => $order->id,
            'source' => ShopCreditEntry::SOURCE_ORDER,
        ]);
        $this->assertSame(118000, (int) $this->shop->fresh()->credit_balance_paise);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/shops/'.$this->shop->id.'/credit')->assertOk();

        $this->assertSame(118000, $response->json('meta.balance_paise'));
        $this->assertSame(118000, $response->json('meta.debit_paise'));
        $this->assertTrue($response->json('data.0.read_only'));
        $this->assertSame($order->order_number, $response->json('data.0.order_number'));
    }

    public function test_cancelling_an_order_removes_its_credit_sale(): void
    {
        $order = $this->placeOrder(50000);

        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/admin/orders/'.$order->id.'/status', ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSoftDeleted('shop_credit_entries', ['order_id' => $order->id]);
        $this->assertSame(0, (int) $this->shop->fresh()->credit_balance_paise);
    }

    public function test_manual_receipts_and_opening_balances_keep_the_running_balance(): void
    {
        Sanctum::actingAs($this->admin);
        $base = '/api/v1/admin/shops/'.$this->shop->id.'/credit';

        $this->postJson($base, [
            'type' => 'opening',
            'amount_paise' => 500000,
            'entry_date' => '2026-11-01',
        ])->assertCreated()->assertJsonPath('balance_paise', 500000);

        $this->postJson($base, [
            'type' => 'receipt',
            'amount_paise' => 200000,
            'entry_date' => '2026-11-10',
            'reference' => 'RCP-001',
        ])->assertCreated()->assertJsonPath('balance_paise', 300000);

        $this->postJson($base, [
            'type' => 'payment',
            'amount_paise' => 100000,
            'entry_date' => '2026-11-15',
        ])->assertCreated()->assertJsonPath('balance_paise', 200000);

        $response = $this->getJson($base)->assertOk();
        $this->assertSame(200000, $response->json('meta.balance_paise'));
        $this->assertSame(500000, $response->json('meta.debit_paise'));
        $this->assertSame(-300000, $response->json('meta.credit_paise'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_a_receipt_is_stored_negative_whatever_sign_the_client_sends(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/shops/'.$this->shop->id.'/credit', [
            'type' => 'receipt',
            'amount_paise' => 150000,
            'entry_date' => '2026-11-12',
        ])->assertCreated()->assertJsonPath('data.amount_paise', -150000);

        $this->postJson('/api/v1/admin/shops/'.$this->shop->id.'/credit', [
            'type' => 'opening',
            'amount_paise' => -50000,
            'entry_date' => '2026-11-12',
        ])->assertCreated()->assertJsonPath('data.amount_paise', 50000);

        $this->assertSame(-100000, (int) $this->shop->fresh()->credit_balance_paise);
    }

    public function test_order_generated_entries_are_read_only(): void
    {
        $this->placeOrder(100000);
        $entry = ShopCreditEntry::query()->where('shop_id', $this->shop->id)->sole();

        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/admin/credit/'.$entry->id, [
            'amount_paise' => 1,
        ])->assertStatus(422);

        $this->deleteJson('/api/v1/admin/credit/'.$entry->id)->assertStatus(422);

        $this->assertDatabaseHas('shop_credit_entries', [
            'id' => $entry->id,
            'amount_paise' => 118000,
            'deleted_at' => null,
        ]);
    }

    public function test_manual_entries_can_be_edited_and_removed(): void
    {
        Sanctum::actingAs($this->admin);
        $base = '/api/v1/admin/shops/'.$this->shop->id.'/credit';

        $id = $this->postJson($base, [
            'type' => 'receipt',
            'amount_paise' => 100000,
            'entry_date' => '2026-11-05',
        ])->assertCreated()->json('data.id');

        $this->putJson('/api/v1/admin/credit/'.$id, ['amount_paise' => 75000])
            ->assertOk()
            ->assertJsonPath('data.amount_paise', -75000)
            ->assertJsonPath('balance_paise', -75000);

        $this->deleteJson('/api/v1/admin/credit/'.$id)
            ->assertOk()
            ->assertJsonPath('balance_paise', 0);

        $this->assertSoftDeleted('shop_credit_entries', ['id' => $id]);
    }

    public function test_an_executive_reads_the_ledger_of_their_own_shop_only(): void
    {
        $colleague = User::factory()->create();
        $otherShop = Shop::factory()->create(['created_by' => $colleague->id]);
        ShopCreditEntry::factory()->sale(250000)->create([
            'shop_id' => $otherShop->id,
            'order_id' => null,
        ]);
        ShopCreditEntry::factory()->sale(90000)->create([
            'shop_id' => $this->shop->id,
            'order_id' => null,
        ]);

        Sanctum::actingAs($this->employee);

        $this->getJson('/api/v1/employee/shops/'.$this->shop->id.'/credit')
            ->assertOk()
            ->assertJsonPath('meta.balance_paise', 90000)
            ->assertJsonCount(1, 'data');

        // Another executive's shop is off limits: 403 from the policy.
        $this->getJson('/api/v1/employee/shops/'.$otherShop->id.'/credit')
            ->assertStatus(403);
    }

    public function test_an_executive_cannot_edit_the_ledger_of_a_colleagues_shop(): void
    {
        $colleague = User::factory()->create();
        $otherShop = Shop::factory()->create(['created_by' => $colleague->id]);
        $entry = ShopCreditEntry::factory()->create([
            'shop_id' => $otherShop->id,
            'order_id' => null,
        ]);

        Sanctum::actingAs($this->employee);

        $this->postJson('/api/v1/employee/shops/'.$otherShop->id.'/credit', [
            'type' => 'receipt',
            'amount_paise' => 100,
            'entry_date' => '2026-11-01',
        ])->assertStatus(403);

        $this->putJson('/api/v1/employee/credit/'.$entry->id, ['amount_paise' => 1])
            ->assertStatus(403);

        $this->deleteJson('/api/v1/employee/credit/'.$entry->id)->assertStatus(403);
    }

    public function test_an_executive_can_maintain_the_ledger_of_their_own_shop(): void
    {
        Sanctum::actingAs($this->employee);
        $base = '/api/v1/employee/shops/'.$this->shop->id.'/credit';

        $id = $this->postJson($base, [
            'type' => 'receipt',
            'amount_paise' => 40000,
            'entry_date' => '2026-11-20',
        ])->assertCreated()->json('data.id');

        $this->putJson('/api/v1/employee/credit/'.$id, ['reference' => 'RCP-9'])
            ->assertOk()
            ->assertJsonPath('data.reference', 'RCP-9');

        $this->deleteJson('/api/v1/employee/credit/'.$id)->assertOk();
    }

    public function test_an_administrator_sees_every_shops_ledger(): void
    {
        $colleague = User::factory()->create();
        $otherShop = Shop::factory()->create(['created_by' => $colleague->id]);
        ShopCreditEntry::factory()->sale(600000)->create([
            'shop_id' => $otherShop->id,
            'order_id' => null,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/shops/'.$otherShop->id.'/credit')
            ->assertOk()
            ->assertJsonPath('meta.balance_paise', 600000);
    }

    public function test_the_ledger_can_be_filtered_by_entry_type(): void
    {
        Sanctum::actingAs($this->admin);
        $base = '/api/v1/admin/shops/'.$this->shop->id.'/credit';

        $this->postJson($base, [
            'type' => 'opening',
            'amount_paise' => 300000,
            'entry_date' => '2026-11-01',
        ])->assertCreated();
        $this->postJson($base, [
            'type' => 'receipt',
            'amount_paise' => 100000,
            'entry_date' => '2026-11-02',
        ])->assertCreated();

        $this->getJson($base.'?type=receipt')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'receipt');
    }

    public function test_a_zero_or_malformed_amount_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/shops/'.$this->shop->id.'/credit', [
            'type' => 'receipt',
            'amount_paise' => 0,
            'entry_date' => '2026-11-01',
        ])->assertStatus(422)->assertJsonValidationErrors('amount_paise');

        $this->postJson('/api/v1/admin/shops/'.$this->shop->id.'/credit', [
            'type' => 'not-a-type',
            'amount_paise' => 100,
            'entry_date' => '2026-11-01',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }
}
