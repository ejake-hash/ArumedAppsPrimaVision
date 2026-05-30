<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lengkapi bpjs_referrals_out dengan field yang dibutuhkan VClaim
     * insertRujukanKeluar (POST /Rujukan/2.0/insert):
     *   t_rujukan: noSep, tglRujukan, ppkDirujuk, jnsPelayanan, catatan,
     *              diagRujukan, tipeRujukan, poliRujukan, user.
     * Kolom faskes tujuan, diagnosa, catatan_rujukan, no_rujukan sudah ada.
     */
    public function up(): void
    {
        Schema::table('bpjs_referrals_out', function (Blueprint $table) {
            // Kode poli rujukan BPJS (referensi poli) = VClaim poliRujukan.
            $table->string('poli_rujukan', 20)->nullable()->after('kode_spesialis');
            $table->string('poli_rujukan_nama', 150)->nullable()->after('poli_rujukan');
            // tipeRujukan: 0=penuh, 1=partial, 2=rujuk balik.
            $table->string('tipe_rujukan', 5)->nullable()->after('poli_rujukan_nama');
            // jnsPelayanan: 1=R.Inap, 2=R.Jalan.
            $table->string('jns_pelayanan', 5)->nullable()->after('tipe_rujukan');
            $table->date('tgl_rujukan')->nullable()->after('jns_pelayanan');
        });
    }

    public function down(): void
    {
        Schema::table('bpjs_referrals_out', function (Blueprint $table) {
            $table->dropColumn([
                'poli_rujukan',
                'poli_rujukan_nama',
                'tipe_rujukan',
                'jns_pelayanan',
                'tgl_rujukan',
            ]);
        });
    }
};
