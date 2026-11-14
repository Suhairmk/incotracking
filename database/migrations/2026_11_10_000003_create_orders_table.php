<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Assigned immediately after the row exists so the number embeds
            // the auto-increment id: unique without a sequence table.
            $table->string('order_number', 32)->nullable()->unique();

            // ULID minted on the device: the same offline order re-uploaded
            // after a timeout is recognised instead of duplicated.
            $table->char('client_order_id', 26)
                ->charset('ascii')
                ->collation('ascii_bin')
                ->unique();

            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();

            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('total_paise')->default(0);
            $table->text('notes')->nullable();

            $table->timestamp('device_created_at');
            $table->timestamp('server_recorded_at');

            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps(3);

            $table->index(['status', 'created_at']);
            $table->index(['employee_id', 'created_at']);
            $table->index('shop_id');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Snapshots: renaming or repricing the catalogue later must not
            // rewrite what the agent actually sold.
            $table->string('product_name', 120);
            $table->string('unit', 24);
            $table->unsignedInteger('unit_price_paise');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('line_total_paise');

            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
