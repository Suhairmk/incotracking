<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('end_photo_thumb_path');
            $table->timestamp('paused_device_recorded_at')->nullable()->after('paused_at');
            $table->string('paused_client_id', 26)->nullable()->after('paused_device_recorded_at');
            $table->timestamp('resumed_at')->nullable()->after('paused_client_id');
            $table->unsignedInteger('total_paused_seconds')->default(0)->after('resumed_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn([
                'paused_at',
                'paused_device_recorded_at',
                'paused_client_id',
                'resumed_at',
                'total_paused_seconds',
            ]);
        });
    }
};
