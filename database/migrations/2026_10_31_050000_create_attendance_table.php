<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->date('work_date');

            $table->timestamp('start_time', 3);
            $table->timestamp('end_time', 3)->nullable();
            $table->timestamp('start_device_recorded_at', 3)->nullable();
            $table->timestamp('end_device_recorded_at', 3)->nullable();
            $table->string('start_device_id', 100)->nullable();
            $table->string('end_device_id', 100)->nullable();

            $table->char('start_client_id', 26)->charset('ascii')->collation('ascii_bin');
            $table->char('end_client_id', 26)->nullable()->charset('ascii')->collation('ascii_bin');

            $table->integer('start_latitude_e7');
            $table->integer('start_longitude_e7');
            $table->unsignedSmallInteger('start_accuracy');

            $table->integer('end_latitude_e7')->nullable();
            $table->integer('end_longitude_e7')->nullable();
            $table->unsignedSmallInteger('end_accuracy')->nullable();

            $table->string('start_photo_path')->nullable();
            $table->string('start_photo_thumb_path')->nullable();
            $table->string('end_photo_path')->nullable();
            $table->string('end_photo_thumb_path')->nullable();

            $table->unsignedInteger('total_distance_meters')->default(0);
            $table->unsignedInteger('total_working_seconds')->default(0);
            $table->unsignedSmallInteger('accepted_points_count')->default(0);
            $table->unsignedSmallInteger('rejected_points_count')->default(0);
            $table->unsignedSmallInteger('visits_count')->default(0);

            $table->timestamp('last_location_at', 3)->nullable();
            $table->timestamp('last_heartbeat_at', 3)->nullable();
            $table->string('tracking_state', 16)->default('unknown');
            $table->string('status', 16)->default('active');

            // Incremental distance calculation cursor.
            $table->unsignedBigInteger('last_processed_point_id')->nullable();
            $table->timestamp('last_processed_recorded_at', 3)->nullable();
            $table->integer('last_processed_latitude_e7')->nullable();
            $table->integer('last_processed_longitude_e7')->nullable();
            $table->unsignedSmallInteger('last_processed_accuracy')->nullable();
            $table->boolean('distance_needs_recalc')->default(false);

            $table->timestamps(3);

            $table->unique(['employee_id', 'work_date']);
            $table->unique(['employee_id', 'start_client_id']);
            $table->unique(['employee_id', 'end_client_id']);
            $table->index(['work_date', 'status']);
            $table->index(['status', 'last_heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
