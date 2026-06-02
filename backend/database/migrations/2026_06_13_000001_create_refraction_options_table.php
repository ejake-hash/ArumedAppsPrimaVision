<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master OPSI REFRAKSI — sumber pilihan dropdown/combobox di RefraksionisView
     * (Autoref S/C/Axis, Keratometri K1/K2/ADD, Visus, Refraksi Subjektif).
     *
     * Satu baris = satu "kind" (kelompok field). Dua mode pengisian opsi:
     *   - mode='range' : opsi DI-GENERATE dari (min, max, step). Cocok untuk nilai
     *                    numerik berpola — Sphere/Cylinder (dioptri kelipatan 0.25),
     *                    Axis (0–180 kelipatan 5), Keratometri (30–60), ADD (0–4).
     *   - mode='list'  : opsi adalah daftar literal di `values` (JSON). Cocok untuk
     *                    Visus yang punya nilai non-numerik (6/6, HM, LP, NLP).
     *
     * `format` menentukan cara render label:
     *   - 'signed_diopter' → tampilkan tanda + untuk plus (mis. "+1.50", "-0.75").
     *   - 'plain'          → apa adanya.
     *
     * Admin RO bisa menyesuaikan rentang/step/daftar lewat UI Master Data tanpa
     * mengubah data refraksi lama (nilai tetap disimpan varchar di refraction_records;
     * combobox membolehkan nilai di luar daftar).
     */
    public function up(): void
    {
        Schema::create('refraction_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 40)->unique();          // sphere | cylinder | axis | keratometri | add | visus
            $table->string('label', 100);                  // label admin, mis. "Sphere (S)"
            $table->string('mode', 10)->default('range');  // range | list
            $table->string('format', 20)->default('plain'); // plain | signed_diopter

            // Mode RANGE
            $table->decimal('min_value', 8, 2)->nullable();
            $table->decimal('max_value', 8, 2)->nullable();
            $table->decimal('step', 6, 2)->nullable();

            // Mode LIST
            $table->json('values')->nullable();            // array of string (mis. ["6/6","HM","LP","NLP"])

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refraction_options');
    }
};
