<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Administrators hide a shop from the field directory (a lead being
        // worked centrally, a duplicate entry, a closed outlet). Hidden rows
        // stay fully visible to administrators.
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('is_visible_to_field')
                ->default(true)
                ->after('credit_balance_paise');
        });

        // Which executives may open which shop — the directory list, the
        // order form and the credit ledger all read this one list.
        Schema::create('shop_employee_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps(3);

            $table->unique(['shop_id', 'employee_id']);
            $table->index('employee_id');
        });

        // Shops that predate this table were shared with the whole team, so
        // every current executive keeps seeing them. Shops added from now on
        // are granted explicitly, one staff member at a time.
        $shopIds = DB::table('shops')->pluck('id');
        $employeeIds = DB::table('users')->where('role', 'employee')->pluck('id');

        $rows = [];
        foreach ($shopIds as $shopId) {
            foreach ($employeeIds as $employeeId) {
                $rows[] = [
                    'shop_id' => $shopId,
                    'employee_id' => $employeeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows !== []) {
            DB::table('shop_employee_access')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_employee_access');

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('is_visible_to_field');
        });
    }
};
