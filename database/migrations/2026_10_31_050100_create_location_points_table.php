<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('attendance_id')->constrained('attendance')->cascadeOnDelete();
            $table->char('client_point_id', 26)->charset('ascii')->collation('ascii_bin');

            $table->integer('latitude_e7');
            $table->integer('longitude_e7');
            $table->unsignedSmallInteger('accuracy_meters');
            $table->unsignedSmallInteger('speed_cm_per_second')->nullable();
            $table->unsignedSmallInteger('heading_degrees')->nullable();

            $table->timestamp('recorded_at', 3);
            $table->timestamp('received_at', 3)->useCurrent();
            $table->string('quality_status', 12)->default('accepted');
            $table->string('rejection_reason', 40)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique(['employee_id', 'client_point_id']);
            $table->index(
                ['attendance_id', 'quality_status', 'recorded_at', 'id'],
                'location_points_route_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_points');
    }
};
