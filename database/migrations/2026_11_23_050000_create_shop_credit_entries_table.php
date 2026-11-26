<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();

            // opening | sale | sales_return | receipt | payment | adjustment
            $table->string('type', 16);

            // Signed: sales and opening balances add to the outstanding
            // amount, receipts and payments subtract from it.
            $table->bigInteger('amount_paise');

            $table->date('entry_date');
            $table->string('reference', 64)->nullable();
            $table->text('details')->nullable();

            // Order-generated rows point at the order that created them and
            // are read-only in the UI; cancelling the order reverses them.
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('source', 16)->default('manual'); // manual | order | import
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps(3);
            $table->softDeletes();

            $table->index(['shop_id', 'entry_date']);
            $table->index('order_id');
        });

        // Cached sum of the shop's non-deleted entries, kept in step by the
        // model so the directory never has to aggregate per row.
        Schema::table('shops', function (Blueprint $table) {
            $table->bigInteger('credit_balance_paise')->default(0)->after('gst_number');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('credit_balance_paise');
        });

        Schema::dropIfExists('shop_credit_entries');
    }
};
