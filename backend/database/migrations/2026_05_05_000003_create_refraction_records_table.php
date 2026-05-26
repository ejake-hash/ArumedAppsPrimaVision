<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refraction_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->unique()->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('examined_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('examination_date')->nullable();
            $table->string('perception_type', 20)->nullable(); // DEKAT / JAUH

            // Autoref OD
            $table->decimal('autoref_od_sph', 5, 2)->nullable();
            $table->decimal('autoref_od_cyl', 5, 2)->nullable();
            $table->integer('autoref_od_axis')->nullable();
            // Autoref OS
            $table->decimal('autoref_os_sph', 5, 2)->nullable();
            $table->decimal('autoref_os_cyl', 5, 2)->nullable();
            $table->integer('autoref_os_axis')->nullable();

            // Keratometri OD
            $table->decimal('keratometri1_od', 5, 2)->nullable();
            $table->decimal('keratometri2_od', 5, 2)->nullable();
            $table->integer('keratometri_axis_od')->nullable();
            // Keratometri OS
            $table->decimal('keratometri1_os', 5, 2)->nullable();
            $table->decimal('keratometri2_os', 5, 2)->nullable();
            $table->integer('keratometri_axis_os')->nullable();

            // Visus OD
            $table->string('visus_awal_od', 20)->nullable();
            $table->string('visus_akhir_od', 20)->nullable();
            $table->string('pinhole_od', 20)->nullable();
            $table->decimal('add_power_od', 5, 2)->nullable();
            // Visus OS
            $table->string('visus_awal_os', 20)->nullable();
            $table->string('visus_akhir_os', 20)->nullable();
            $table->string('pinhole_os', 20)->nullable();
            $table->decimal('add_power_os', 5, 2)->nullable();

            // Refraksi Subjektif OD
            $table->decimal('refraksi_subjektif_od_sph', 5, 2)->nullable();
            $table->decimal('refraksi_subjektif_od_cyl', 5, 2)->nullable();
            $table->integer('refraksi_subjektif_od_axis')->nullable();
            // Refraksi Subjektif OS
            $table->decimal('refraksi_subjektif_os_sph', 5, 2)->nullable();
            $table->decimal('refraksi_subjektif_os_cyl', 5, 2)->nullable();
            $table->integer('refraksi_subjektif_os_axis')->nullable();

            // Kacamata Lama OD
            $table->decimal('old_glasses_od_sph', 5, 2)->nullable();
            $table->decimal('old_glasses_od_cyl', 5, 2)->nullable();
            $table->integer('old_glasses_od_axis')->nullable();
            $table->decimal('old_glasses_add_od', 5, 2)->nullable();
            // Kacamata Lama OS
            $table->decimal('old_glasses_os_sph', 5, 2)->nullable();
            $table->decimal('old_glasses_os_cyl', 5, 2)->nullable();
            $table->integer('old_glasses_os_axis')->nullable();
            $table->decimal('old_glasses_add_os', 5, 2)->nullable();

            // IOP
            $table->decimal('iop_od', 5, 2)->nullable();
            $table->decimal('iop_os', 5, 2)->nullable();
            $table->string('iop_method', 50)->nullable(); // NCT / Goldmann / Schiotz

            // Shared (bukan OD/OS)
            $table->decimal('pd_distance', 5, 2)->nullable();
            $table->text('clinical_notes')->nullable();

            // Finalisasi
            $table->boolean('is_finalized')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->foreignUuid('finalized_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('digital_signature', 500)->nullable();
            $table->timestamp('signature_timestamp')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refraction_records');
    }
};
