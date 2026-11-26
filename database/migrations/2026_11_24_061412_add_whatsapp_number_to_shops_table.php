<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WhatsApp number of a shop or dealer, kept apart from the landline
 * that `phone` usually holds. Stored as canonical digits with the country
 * code (919876543210), which is exactly what the wa.me link and the
 * WhatsApp share intent need — no formatting to strip at send time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('whatsapp_number', 20)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_number');
        });
    }
};
