<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->string('name', 120);
            $table->string('type', 16)->default('shop');
            $table->string('location_name', 120);
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('notes')->nullable();

            // Same E7 integer convention as attendance and visits.
            $table->integer('latitude_e7');
            $table->integer('longitude_e7');
            $table->unsignedSmallInteger('accuracy_meters')->nullable();

            $table->timestamps(3);

            $table->index('name');
            $table->index('location_name');
            $table->index(['latitude_e7', 'longitude_e7']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
