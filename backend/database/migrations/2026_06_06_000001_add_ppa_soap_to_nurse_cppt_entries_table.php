<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CPPT terintegrasi multi-PPA (sesuai SNARS/STARKES PAP 2 & MIRM).
 *
 * Tabel nurse_cppt_entries semula berkesan "perawat-only" (TTV + notes bebas).
 * CPPT yang benar = Catatan Perkembangan Pasien TERINTEGRASI: semua PPA
 * (Dokter/DPJP, Perawat, Apoteker, Ahli Gizi, Fisioterapis) menulis di lembar
 * yang sama dengan format SOAP, lalu DPJP me-review/verifikasi.
 *
 * Semua kolom additive & nullable → entri lama (TTV+notes) tetap valid.
 *   - ppa_role     : kategori PPA penulis (di-derive dari employee.profession)
 *   - soap_s/o/a/p : format SOAP terstruktur (notes lama tetap dipertahankan)
 *   - instruksi    : kolom instruksi PPA standar CPPT
 *   - verified_*   : jejak review/verifikasi DPJP (non-blocking di MVP)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nurse_cppt_entries', function (Blueprint $table) {
            // Kategori PPA penulis: DOKTER|PERAWAT|APOTEKER|GIZI|FISIOTERAPIS|LAINNYA.
            // Di-derive dari employee.profession saat create (lihat Employee::ppaRole()).
            $table->string('ppa_role', 20)->nullable()->after('nurse_assessment_id');

            // notes lama (free-text) kini OPSIONAL — CPPT boleh SOAP-only.
            $table->text('notes')->nullable()->change();

            // SOAP terstruktur. notes lama (kolom 'notes') tetap ada untuk
            // backward-compat — entri lama tak punya SOAP terpisah.
            $table->text('soap_s')->nullable()->after('notes');
            $table->text('soap_o')->nullable()->after('soap_s');
            $table->text('soap_a')->nullable()->after('soap_o');
            $table->text('soap_p')->nullable()->after('soap_a');

            // Instruksi PPA (kolom instruksi pada lembar CPPT standar).
            $table->text('instruksi')->nullable()->after('soap_p');

            // Review/verifikasi DPJP. null = belum diverifikasi.
            $table->foreignUuid('verified_by_id')->nullable()->after('edited_by_id')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by_id');

            $table->index('ppa_role');
        });
    }

    public function down(): void
    {
        Schema::table('nurse_cppt_entries', function (Blueprint $table) {
            $table->dropForeign(['verified_by_id']);
            $table->dropIndex(['ppa_role']);
            $table->dropColumn([
                'ppa_role',
                'soap_s',
                'soap_o',
                'soap_a',
                'soap_p',
                'instruksi',
                'verified_by_id',
                'verified_at',
            ]);
        });

        // Kembalikan notes ke NOT NULL (isi null lama → '' agar tak gagal).
        DB::table('nurse_cppt_entries')->whereNull('notes')->update(['notes' => '']);
        Schema::table('nurse_cppt_entries', function (Blueprint $table) {
            $table->text('notes')->nullable(false)->change();
        });
    }
};
