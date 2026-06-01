<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selaraskan room_tariffs ke pola "insurer-only" (sama dengan procedure/medication/
 * bhp/iol_tariffs yang sudah drop classification di 2026_05_26_000011).
 *
 * Identitas tarif kamar berubah dari (room_class, insurer_id, classification)
 * menjadi (room_class, insurer_id). UMUM/BPJS/SOSIAL = insurer sistem
 * (is_system=true) jadi classification redundant — informasi penjamin sudah
 * di insurer.type. getPrice('room') TIDAK pakai classification untuk lookup,
 * jadi billing aman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_tariffs', function (Blueprint $t) {
            $t->dropUnique('room_tariffs_room_class_insurer_id_classification_unique');
            $t->dropIndex('room_tariffs_classification_index');
            $t->dropColumn('classification');
        });
        Schema::table('room_tariffs', function (Blueprint $t) {
            $t->unique(['room_class', 'insurer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('room_tariffs', function (Blueprint $t) {
            $t->dropUnique(['room_class', 'insurer_id']);
        });
        Schema::table('room_tariffs', function (Blueprint $t) {
            $t->string('classification', 20)->default('UMUM')->after('insurer_id');
            $t->index('classification');
            $t->unique(['room_class', 'insurer_id', 'classification']);
        });
    }
};
