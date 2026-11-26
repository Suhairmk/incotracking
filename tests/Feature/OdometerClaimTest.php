<?php

namespace Tests\Feature;

use App\Enums\OdometerClaimStatus;
use App\Models\Attendance;
use App\Models\OdometerClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Odometer claims: an executive records the vehicle's own reading for a
 * session, the administrator compares it against the GPS distance the app
 * measured and approves or rejects it. Only approved readings count as
 * travelled kilometres, and approval never rewrites the GPS track.
 */
class OdometerClaimTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->employee = User::factory()->create();
    }

    private function sessionFor(User $employee, array $attributes = []): Attendance
    {
        return Attendance::factory()->completed()->create(array_merge([
            'employee_id' => $employee->id,
            'total_distance_meters' => 12_000,
        ], $attributes));
    }

    public function test_an_executive_submits_a_reading_for_their_own_session(): void
    {
        $attendance = $this->sessionFor($this->employee);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/v1/employee/attendance/{$attendance->id}/odometer", [
            'odometer_km' => 45321.7,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.odometer_meters', 45321700)
            ->assertJsonPath('data.gps_distance_meters', 12_000);

        $this->assertDatabaseHas('odometer_claims', [
            'attendance_id' => $attendance->id,
            'employee_id' => $this->employee->id,
            'status' => OdometerClaimStatus::Pending->value,
        ]);
    }

    public function test_a_second_reading_measures_distance_from_the_previous_approved_one(): void
    {
        $yesterday = $this->sessionFor($this->employee, ['work_date' => now()->subDay()->toDateString()]);
        $today = $this->sessionFor($this->employee, ['work_date' => now()->toDateString()]);

        OdometerClaim::factory()->approved()->create([
            'employee_id' => $this->employee->id,
            'attendance_id' => $yesterday->id,
            'work_date' => $yesterday->work_date,
            'odometer_meters' => 45_300_000,
            'gps_distance_meters' => 10_000,
        ]);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/v1/employee/attendance/{$today->id}/odometer", [
            'odometer_km' => 45_321.7,
        ])
            ->assertCreated()
            ->assertJsonPath('data.previous_odometer_meters', 45_300_000)
            ->assertJsonPath('data.claimed_distance_meters', 21_700);
    }

    public function test_a_reading_below_the_last_approved_one_is_refused(): void
    {
        $yesterday = $this->sessionFor($this->employee, ['work_date' => now()->subDay()->toDateString()]);
        $today = $this->sessionFor($this->employee, ['work_date' => now()->toDateString()]);

        OdometerClaim::factory()->approved()->create([
            'employee_id' => $this->employee->id,
            'attendance_id' => $yesterday->id,
            'work_date' => $yesterday->work_date,
            'odometer_meters' => 45_300_000,
        ]);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/v1/employee/attendance/{$today->id}/odometer", [
            'odometer_km' => 45_000,
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['message'])
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'lower than the last approved reading'));
    }

    public function test_an_executive_cannot_claim_for_a_colleagues_session(): void
    {
        $attendance = $this->sessionFor(User::factory()->create());

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/v1/employee/attendance/{$attendance->id}/odometer", [
            'odometer_km' => 45321.7,
        ])->assertForbidden();

        $this->assertDatabaseCount('odometer_claims', 0);
    }

    public function test_a_rejected_reading_can_be_corrected_but_an_approved_one_is_locked(): void
    {
        $attendance = $this->sessionFor($this->employee);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/v1/employee/attendance/{$attendance->id}/odometer", [
            'odometer_km' => 45321.7,
        ])->assertCreated();

        // The administrator rejects it with a reason.
        Sanctum::actingAs($this->admin);
        $claimId = OdometerClaim::firstOrFail()->id;
        $this->postJson("/api/v1/admin/odometer-claims/{$claimId}/reject", [
            'admin_notes' => 'Reading does not match the fuel log.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        // The executive corrects and resubmits — same row, back to pending.
        Sanctum::actingAs($this->employee);
        $this->postJson("/api/v1/employee/attendance/{$attendance->id}/odometer", [
            'odometer_km' => 45300.0,
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        // Once approved it is the baseline for every later claim and locked.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/odometer-claims/{$claimId}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by', $this->admin->id);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/v1/employee/attendance/{$attendance->id}/odometer", [
            'odometer_km' => 45400,
        ])->assertStatus(422);
    }

    public function test_rejecting_requires_a_note_and_only_an_administrator_reviews(): void
    {
        $attendance = $this->sessionFor($this->employee);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/v1/employee/attendance/{$attendance->id}/odometer", [
            'odometer_km' => 45321.7,
        ])->assertCreated();
        $claimId = OdometerClaim::firstOrFail()->id;

        // Field staff can neither approve nor reject, nor read the queue.
        $this->postJson("/api/v1/admin/odometer-claims/{$claimId}/approve", [])->assertForbidden();
        $this->postJson("/api/v1/admin/odometer-claims/{$claimId}/reject", [
            'admin_notes' => 'no',
        ])->assertForbidden();
        $this->getJson('/api/v1/admin/odometer-claims')->assertForbidden();

        // An approval without a note is fine; a rejection without one is not.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/odometer-claims/{$claimId}/reject", [])
            ->assertUnprocessable()->assertJsonValidationErrors('admin_notes');
    }

    public function test_the_review_queue_counts_claims_and_filters_by_status(): void
    {
        $pending = $this->sessionFor($this->employee);
        $other = User::factory()->create();
        $approvedSession = $this->sessionFor($other, ['work_date' => now()->subDay()->toDateString()]);
        OdometerClaim::factory()->approved()->create([
            'employee_id' => $other->id,
            'attendance_id' => $approvedSession->id,
            'work_date' => $approvedSession->work_date,
            'odometer_meters' => 100_000,
        ]);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/v1/employee/attendance/{$pending->id}/odometer", [
            'odometer_km' => 45321.7,
        ])->assertCreated();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/odometer-claims')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.counts.pending', 1)
            ->assertJsonPath('meta.counts.approved', 1);

        $this->getJson('/api/v1/admin/odometer-claims?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');

        // The executive sees only their own claims.
        Sanctum::actingAs($this->employee);
        $this->getJson('/api/v1/employee/odometer-claims')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_the_distance_report_shows_approved_odometer_kilometres_beside_gps(): void
    {
        $attendance = $this->sessionFor($this->employee);
        $claim = OdometerClaim::factory()->approved()->create([
            'employee_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'work_date' => $attendance->work_date,
            'odometer_meters' => 45_321_700,
            'claimed_distance_meters' => 15_500,
            'gps_distance_meters' => 12_000,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/reports/distance')
            ->assertOk()
            ->assertJsonPath('data.0.distance_meters', 12_000)
            ->assertJsonPath('data.0.odometer_meters', 15_500)
            ->assertJsonPath('meta.totals.odometer_meters', 15_500);

        // A pending claim is never counted as travelled kilometres.
        $claim->update(['status' => OdometerClaimStatus::Pending]);
        $this->getJson('/api/v1/admin/reports/distance')
            ->assertOk()
            ->assertJsonPath('data.0.odometer_meters', 0)
            ->assertJsonPath('meta.totals.odometer_meters', 0);
    }

    public function test_approving_an_older_claim_after_a_newer_one_rebases_both_distances(): void
    {
        $first = $this->sessionFor($this->employee, ['work_date' => now()->subDays(2)->toDateString()]);
        $second = $this->sessionFor($this->employee, ['work_date' => now()->subDay()->toDateString()]);

        $older = OdometerClaim::factory()->create([
            'employee_id' => $this->employee->id,
            'attendance_id' => $first->id,
            'work_date' => $first->work_date,
            'odometer_meters' => 45_300_000,
            'gps_distance_meters' => 9_000,
        ]);
        $newer = OdometerClaim::factory()->create([
            'employee_id' => $this->employee->id,
            'attendance_id' => $second->id,
            'work_date' => $second->work_date,
            'odometer_meters' => 45_330_000,
            'gps_distance_meters' => 11_000,
        ]);

        Sanctum::actingAs($this->admin);
        // The newer claim goes first: it has no approved baseline yet.
        $this->postJson("/api/v1/admin/odometer-claims/{$newer->id}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.claimed_distance_meters', null);

        // Approving the older one afterwards gives it a baseline of nothing
        // and hands the newer one its real 30 km.
        $this->postJson("/api/v1/admin/odometer-claims/{$older->id}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.claimed_distance_meters', null);

        $this->assertSame(null, $older->fresh()->claimed_distance_meters);
        $this->assertSame(30_000, $newer->fresh()->claimed_distance_meters);
    }
}
