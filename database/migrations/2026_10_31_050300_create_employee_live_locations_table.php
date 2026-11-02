<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_live_locations', function (Blueprint $table) {
            $table->foreignId('employee_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->foreignId('attendance_id')->constrained('attendance')->cascadeOnDelete();

            $table->integer('latitude_e7')->nullable();
            $table->integer('longitude_e7')->nullable();
            $table->unsignedSmallInteger('accuracy_meters')->nullable();
            $table->unsignedSmallInteger('speed_cm_per_second')->nullable();
            $table->timestamp('last_recorded_at', 3)->nullable();
            $table->timestamp('last_received_at', 3)->nullable();
            $table->timestamp('last_heartbeat_at', 3)->nullable();
            $table->string('tracking_state', 16)->default('unknown');
            $table->timestamp('updated_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_live_locations');
    }
};
