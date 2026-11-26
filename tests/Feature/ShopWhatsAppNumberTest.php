<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\WhatsAppNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopWhatsAppNumberTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sri Balaji Motors',
            'type' => 'dealer',
            'location_name' => 'Indiranagar',
            'whatsapp_number' => '9876543210',
            'latitude' => 12.9716,
            'longitude' => 77.5946,
        ], $overrides);
    }

    public function test_every_new_shop_must_carry_a_whatsapp_number(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/employee/shops', $this->payload(['whatsapp_number' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['whatsapp_number']);

        $this->postJson('/api/v1/employee/shops', $this->payload(['whatsapp_number' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['whatsapp_number']);

        $this->assertDatabaseCount('shops', 0);
    }

    public function test_the_number_is_normalised_to_canonical_digits_on_the_way_in(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $shapes = [
            '9876543210',
            '+91 98765 43210',
            '09876543210',
            '00919876543210',
            '91-98765-43210',
        ];

        foreach ($shapes as $index => $shape) {
            $response = $this->postJson('/api/v1/employee/shops', $this->payload([
                'name' => "Shop {$index}",
                'whatsapp_number' => $shape,
            ]))->assertCreated();

            $this->assertSame('919876543210', $response->json('data.whatsapp_number'));
            $this->assertDatabaseHas('shops', [
                'name' => "Shop {$index}",
                'whatsapp_number' => '919876543210',
            ]);
        }
    }

    public function test_a_whatsapp_number_that_is_not_a_number_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (['call us', '12345', '98765432101234567'] as $bad) {
            $this->postJson('/api/v1/employee/shops', $this->payload(['whatsapp_number' => $bad]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['whatsapp_number']);
        }

        $this->assertDatabaseCount('shops', 0);
    }

    public function test_editing_an_existing_shop_may_leave_the_number_out_or_clear_it(): void
    {
        $employee = User::factory()->create();
        $shop = Shop::factory()->create([
            'created_by' => $employee->id,
            'whatsapp_number' => '919876543210',
        ]);

        Sanctum::actingAs($employee);

        // Shops recorded before this field existed stay editable: an edit
        // that never mentions the number must not be blocked by it.
        $this->putJson("/api/v1/employee/shops/{$shop->id}", [
            'name' => $shop->name,
            'type' => $shop->type,
            'location_name' => 'HSR Layout',
            'latitude' => 12.91,
            'longitude' => 77.63,
        ])->assertOk()->assertJsonPath('data.whatsapp_number', '919876543210');

        // …and clearing it deliberately works too.
        $this->putJson("/api/v1/employee/shops/{$shop->id}", [
            'name' => $shop->name,
            'type' => $shop->type,
            'location_name' => 'HSR Layout',
            'latitude' => 12.91,
            'longitude' => 77.63,
            'whatsapp_number' => '',
        ])->assertOk()->assertJsonPath('data.whatsapp_number', null);

        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'whatsapp_number' => null]);
    }

    public function test_an_administrator_can_fill_in_the_number_of_an_imported_shop(): void
    {
        $admin = User::factory()->admin()->create();
        $shop = Shop::factory()->create(['whatsapp_number' => null]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/shops/{$shop->id}", [
            'whatsapp_number' => '+91 98765 43210',
        ])
            ->assertOk()
            ->assertJsonPath('data.whatsapp_number', '919876543210');

        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'whatsapp_number' => '919876543210']);

        // An empty value is not a number — the administrator either knows it
        // or leaves it for the executive who owns the shop.
        $this->putJson("/api/v1/admin/shops/{$shop->id}", ['whatsapp_number' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['whatsapp_number']);
    }

    public function test_only_an_administrator_may_use_the_admin_shop_endpoint(): void
    {
        $employee = User::factory()->create();
        $shop = Shop::factory()->create(['created_by' => $employee->id]);

        Sanctum::actingAs($employee);

        $this->putJson("/api/v1/admin/shops/{$shop->id}", [
            'whatsapp_number' => '9876543210',
        ])->assertForbidden();
    }

    public function test_the_normaliser_folds_every_common_shape(): void
    {
        $this->assertSame('919876543210', WhatsAppNumber::normalize('9876543210'));
        $this->assertSame('919876543210', WhatsAppNumber::normalize('+91 98765 43210'));
        $this->assertSame('919876543210', WhatsAppNumber::normalize('09876543210'));
        $this->assertSame('919876543210', WhatsAppNumber::normalize('0091 98765 43210'));
        $this->assertSame('919876543210', WhatsAppNumber::normalize('tel: 98765-43210'));
        $this->assertSame('447700900123', WhatsAppNumber::normalize('+44 7700 900123'));
        $this->assertNull(WhatsAppNumber::normalize(''));
        $this->assertNull(WhatsAppNumber::normalize(null));

        $this->assertNull(WhatsAppNumber::validationError('9876543210'));
        $this->assertNotNull(WhatsAppNumber::validationError('12345'));
        $this->assertNotNull(WhatsAppNumber::validationError('not a number'));
        $this->assertNotNull(WhatsAppNumber::validationError('12345678901234567'));
    }
}
