<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            // Vehicle meter (odometer) readings captured alongside the
            // start/end selfies so fuel consumption can be reconciled per day.
            $table->string('start_meter_photo_path')->nullable()->after('start_photo_thumb_path');
            $table->string('start_meter_photo_thumb_path')->nullable()->after('start_meter_photo_path');
            $table->string('end_meter_photo_path')->nullable()->after('end_photo_thumb_path');
            $table->string('end_meter_photo_thumb_path')->nullable()->after('end_meter_photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn([
                'start_meter_photo_path',
                'start_meter_photo_thumb_path',
                'end_meter_photo_path',
                'end_meter_photo_thumb_path',
            ]);
        });
    }
};
