<?php

namespace Database\Seeders;

use App\Models\BhpItem;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\UnitRequest;
use App\Models\UnitRequestItem;
use App\Models\UnitReturn;
use App\Models\UnitReturnItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed dummy data untuk Request & Retur dari Unit.
 *
 * Tujuan: populate inbox admin inventori dengan beberapa request + retur
 * status SUBMITTED dari berbagai stasiun (Triase, Refraksi, Bedah, dll)
 * supaya UI bell + modal langsung punya konten saat dibuka.
 *
 * Idempotent: cek by request_number / return_number sebelum create.
 */
class UnitRequestReturnSeeder extends Seeder
{
    public function run(): void
    {
        $items = $this->collectMasterItems();
        if (empty($items)) {
            $this->command?->warn('Skip UnitRequestReturnSeeder: tidak ada master item (Medication/BHP/IOL).');
            return;
        }

        $userId = User::query()->value('id');

        DB::transaction(function () use ($items, $userId) {
            $this->seedRequests($items, $userId);
            $this->seedReturns($items, $userId);
        });

        $this->command?->info('UnitRequestReturnSeeder selesai.');
    }

    /**
     * Kumpulkan item master apa saja yang tersedia.
     * @return array<int, array{type:string,id:string,name:string}>
     */
    private function collectMasterItems(): array
    {
        $out = [];

        foreach (Medication::limit(5)->get() as $m) {
            $out[] = ['type' => 'MEDICATION', 'id' => $m->id, 'name' => $m->name ?? 'Obat'];
        }
        foreach (BhpItem::limit(5)->get() as $b) {
            $out[] = ['type' => 'BHP', 'id' => $b->id, 'name' => $b->name ?? 'BHP'];
        }
        foreach (IolItem::limit(3)->get() as $i) {
            $name = trim(($i->brand ?? '') . ' ' . ($i->model ?? '')) ?: 'IOL';
            $out[] = ['type' => 'IOL', 'id' => $i->id, 'name' => $name];
        }

        return $out;
    }

    private function seedRequests(array $items, ?string $userId): void
    {
        // [station, items_count, status, days_ago, notes]
        $blueprints = [
            ['TRIASE',       2, 'SUBMITTED', 0, 'Stok kapas alkohol di unit triase menipis untuk shift sore.'],
            ['REFRAKSIONIS', 1, 'SUBMITTED', 0, 'Permintaan tetes mata untuk pemeriksaan refraksi.'],
            ['BEDAH',        3, 'SUBMITTED', 1, 'Persiapan operasi katarak besok pagi.'],
            ['DOKTER',       2, 'APPROVED',  2, 'Permintaan obat tetes untuk pasien rawat jalan poli mata.'],
            ['PENUNJANG',    1, 'SUBMITTED', 1, 'Reagen untuk pemeriksaan biometri.'],
        ];

        foreach ($blueprints as $idx => [$station, $cnt, $status, $daysAgo, $notes]) {
            $date = Carbon::now()->subDays($daysAgo);
            $number = $this->makeNumber('REQ', $date, $idx + 1);

            $req = UnitRequest::firstOrCreate(
                ['request_number' => $number],
                [
                    'requesting_station' => $station,
                    'request_date'       => $date->toDateString(),
                    'status'             => $status,
                    'notes'              => $notes,
                    'requested_by'       => $userId,
                    'approved_by'        => $status === 'APPROVED' ? $userId : null,
                    'approved_at'        => $status === 'APPROVED' ? $date->copy()->addHours(2) : null,
                    'created_at'         => $date,
                    'updated_at'         => $date,
                ]
            );

            if ($req->items()->exists()) continue; // already seeded

            for ($i = 0; $i < $cnt; $i++) {
                $pick = $items[($idx + $i) % count($items)];
                UnitRequestItem::create([
                    'unit_request_id' => $req->id,
                    'item_type'       => $pick['type'],
                    'item_id'         => $pick['id'],
                    'qty_requested'   => 5 + ($i * 3),
                    'qty_delivered'   => 0,
                    'batch_no'        => null,
                    'expiry_date'     => null,
                    'notes'           => null,
                ]);
            }
        }
    }

    private function seedReturns(array $items, ?string $userId): void
    {
        // [station, items_count, status, days_ago, reason, notes]
        $blueprints = [
            ['BEDAH',        2, 'SUBMITTED', 0, 'Sisa operasi tidak terpakai', 'Operasi katarak selesai lebih cepat, ada sisa BHP yang belum dibuka.'],
            ['REFRAKSIONIS', 1, 'SUBMITTED', 1, 'Salah ambil',                  'Petugas mengambil tetes mata yang salah jenis.'],
            ['FARMASI',      1, 'SUBMITTED', 0, 'Hampir expired',               'Stok tetes mata akan expired bulan depan, return ke gudang untuk relokasi.'],
        ];

        foreach ($blueprints as $idx => [$station, $cnt, $status, $daysAgo, $reason, $notes]) {
            $date = Carbon::now()->subDays($daysAgo);
            $number = $this->makeNumber('RET', $date, $idx + 1);

            $ret = UnitReturn::firstOrCreate(
                ['return_number' => $number],
                [
                    'returning_station' => $station,
                    'return_date'       => $date->toDateString(),
                    'status'            => $status,
                    'reason'            => $reason,
                    'notes'             => $notes,
                    'returned_by'       => $userId,
                    'created_at'        => $date,
                    'updated_at'        => $date,
                ]
            );

            if ($ret->items()->exists()) continue;

            for ($i = 0; $i < $cnt; $i++) {
                $pick = $items[($idx + $i + 1) % count($items)];
                UnitReturnItem::create([
                    'unit_return_id' => $ret->id,
                    'item_type'      => $pick['type'],
                    'item_id'        => $pick['id'],
                    'qty_returned'   => 2 + $i,
                    'batch_no'       => 'BATCH-DUMMY-' . ($idx + 1),
                    'expiry_date'    => Carbon::now()->addMonths(6)->toDateString(),
                    'condition'      => $idx === 2 ? 'NEAR_EXPIRY' : 'GOOD',
                    'notes'          => null,
                ]);
            }
        }
    }

    private function makeNumber(string $prefix, Carbon $date, int $seq): string
    {
        return sprintf('%s-%s-SEED%02d', $prefix, $date->format('Ym'), $seq);
    }
}
