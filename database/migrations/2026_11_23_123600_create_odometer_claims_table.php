<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A field executive's own odometer reading for one work day, submitted
     * after the session ends and reviewed by an administrator.
     *
     * GPS distance stays authoritative on the attendance row; a claim only
     * adds the vehicle's reading alongside it so the two can be compared and
     * the difference approved or rejected. Approving never rewrites the GPS
     * track.
     */
    public function up(): void
    {
        Schema::create('odometer_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('attendance_id')->constrained('attendance')->cascadeOnDelete();
            $table->date('work_date');

            // The reading typed off the meter, in metres (45,321.7 km is
            // stored as 45321700) so it sits in the same unit family as
            // every other distance in the schema.
            $table->unsignedBigInteger('odometer_meters');

            // Snapshot of the last approved reading and the resulting
            // claimed distance; null on the very first claim of a vehicle.
            $table->unsignedBigInteger('previous_odometer_meters')->nullable();
            $table->unsignedBigInteger('claimed_distance_meters')->nullable();

            // GPS distance measured for the same session at submit time,
            // kept for side-by-side review even if the track is recalculated
            // later.
            $table->unsignedBigInteger('gps_distance_meters')->default(0);

            $table->string('status', 16)->default('pending');
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            // One claim per session; resubmission updates the same row.
            $table->unique('attendance_id');
            $table->index(['employee_id', 'work_date']);
            $table->index(['status', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odometer_claims');
    }
};
