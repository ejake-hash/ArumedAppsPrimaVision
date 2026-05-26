<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refraction_prescriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('refraction_record_id')->unique()->constrained('refraction_records')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();

            // Rx OD
            $table->decimal('rx_od_sph', 5, 2)->nullable();
            $table->decimal('rx_od_cyl', 5, 2)->nullable();
            $table->integer('rx_od_axis')->nullable();
            $table->decimal('rx_od_add', 5, 2)->nullable();

            // Rx OS
            $table->decimal('rx_os_sph', 5, 2)->nullable();
            $table->decimal('rx_os_cyl', 5, 2)->nullable();
            $table->integer('rx_os_axis')->nullable();
            $table->decimal('rx_os_add', 5, 2)->nullable();

            $table->string('glasses_type', 100)->nullable();
            $table->string('lens_material', 100)->nullable();
            $table->string('coating', 100)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refraction_prescriptions');
    }
};
