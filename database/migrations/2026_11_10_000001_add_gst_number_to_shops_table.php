<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Optional: dealers and shops without GST registration still get
            // into the directory. Stored uppercase, ASCII keeps the index on
            // this fixed-width code cheap.
            $table->char('gst_number', 15)
                ->nullable()
                ->charset('ascii')
                ->collation('ascii_bin')
                ->after('notes');

            $table->index('gst_number');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropIndex(['gst_number']);
            $table->dropColumn('gst_number');
        });
    }
};
