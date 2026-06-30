<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performa: di PostgreSQL FOREIGN KEY tidak otomatis ber-index. Audit 30 Jun 2026
 * menandai 6 kolom FK yang di-query langsung (where / updateOrCreate / agregat) namun
 * tanpa index → sequential scan yang tumbuh seiring data.
 *
 *   - bpjs_claims.visit_id                  (KlaimService: where + updateOrCreate per visit)
 *   - surgery_requests.surgery_schedule_id  (BedahService: daftar permintaan/supply per jadwal)
 *   - antrean_bookings.doctor_schedule_id   (AntrolMobileService: hitung kuota/sequence)
 *   - antrean_bookings.patient_id           (AntrolMobileService: lookup booking pasien)
 *   - visits.surgery_schedule_id            (BedahService: visit terkait jadwal bedah)
 *   - doctor_examinations.surgery_schedule_id
 *
 * Aditif & reversible — tidak mengubah data. Aman dijalankan kapan saja.
 */
return new class extends Migration
{
    /** @var array<string,array<int,string>> tabel => kolom */
    private array $targets = [
        'bpjs_claims'         => ['visit_id'],
        'surgery_requests'    => ['surgery_schedule_id'],
        'antrean_bookings'    => ['doctor_schedule_id', 'patient_id'],
        'visits'              => ['surgery_schedule_id'],
        'doctor_examinations' => ['surgery_schedule_id'],
    ];

    public function up(): void
    {
        foreach ($this->targets as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $columns) {
                foreach ($columns as $col) {
                    if (! Schema::hasColumn($table, $col)) {
                        continue;
                    }
                    $t->index($col, $this->indexName($table, $col));
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $columns) {
                foreach ($columns as $col) {
                    $t->dropIndex($this->indexName($table, $col));
                }
            });
        }
    }

    private function indexName(string $table, string $col): string
    {
        return "{$table}_{$col}_index";
    }
};
