<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GstNumberTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_GSTIN = '27AAPFU0939F1ZV';

    public function test_the_gst_number_is_optional_and_stored_uppercase(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $payload = [
            'name' => 'Sri Balaji Motors',
            'type' => 'dealer',
            'location_name' => 'Indiranagar',
            'whatsapp_number' => '9845012345',
            'latitude' => 12.9716,
            'longitude' => 77.5946,
        ];

        $withoutGst = $this->postJson('/api/v1/employee/shops', $payload)
            ->assertCreated()
            ->assertJsonPath('data.gst_number', null);

        $this->assertDatabaseHas('shops', ['id' => $withoutGst->json('data.id'), 'gst_number' => null]);

        $lowercase = $this->postJson('/api/v1/employee/shops', [
            ...$payload,
            'name' => 'Lower Case Shop',
            'gst_number' => ' 27aapfu0939f1zv ',
        ])->assertCreated()->assertJsonPath('data.gst_number', self::VALID_GSTIN);

        $this->assertDatabaseHas('shops', [
            'id' => $lowercase->json('data.id'),
            'gst_number' => self::VALID_GSTIN,
        ]);
    }

    public function test_a_bad_gst_number_is_rejected_with_a_specific_reason(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $base = [
            'name' => 'Bad GST Shop',
            'type' => 'shop',
            'location_name' => 'Whitefield',
            'whatsapp_number' => '9845012345',
            'latitude' => 12.9716,
            'longitude' => 77.5946,
        ];

        $this->postJson('/api/v1/employee/shops', [
            ...$base,
            'gst_number' => '27AAPFU0939F1ZW',
        ])->assertUnprocessable()->assertJsonValidationErrors(['gst_number']);

        $this->postJson('/api/v1/employee/shops', [
            ...$base,
            'gst_number' => '40ABCDE1234F1Z1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['gst_number']);

        $this->postJson('/api/v1/employee/shops', [
            ...$base,
            'gst_number' => 'not-a-gstin',
        ])->assertUnprocessable()->assertJsonValidationErrors(['gst_number']);

        $this->assertDatabaseCount('shops', 0);
    }

    public function test_a_repeated_gst_number_warns_but_never_blocks_a_save(): void
    {
        $employee = User::factory()->create();

        Shop::factory()->create([
            'created_by' => $employee->id,
            'name' => 'Head Office Outlet',
            'gst_number' => self::VALID_GSTIN,
        ]);

        Sanctum::actingAs($employee);

        // The lookup reports the collision…
        $this->getJson('/api/v1/employee/shops/gst-check?gst_number='.self::VALID_GSTIN)
            ->assertOk()
            ->assertJsonPath('data.gst_number', self::VALID_GSTIN)
            ->assertJsonCount(1, 'data.duplicates')
            ->assertJsonPath('data.duplicates.0.name', 'Head Office Outlet');

        // …but a second outlet sharing it still saves.
        $second = $this->postJson('/api/v1/employee/shops', [
            'name' => 'Branch Outlet',
            'type' => 'shop',
            'location_name' => 'Koramangala',
            'whatsapp_number' => '9845012345',
            'latitude' => 12.93,
            'longitude' => 77.62,
            'gst_number' => self::VALID_GSTIN,
        ])->assertCreated();

        $this->assertDatabaseHas('shops', ['id' => $second->json('data.id'), 'gst_number' => self::VALID_GSTIN]);

        // Editing the first shop ignores itself in the lookup.
        $first = Shop::query()->where('name', 'Head Office Outlet')->sole();
        $this->getJson('/api/v1/employee/shops/gst-check?gst_number='.self::VALID_GSTIN."&ignore_id={$first->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.duplicates')
            ->assertJsonPath('data.duplicates.0.name', 'Branch Outlet');

        // An invalid number never reaches the duplicate lookup.
        $this->getJson('/api/v1/employee/shops/gst-check?gst_number=27AAPFU0939F1ZW')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gst_number']);
    }

    public function test_the_directory_search_finds_a_shop_by_its_gst_number(): void
    {
        $employee = User::factory()->create();

        Shop::factory()->create([
            'created_by' => $employee->id,
            'name' => 'GST Indexed Traders',
            'gst_number' => self::VALID_GSTIN,
        ]);
        Shop::factory()->create([
            'created_by' => $employee->id,
            'name' => 'No GST Here',
            'gst_number' => null,
        ]);

        Sanctum::actingAs($employee);

        $this->getJson('/api/v1/employee/shops?q=27aapfu')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'GST Indexed Traders');

        $this->getJson('/api/v1/employee/shops?q='.self::VALID_GSTIN)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_editing_a_shop_can_clear_or_change_its_gst_number(): void
    {
        $employee = User::factory()->create();
        $shop = Shop::factory()->create([
            'created_by' => $employee->id,
            'gst_number' => self::VALID_GSTIN,
        ]);

        Sanctum::actingAs($employee);

        $this->putJson("/api/v1/employee/shops/{$shop->id}", [
            'name' => $shop->name,
            'type' => 'shop',
            'location_name' => 'HSR Layout',
            'latitude' => 12.91,
            'longitude' => 77.63,
            'gst_number' => '36ABCDE1234F1Z1',
        ])->assertOk()->assertJsonPath('data.gst_number', '36ABCDE1234F1Z1');

        $this->putJson("/api/v1/employee/shops/{$shop->id}", [
            'name' => $shop->name,
            'type' => 'shop',
            'location_name' => 'HSR Layout',
            'latitude' => 12.91,
            'longitude' => 77.63,
            'gst_number' => '',
        ])->assertOk()->assertJsonPath('data.gst_number', null);

        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'gst_number' => null]);
    }
}
