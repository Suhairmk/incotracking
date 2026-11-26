<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One sales target per employee per calendar month. Achievement is
     * measured against delivered orders at their taxable value (ex-GST).
     */
    public function up(): void
    {
        Schema::create('monthly_sales_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            // First day of the target month, stored as a date so the index
            // and the unique key stay simple and timezone-independent.
            $table->date('month');
            $table->unsignedBigInteger('target_paise')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'month']);
            $table->index('month');
            $table->foreign('employee_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_sales_targets');
    }
};
