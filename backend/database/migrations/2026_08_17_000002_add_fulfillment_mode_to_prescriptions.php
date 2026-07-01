<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prescriptions.fulfillment_mode — cara serah obat (dipisah dari `type`, yang
 * merangkap billing-skip + routing antrean + tab tujuan).
 *   DELIVER = Antar ke Kamar   (obat harian ranap: default; obat pulang: opsi baru)
 *   PICKUP  = Ambil di Farmasi (obat pulang: perilaku lama via loket)
 *   NULL    = data lama (interpretasi per konteks: RANAP=DELIVER, RAJAL=loket)
 *
 * prescriptions.charged_upfront — penanda "obat sudah ditagih saat discharge"
 * (obat pulang selalu tagih inpatient_charges di createObatPulang). Jalur serah
 * ranap (FarmasiService::serahRanapRequest) memakai flag ini untuk MELEWATI
 * penagihan ulang → anti-dobel saat obat pulang DELIVER diserahkan ke ruangan.
 *
 * Aditif & prod-safe: nullable / default false → tak mengubah perilaku data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('fulfillment_mode', 10)->nullable()->after('type')->index();
            $table->boolean('charged_upfront')->default(false)->after('fulfillment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_mode', 'charged_upfront']);
        });
    }
};
