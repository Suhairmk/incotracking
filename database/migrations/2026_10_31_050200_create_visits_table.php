<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('attendance_id')->constrained('attendance')->cascadeOnDelete();
            $table->char('client_visit_id', 26)->charset('ascii')->collation('ascii_bin');

            $table->string('customer_name', 120);
            $table->string('contact_person', 100)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('photo_thumb_path')->nullable();

            $table->integer('latitude_e7');
            $table->integer('longitude_e7');
            $table->unsignedSmallInteger('accuracy_meters');

            $table->timestamp('device_recorded_at', 3);
            $table->timestamp('server_recorded_at', 3);
            $table->text('remarks')->nullable();
            $table->string('outcome', 24);

            $table->timestamps(3);

            $table->unique(['employee_id', 'client_visit_id']);
            $table->index(['attendance_id', 'server_recorded_at']);
            $table->index(['employee_id', 'server_recorded_at']);
            $table->index('server_recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
