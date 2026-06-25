<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K3 — penutup siklus klaim: status verifikasi (get_claim_status), pelacakan
 * dispute/pending internal, dan rekonsiliasi pembayaran. Tidak ada API resmi
 * untuk umpan-balik kaya/nominal → dikelola internal/manual sampai SATUSEHAT Klaim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            // Status verifikasi kasar dari get_claim_status (mis. '40_Proses_Cabang').
            $table->string('verif_status_code', 20)->nullable()->after('dc_sent_at');
            $table->string('verif_status_name', 100)->nullable()->after('verif_status_code');
            $table->timestamp('verif_checked_at')->nullable()->after('verif_status_name');

            // Dispute & pending (kelola internal — Perdir BPJS 19/2023, BA 3760/2024).
            $table->string('jenis_dispute', 20)->nullable()->after('verif_checked_at'); // medis/koding/obat/cob
            $table->string('dispute_state', 20)->nullable()->after('jenis_dispute');     // PENDING/DISPUTE/SEPAKAT
            $table->string('bahv_no', 100)->nullable()->after('dispute_state');           // ref Berita Acara Hasil Verifikasi
            $table->text('pending_note')->nullable()->after('bahv_no');

            // Rekonsiliasi pembayaran (manual/import dari Berita Acara Pembayaran).
            $table->decimal('nominal_diajukan', 14, 2)->nullable()->after('pending_note');
            $table->decimal('nominal_disetujui', 14, 2)->nullable()->after('nominal_diajukan');
            $table->timestamp('paid_at')->nullable()->after('nominal_disetujui');
            $table->string('berita_acara_bayar_ref', 100)->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            $table->dropColumn([
                'verif_status_code', 'verif_status_name', 'verif_checked_at',
                'jenis_dispute', 'dispute_state', 'bahv_no', 'pending_note',
                'nominal_diajukan', 'nominal_disetujui', 'paid_at', 'berita_acara_bayar_ref',
            ]);
        });
    }
};
