<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_updates_and_retires_catalogue_entries(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $created = $this->postJson('/api/v1/admin/products', [
            'name' => 'Sunflower Oil 1L',
            'unit' => 'ltr',
            'price_paise' => 14500,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Sunflower Oil 1L')
            ->assertJsonPath('data.price_paise', 14500)
            ->assertJsonPath('data.is_active', true);

        $id = $created->json('data.id');

        $this->putJson("/api/v1/admin/products/{$id}", [
            'name' => 'Sunflower Oil 1L',
            'unit' => 'bottle',
            'price_paise' => 15000,
        ])->assertOk()
            ->assertJsonPath('data.unit', 'bottle')
            ->assertJsonPath('data.price_paise', 15000)
            ->assertJsonPath('data.is_active', true);

        // Retiring removes it from what the field can sell, without deleting
        // the row orders still point at.
        $this->deleteJson("/api/v1/admin/products/{$id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('products', ['id' => $id, 'is_active' => false]);

        $this->getJson('/api/v1/admin/products?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_the_catalogue_is_validated_and_searchable(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Product::factory()->create(['name' => 'Premium Rice 25kg']);
        Product::factory()->create(['name' => 'Detergent Powder 1kg']);

        $this->postJson('/api/v1/admin/products', [
            'name' => '',
            'unit' => '',
            'price_paise' => -5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'unit', 'price_paise']);

        $this->getJson('/api/v1/admin/products?q=rice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Premium Rice 25kg')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_field_executives_cannot_manage_the_catalogue(): void
    {
        $employee = User::factory()->create();
        $product = Product::factory()->create();

        Sanctum::actingAs($employee);

        $this->getJson('/api/v1/admin/products')->assertForbidden();
        $this->postJson('/api/v1/admin/products', ['name' => 'X', 'unit' => 'pcs', 'price_paise' => 100])
            ->assertForbidden();
        $this->putJson("/api/v1/admin/products/{$product->id}", ['name' => 'X', 'unit' => 'pcs', 'price_paise' => 100])
            ->assertForbidden();
        $this->deleteJson("/api/v1/admin/products/{$product->id}")->assertForbidden();
    }

    public function test_reference_data_returns_the_directory_and_the_active_catalogue(): void
    {
        $employee = User::factory()->create();
        $withGst = Shop::factory()->create([
            'created_by' => $employee->id,
            'name' => 'With GST',
            'gst_number' => '27AAPFU0939F1ZV',
        ]);
        Shop::factory()->dealer()->create(['created_by' => $employee->id, 'name' => 'Dealer Row']);

        Product::factory()->create(['name' => 'Active Item', 'is_active' => true]);
        Product::factory()->inactive()->create(['name' => 'Retired Item']);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/v1/employee/reference-data')->assertOk();

        $response->assertJsonCount(2, 'data.shops')
            ->assertJsonPath('data.shops.0.name', 'Dealer Row')
            ->assertJsonPath('data.shops.1.gst_number', '27AAPFU0939F1ZV')
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.name', 'Active Item');

        $this->assertNotNull($response->json('data.server_time'));
        $this->assertIsFloat($response->json('data.shops.1.latitude'));
        $this->assertSame($withGst->id, $response->json('data.shops.1.id'));
    }
}
