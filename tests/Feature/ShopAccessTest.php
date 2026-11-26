<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-staff shop access: administrators grant and withdraw shops one
 * executive at a time, hide a shop from the whole field, and every
 * shop-scoped feature — directory, order form, credit ledger — follows the
 * same list.
 */
class ShopAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $author;

    private User $colleague;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->author = User::factory()->create();
        $this->colleague = User::factory()->create();
        $this->shop = Shop::factory()->create(['created_by' => $this->author->id]);
    }

    public function test_a_hidden_shop_leaves_the_field_but_stays_with_administrators(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/admin/shops/{$this->shop->id}/access", [
            'is_visible_to_field' => false,
        ])->assertOk()->assertJsonPath('data.is_visible_to_field', false);

        // The author created it and still holds a seat, but the switch hides
        // it from every executive, whoever they are.
        Sanctum::actingAs($this->author);
        $this->getJson('/api/v1/employee/shops')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/employee/shops/{$this->shop->id}")->assertForbidden();
        $this->getJson("/api/v1/employee/shops/{$this->shop->id}/credit")->assertForbidden();

        // The order form refuses too, so no order can be written for a shop
        // the executive cannot see.
        $product = Product::factory()->create();
        $this->postJson('/api/v1/employee/orders', [
            'client_order_id' => (string) Str::ulid(),
            'shop_id' => $this->shop->id,
            'device_created_at' => now()->toIso8601String(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price_paise' => 1000]],
        ])->assertForbidden();

        // Administrators keep every view of it.
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/shops')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/shops?visible=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/shops?visible=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_visible_to_field', false);
        $this->getJson("/api/v1/admin/shops/{$this->shop->id}")->assertOk();
        $this->getJson("/api/v1/admin/shops/{$this->shop->id}/credit")->assertOk();
    }

    public function test_the_access_screen_reports_and_replaces_the_granted_staff(): void
    {
        Sanctum::actingAs($this->admin);

        // The author holds a seat because they created the shop.
        $this->getJson("/api/v1/admin/shops/{$this->shop->id}/access")
            ->assertOk()
            ->assertJsonPath('data.is_visible_to_field', true)
            ->assertJsonPath('data.staff.0.id', $this->author->id);

        // Sharing it with a colleague adds a second seat without touching
        // the first.
        $this->putJson("/api/v1/admin/shops/{$this->shop->id}/access", [
            'employee_ids' => [$this->author->id, $this->colleague->id],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.staff');

        Sanctum::actingAs($this->colleague);
        $this->getJson('/api/v1/employee/shops')->assertOk()->assertJsonCount(1, 'data');

        // Withdrawing the author takes the shop out of their directory —
        // the access list is the single source of truth, not `created_by`.
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/admin/shops/{$this->shop->id}/access", [
            'employee_ids' => [$this->colleague->id],
        ])->assertOk()->assertJsonCount(1, 'data.staff');

        Sanctum::actingAs($this->author);
        $this->getJson('/api/v1/employee/shops')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/employee/shops/{$this->shop->id}/credit")->assertForbidden();

        // Only real field staff can be granted a shop.
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/admin/shops/{$this->shop->id}/access", [
            'employee_ids' => [$this->admin->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_ids.0');
    }

    public function test_a_new_executive_starts_with_the_visible_directory(): void
    {
        $hidden = Shop::factory()->create(['created_by' => $this->admin->id]);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/admin/shops/{$hidden->id}/access", [
            'is_visible_to_field' => false,
        ])->assertOk();

        $id = $this->postJson('/api/v1/admin/employees', [
            'employee_code' => 'FE-77',
            'name' => 'Fresh Hire',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('shop_employee_access', [
            'shop_id' => $this->shop->id,
            'employee_id' => $id,
        ]);
        $this->assertDatabaseMissing('shop_employee_access', [
            'shop_id' => $hidden->id,
            'employee_id' => $id,
        ]);

        // The new hire sees exactly the shops the team already sees.
        Sanctum::actingAs(User::query()->findOrFail($id));
        $this->getJson('/api/v1/employee/shops')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->shop->id);
    }

    public function test_a_colleague_can_only_order_from_a_shop_granted_to_them(): void
    {
        $product = Product::factory()->create();

        Sanctum::actingAs($this->colleague);
        $this->postJson('/api/v1/employee/orders', [
            'client_order_id' => (string) Str::ulid(),
            'shop_id' => $this->shop->id,
            'device_created_at' => now()->toIso8601String(),
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price_paise' => 1500]],
        ])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/admin/shops/{$this->shop->id}/access", [
            'employee_ids' => [$this->author->id, $this->colleague->id],
        ])->assertOk();

        Sanctum::actingAs($this->colleague);
        $this->postJson('/api/v1/employee/orders', [
            'client_order_id' => (string) Str::ulid(),
            'shop_id' => $this->shop->id,
            'device_created_at' => now()->toIso8601String(),
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price_paise' => 1500]],
        ])->assertCreated();
    }

    public function test_the_access_screen_needs_an_administrator(): void
    {
        Sanctum::actingAs($this->colleague);

        $this->getJson("/api/v1/admin/shops/{$this->shop->id}/access")->assertForbidden();
        $this->putJson("/api/v1/admin/shops/{$this->shop->id}/access", [
            'employee_ids' => [$this->colleague->id],
        ])->assertForbidden();
    }
}
