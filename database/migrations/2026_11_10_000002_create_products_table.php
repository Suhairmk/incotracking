<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('unit', 24)->default('pcs');

            // Integer minor units: order totals are sums of multiplications
            // and must not drift through binary floating point.
            $table->unsignedInteger('price_paise');

            // Never hard-deleted: orders reference a product for life, so the
            // catalogue deactivates instead of disappearing.
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);

            $table->index('is_active');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
