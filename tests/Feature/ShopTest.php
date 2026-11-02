<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_adds_a_shop_and_a_colleague_sees_it(): void
    {
        $author = User::factory()->create();
        $colleague = User::factory()->create();

        Sanctum::actingAs($author);

        $response = $this->postJson('/api/v1/employee/shops', [
            'name' => 'Sri Balaji Motors',
            'type' => 'dealer',
            'location_name' => 'Indiranagar',
            'address' => '100 ft Road, Bengaluru',
            'phone' => '9845012345',
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'accuracy_meters' => 14,
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Sri Balaji Motors');
        $this->assertDatabaseHas('shops', [
            'id' => $response->json('data.id'),
            'created_by' => $author->id,
            'type' => 'dealer',
        ]);

        // A different executive sees shops they did not create.
        Sanctum::actingAs($colleague);

        $this->getJson('/api/v1/employee/shops')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sri Balaji Motors')
            ->assertJsonPath('data.0.created_by.id', $author->id);
    }

    public function test_directory_search_matches_name_or_area_and_filters_by_type(): void
    {
        $employee = User::factory()->create();
        Shop::factory()->create([
            'created_by' => $employee->id,
            'name' => 'Alpha Distributors',
            'location_name' => 'Whitefield',
            'type' => 'dealer',
        ]);
        Shop::factory()->create([
            'created_by' => $employee->id,
            'name' => 'Beta Kirana Store',
            'location_name' => 'Indiranagar',
            'type' => 'shop',
        ]);

        Sanctum::actingAs($employee);

        // One term finds a shop by its name…
        $this->getJson('/api/v1/employee/shops?q=alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Distributors');

        // …or by the area it sits in, for either row.
        $this->getJson('/api/v1/employee/shops?q=indira')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Kirana Store');

        $this->getJson('/api/v1/employee/shops?q=whitefield')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Distributors');

        $this->getJson('/api/v1/employee/shops?type=dealer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'dealer');

        $this->getJson('/api/v1/employee/shops?q=beta&type=shop')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_nearby_search_orders_by_distance_and_respects_the_radius(): void
    {
        $employee = User::factory()->create();
        $originLatitude = 12.9716;
        $originLongitude = 77.5946;

        $neighbourhood = Shop::factory()->create([
            'created_by' => $employee->id,
            'name' => 'Corner Shop',
            'latitude_e7' => (int) round(($originLatitude + 0.001) * 10_000_000),
            'longitude_e7' => (int) round($originLongitude * 10_000_000),
        ]);
        $nextDoor = Shop::factory()->create([
            'created_by' => $employee->id,
            'name' => 'Next Door Dealer',
            'latitude_e7' => (int) round(($originLatitude + 0.0001) * 10_000_000),
            'longitude_e7' => (int) round($originLongitude * 10_000_000),
        ]);
        // Roughly 35 km away: outside a 1 km radius.
        Shop::factory()->create([
            'created_by' => $employee->id,
            'name' => 'Far Away Traders',
            'latitude_e7' => (int) round(($originLatitude + 0.31) * 10_000_000),
            'longitude_e7' => (int) round($originLongitude * 10_000_000),
        ]);

        Sanctum::actingAs($employee);

        $response = $this->getJson(
            "/api/v1/employee/shops?latitude={$originLatitude}&longitude={$originLongitude}&radius_km=1"
        )->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertSame(['Next Door Dealer', 'Corner Shop'], $names->all());
        $this->assertNotNull($response->json('data.0.distance_meters'));
        $this->assertLessThan(1000, $response->json('data.0.distance_meters'));

        // Widening the radius brings the distant shop in, still ordered by distance.
        $wide = $this->getJson(
            "/api/v1/employee/shops?latitude={$originLatitude}&longitude={$originLongitude}&radius_km=100"
        )->assertOk();

        $this->assertSame(3, $wide->json('meta.total'));
        $this->assertGreaterThan(
            $wide->json('data.1.distance_meters'),
            $wide->json('data.2.distance_meters')
        );
        $this->assertSame($originLatitude, $wide->json('meta.latitude'));
        $this->assertNotNull($wide->json('meta.radius_km'));
    }

    public function test_mine_filter_and_edit_permissions_follow_the_creator(): void
    {
        $author = User::factory()->create();
        $colleague = User::factory()->create();

        $own = Shop::factory()->create(['created_by' => $author->id, 'name' => 'Own Shop']);
        Shop::factory()->create(['created_by' => $colleague->id, 'name' => 'Other Shop']);

        Sanctum::actingAs($author);

        $this->getJson('/api/v1/employee/shops?mine=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Own Shop');

        $this->putJson("/api/v1/employee/shops/{$own->id}", [
            'name' => 'Own Shop Renamed',
            'type' => 'shop',
            'location_name' => 'HSR Layout',
            'latitude' => 12.91,
            'longitude' => 77.63,
        ])->assertOk()->assertJsonPath('data.name', 'Own Shop Renamed');

        $other = Shop::query()->where('name', 'Other Shop')->sole();
        $this->putJson("/api/v1/employee/shops/{$other->id}", [
            'name' => 'Hijacked',
            'type' => 'shop',
            'location_name' => 'HSR Layout',
            'latitude' => 12.91,
            'longitude' => 77.63,
        ])->assertForbidden();

        $this->deleteJson("/api/v1/employee/shops/{$other->id}")->assertForbidden();
        $this->deleteJson("/api/v1/employee/shops/{$own->id}")->assertOk();
        $this->assertDatabaseMissing('shops', ['id' => $own->id]);
    }

    public function test_admin_sees_every_shop_with_attribution_and_moderates(): void
    {
        $author = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $first = Shop::factory()->create(['created_by' => $author->id, 'name' => 'First Shop']);
        $second = Shop::factory()->create(['created_by' => $admin->id, 'name' => 'Second Shop']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/shops')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.created_by.id', $author->id);

        $this->getJson('/api/v1/admin/shops?created_by='.$author->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'First Shop');

        $this->getJson("/api/v1/admin/shops/{$second->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Second Shop');

        // An administrator moderates shops created by anyone.
        $this->deleteJson("/api/v1/employee/shops/{$first->id}")->assertForbidden();
        $this->deleteJson("/api/v1/admin/shops/{$first->id}")->assertOk();
        $this->assertDatabaseMissing('shops', ['id' => $first->id]);
    }

    public function test_shop_validation_is_enforced(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $this->postJson('/api/v1/employee/shops', [
            'name' => '',
            'type' => 'warehouse',
            'location_name' => '',
            'latitude' => 999,
            'longitude' => 77.5946,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'type', 'location_name', 'latitude']);

        // The shared directory is readable without creating anything.
        $this->getJson('/api/v1/employee/shops')->assertOk()->assertJsonCount(0, 'data');
    }
}
