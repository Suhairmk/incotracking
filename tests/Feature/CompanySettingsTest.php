<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The company details that head every PDF and exported report: one row the
 * administrator edits, read back exactly as saved.
 */
class CompanySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_GSTIN = '27AAPFU0939F1ZV';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_header_defaults_to_the_application_name_before_anything_is_set(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/settings/company')
            ->assertOk()
            ->assertJsonPath('data.name', config('app.name'))
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.gstin', null);

        // Reading it is enough to materialise the row, and it does so once.
        $this->assertDatabaseCount('company_settings', 1);
    }

    public function test_an_administrator_saves_the_details_the_reports_are_headed_with(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/admin/settings/company', [
            'name' => 'Indu Components',
            'address' => 'Mavoor Road, Kozhikode, Kerala 673004',
            'phone' => '+91 495 272 1188',
            'email' => 'sales@inducomponents.in',
            'gstin' => self::VALID_GSTIN,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Indu Components')
            ->assertJsonPath('data.gstin', self::VALID_GSTIN);

        // The next read — from the PDF header — returns what was saved.
        $this->getJson('/api/v1/settings/company')
            ->assertOk()
            ->assertJsonPath('data.name', 'Indu Components')
            ->assertJsonPath('data.address', 'Mavoor Road, Kozhikode, Kerala 673004')
            ->assertJsonPath('data.email', 'sales@inducomponents.in');

        $this->assertDatabaseCount('company_settings', 1);
        $this->assertDatabaseHas('company_settings', [
            'name' => 'Indu Components',
            'gstin' => self::VALID_GSTIN,
        ]);
    }

    public function test_the_optional_fields_can_be_cleared_again(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/admin/settings/company', [
            'name' => 'Indu Components',
            'phone' => '0495 2721188',
        ])->assertOk()->assertJsonPath('data.phone', '0495 2721188');

        $this->putJson('/api/v1/admin/settings/company', [
            'name' => 'Indu Components',
            'phone' => null,
            'email' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.email', null);
    }

    public function test_the_name_is_required_and_the_gstin_must_check_out(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/admin/settings/company', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        // Right shape, wrong checksum digit.
        $this->putJson('/api/v1/admin/settings/company', [
            'name' => 'Indu Components',
            'gstin' => '27AAPFU0939F1ZW',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gstin');

        $this->putJson('/api/v1/admin/settings/company', [
            'name' => 'Indu Components',
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_field_staff_can_read_the_header_but_never_change_it(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        // Readable by everybody signed in: employees print the same headed
        // statements the administrator does.
        $this->getJson('/api/v1/settings/company')
            ->assertOk()
            ->assertJsonPath('data.name', config('app.name'));

        $this->putJson('/api/v1/admin/settings/company', ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('company_settings', ['name' => config('app.name')]);
    }
}
