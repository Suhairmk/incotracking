<?php

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GST columns for newly placed orders plus the business date reports
     * group by. Existing rows are only annotated: their totals, subtotal
     * equal to the total and zero tax, are left exactly as they were.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('business_date')->nullable()->after('status');
            $table->unsignedBigInteger('subtotal_paise')->default(0)->after('total_paise');
            $table->unsignedBigInteger('tax_paise')->default(0)->after('subtotal_paise');
            $table->unsignedSmallInteger('tax_rate_bp')->default(0)->after('tax_paise');

            $table->index(['business_date', 'employee_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('line_tax_paise')->default(0)->after('line_total_paise');
            $table->unsignedSmallInteger('tax_rate_bp')->default(0)->after('line_tax_paise');
        });

        $timezone = BusinessTime::timezone();

        DB::table('orders')
            ->select(['id', 'created_at', 'total_paise'])
            ->whereNull('business_date')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($timezone): void {
                foreach ($orders as $order) {
                    DB::table('orders')->where('id', $order->id)->update([
                        'business_date' => CarbonImmutable::parse($order->created_at, 'UTC')
                            ->setTimezone($timezone)
                            ->toDateString(),
                        // Pre-GST orders keep their original calculation:
                        // the quoted total already is the grand total.
                        'subtotal_paise' => $order->total_paise,
                        'tax_paise' => 0,
                        'tax_rate_bp' => 0,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['line_tax_paise', 'tax_rate_bp']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['business_date', 'employee_id']);
            $table->dropColumn(['business_date', 'subtotal_paise', 'tax_paise', 'tax_rate_bp']);
        });
    }
};
