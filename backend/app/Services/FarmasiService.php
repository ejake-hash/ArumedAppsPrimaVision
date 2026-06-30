<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\InventoryStock;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\MedicationSaleUnit;
use App\Models\PharmacySaleItem;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Queue;
use App\Models\SurgeryRequest;
use App\Models\SurgeryRequestBhp;
use App\Models\SurgeryRequestIol;
use App\Models\SystemLog;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use App\Models\VisitBhpUsage;
use App\Services\QueueService;
use App\Services\InventoryStockService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FarmasiService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
        private readonly InventoryStockService $stockService,
    ) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    public function getPatientQueue(): Collection
    {
        return Queue::with([
            'visit.patient',
            // Relasi DPJP untuk kartu identitas pasien (badge DPJP) — eager-load
            // agar accessor dpjp_name bebas N+1 (RANAP=dpjp, RAJAL/IGD=pemeriksa/jadwal).
            'visit.dpjp',
            'visit.doctorExamination.doctor',
            'visit.doctorSchedule.employee',
            'visit.prescriptions' => fn ($q) => $q
                // Resep PERMINTAAN rawat inap (type RANAP) di-dispense ke ruangan lewat
                // tab "Dispensing Rawat Inap", BUKAN antrean loket ini — jangan ikut load
                // agar pickActiveRx FE tak salah mengangkatnya saat pasien RANAP discharge.
                ->where('type', '!=', Prescription::TYPE_RANAP),
            // BHP dipakai dokter yang BELUM diserahkan (consumed_batches NULL) → tampil di
            // kartu dispensing; stoknya dipotong saat petugas menyerahkan (selesaiAntrian).
            'visit.bhpUsages' => fn ($q) => $q->whereNull('consumed_batches')->with('bhpItem'),
        ])
            ->where('station', 'FARMASI')
            ->boardVisibleOpenBilling()   // +pasien belum tutup kasir (Masih Aktif)
            ->whereHas('visit')   // exclude zombie row (visit soft-deleted)
            ->orderBy('queue_sequence')
            ->get()
            ->each(fn ($q) => $q->visit?->append('dpjp_name'));
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_FARMASI)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Lewati pasien Farmasi yang tidak hadir → tukar urutan dengan pasien
     * berikutnya (turun 1). Pola sama dengan stasiun lain (Kasir/Perawat/dst).
     */
    public function lewatiAntrian(string $queueId): Queue
    {
        Queue::byStation(Queue::STATION_FARMASI)->findOrFail($queueId);
        return $this->queueService->lewati($queueId);
    }

    /**
     * Preview harga obat tambahan (di luar resep) untuk satu visit, sesuai
     * penjamin pasien — SUMBER HARGA YANG SAMA dengan yang ditagih kasir
     * (KasirService::getPrice → medication_tariffs per-insurer), BUKAN HJA POS.
     *
     * Untuk visit RANAP/IGD obat ditagih lewat inpatient_charges (bukan resep),
     * jadi tandai billed_via='RANAP' agar UI bisa memberi catatan yang benar.
     *
     * @return array{unit_price: float, billed_via: string, guarantor_type: ?string}
     */
    public function previewHargaObat(string $medicationId, ?string $visitId): array
    {
        $kasir = app(KasirService::class);

        $guarantor = 'UMUM';
        $insurerId = null;
        $billedVia = 'INVOICE';

        if ($visitId) {
            $visit = Visit::find($visitId);
            if ($visit) {
                $guarantor = $visit->guarantor_type ?: 'UMUM';
                $insurerId = $visit->insurer_id;
                // RANAP/IGD: obat masuk inpatient_charges, bukan invoice resep.
                if (in_array($visit->visit_type, ['RAWAT_INAP', 'IGD'], true)) {
                    $billedVia = $visit->visit_type === 'IGD' ? 'IGD' : 'RANAP';
                }
            }
        }

        $price = $kasir->getPrice('medication', $medicationId, $guarantor, $insurerId);

        return [
            'unit_price'     => (float) $price,
            'billed_via'     => $billedVia,
            'guarantor_type' => $guarantor,
        ];
    }

    /**
     * Selesai antrian Farmasi → pasien PULANG (current_station = SELESAI).
     * Section 11.3 step 6.
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::byStation(Queue::STATION_FARMASI)->findOrFail($queueId);

        // Saat pasien meninggalkan FARMASI: potong stok BHP yang dipakai dokter pada
        // kunjungan ini (ditunda dari saat input dokter → "serah" di sini, sejajar obat).
        // Dijalankan SEBELUM advance — bila stok BHP kurang, penutupan antrean batal
        // (422) dan pasien tetap di antrean sampai stok dicukupi.
        if ($queue->visit_id) {
            $this->consumePendingVisitBhp($queue->visit_id);
        }

        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_FARMASI);
    }

    /**
     * Potong stok unit FARMASI untuk seluruh BHP dokter pada kunjungan yang BELUM
     * diserahkan (consumed_batches NULL). Idempoten — BHP yang sudah dipotong dilewati,
     * jadi aman bila selesaiAntrian terpanggil ulang. consume() melempar 422 bila stok
     * kurang (mirror serah obat) → tandai 422 agar penutupan antrean batal & petugas
     * diminta transfer stok dari gudang dulu.
     */
    private function consumePendingVisitBhp(string $visitId): void
    {
        $pending = VisitBhpUsage::with('bhpItem')
            ->where('visit_id', $visitId)
            ->whereNull('consumed_batches')
            ->get();
        if ($pending->isEmpty()) {
            return;
        }

        $employeeId = auth('api')->user()?->employee_id;

        DB::transaction(function () use ($pending, $employeeId) {
            foreach ($pending as $usage) {
                $qty = max(1, (int) $usage->quantity);
                try {
                    $consumed = $this->stockService->consume(
                        InventoryStock::TYPE_BHP, $usage->bhp_item_id, (float) $qty, InventoryStock::LOC_FARMASI
                    );
                } catch (\Throwable $e) {
                    // Perhalus HANYA kasus kurang-stok (422) → tampilkan NAMA BHP, bukan
                    // sekadar "item BHP". Error lain (mis. kegagalan DB) diteruskan apa adanya.
                    $code = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                        ? $e->getStatusCode() : (int) $e->getCode();
                    if ($code !== 422) {
                        throw $e;
                    }
                    $name = $usage->bhpItem?->name ?? $usage->bhp_item_id;
                    throw new \Exception(
                        "Stok BHP \"{$name}\" di unit FARMASI tidak mencukupi untuk diserahkan (butuh {$qty}). Minta transfer dari gudang dulu.",
                        422
                    );
                }
                // Tandai sudah diserahkan: consumed_batches terisi (sentinel bila tanpa
                // rincian batch) → tak akan dipotong ulang pada panggilan berikutnya.
                //
                // Serah fisik = de-facto verifikasi: stamp verified_at bila petugas tak
                // sempat "Verifikasi & Kunci BHP" sebelum menutup antrean. WAJIB, sebab
                // worklist verifikasi memfilter consumed_batches NULL — BHP yang sudah
                // diserah TAK akan pernah bisa diverifikasi lewat UI lagi. Tanpa stamp ini,
                // BHP consumed+belum-verif (a) tak ditagih (buildBhpLines hanya menagih
                // verified → kebocoran) dan (b) memblok gate Kasir assertObatVerified secara
                // tak-terlihat → tagihan DEADLOCK tanpa jalan keluar.
                $usage->update([
                    'consumed_batches' => $consumed ?: [['qty' => $qty]],
                    'verified_at'      => $usage->verified_at ?? now(),
                    'verified_by_id'   => $usage->verified_by_id ?? $employeeId,
                ]);
            }
        });

        $this->log(auth('api')->id(), 'SERAH_BHP_FARMASI', Visit::class, $visitId, "Serah BHP dokter — {$pending->count()} item, stok FARMASI dipotong");
    }

    // =========================================================================
    // RESEP OBAT
    // =========================================================================

    public function getPrescriptions(array $filters = []): LengthAwarePaginator
    {
        $query = Prescription::with(['visit.patient', 'prescribedBy', 'items.medication'])
            // Daftar resep loket = rawat jalan + obat pulang (type RAJAL). Permintaan
            // obat rawat inap (type RANAP) punya daftarnya sendiri (getRanapRequests).
            ->where('type', '!=', Prescription::TYPE_RANAP)
            ->whereDate('created_at', $filters['tanggal'] ?? today());

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->whereHas('visit.patient', fn ($q) => $q
                ->where('name', 'ilike', "%{$keyword}%")
                ->orWhere('no_rm', 'ilike', "%{$keyword}%")
            );
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 20);
    }

    public function getPrescriptionById(string $id): Prescription
    {
        $rx = Prescription::with([
            'visit.patient',
            // DPJP untuk kartu identitas pasien (lihat getPatientQueue).
            'visit.dpjp',
            'visit.doctorExamination.doctor',
            'visit.doctorSchedule.employee',
            'prescribedBy',
            'dispensedBy',
            'items.medication',
        ])->findOrFail($id);
        $rx->visit?->append('dpjp_name');

        return $rx;
    }

    /**
     * DRAFT → DISPENSING (mulai proses dispensing).
     */
    public function startDispensing(string $prescriptionId): Prescription
    {
        $prescription = Prescription::findOrFail($prescriptionId);

        if (! in_array($prescription->status, ['DRAFT', 'SUBMITTED'])) {
            throw new \Exception('Resep tidak dalam status yang bisa diproses.', 422);
        }

        // Defensif: resep RAJAL hanya boleh disiapkan/diserahkan setelah diverifikasi
        // & dikunci Farmasi (gate utama ada di Kasir; ini lapis kedua agar tak ada
        // resep belum-verifikasi yang lolos ke dispensing).
        if ($prescription->type !== Prescription::TYPE_RANAP && is_null($prescription->verified_at)) {
            throw new \Exception('Resep belum diverifikasi Farmasi — verifikasi & kunci dulu sebelum menyiapkan obat.', 422);
        }

        $prescription->update(['status' => 'DISPENSING']);

        $this->log(auth('api')->id(), 'START_DISPENSING', Prescription::class, $prescriptionId);

        // BPJS Antrol (Sisi A): daftarkan antrean farmasi + task 6 (mulai buat obat).
        // Non-blocking — skip diam-diam bila bukan BPJS / ANTREAN nonaktif.
        $visit = $prescription->visit;
        $this->queueService->reportAntreanFarmasiAdd($visit);
        $this->queueService->reportTask($visit, 6);

        return $prescription->fresh(['items.medication']);
    }

    /**
     * DISPENSING → DISPENSED: kurangi stok obat per item.
     */
    public function selesaiDispensing(string $prescriptionId): Prescription
    {
        $prescription = Prescription::with('items.medication')->findOrFail($prescriptionId);

        if ($prescription->status !== 'DISPENSING') {
            throw new \Exception('Resep harus dalam status DISPENSING sebelum diselesaikan.', 422);
        }

        $this->assertStockSufficient($prescription);

        $user = auth('api')->user();

        DB::transaction(function () use ($prescription, $user) {
            $this->consumePrescriptionStock($prescription);

            $prescription->update([
                'status'          => 'DISPENSED',
                'dispensed_by_id' => $user->employee_id,
                'dispensed_at'    => now(),
            ]);
        });

        $this->log(
            $user->id,
            'SELESAI_DISPENSING',
            Prescription::class,
            $prescriptionId,
            "Resep diselesaikan — {$prescription->items->count()} item obat"
        );

        // BPJS Antrol task 7 = akhir obat selesai dibuat (Docs/Antrol.md:346).
        // Non-blocking — guard monoton memastikan urut 6 → 7.
        $this->queueService->reportTask($prescription->visit, 7);

        return $prescription->fresh(['items.medication', 'dispensedBy']);
    }

    public function cancelResep(string $prescriptionId): Prescription
    {
        $prescription = Prescription::findOrFail($prescriptionId);

        if (in_array($prescription->status, ['DISPENSED'])) {
            throw new \Exception('Resep yang sudah diselesaikan tidak bisa dibatalkan.', 422);
        }

        $prescription->update(['status' => 'CANCELLED']);

        $this->log(auth('api')->id(), 'CANCEL_RESEP', Prescription::class, $prescriptionId);

        return $prescription->fresh();
    }

    // -------------------------------------------------------------------------
    // VERIFIKASI FARMASI (gate sebelum tagihan Kasir) — alur D→K→F
    //
    // Resep dokter (SUBMITTED) muncul di worklist "Perlu Verifikasi". Farmasi
    // menyunting (substitusi/qty/tambah/hapus + alasan) lalu "Verifikasi & Kunci"
    // (set verified_at). Kasir BARU bisa membuat tagihan setelah resep terverifikasi
    // (consolidateBilling gate). Tujuan: tagihan = obat yang benar-benar diserahkan.
    // -------------------------------------------------------------------------

    /**
     * Worklist verifikasi: resep RAJAL berstatus SUBMITTED (belum & sudah diverifikasi,
     * dibedakan via verified_at). Tiap item dilampiri estimasi harga sesuai penjamin
     * (SUMBER SAMA dgn kasir: KasirService::getPrice) supaya farmasi bisa kelola
     * over-budget BPJS sebelum mengunci.
     *
     * Cakupan tanggal:
     *  - filter `tanggal` eksplisit → hanya resep di tanggal itu.
     *  - default (tanpa filter) → resep HARI INI + SEMUA resep yang BELUM diverifikasi
     *    (verified_at NULL) lintas-hari. Sebab resep belum-verified MENGHAMBAT tagihan
     *    Kasir (gate consolidateBilling) — bila hilang dari worklist keesokan hari,
     *    tagihan buntu tanpa jalan keluar. (Resep lama sudah di-backfill verified_at
     *    saat migrasi → tak ikut membanjiri.)
     */
    public function getVerificationQueue(array $filters = []): Collection
    {
        $rows = Prescription::with([
                'visit.patient', 'prescribedBy', 'verifiedBy', 'items.medication', 'items.saleUnit',
                // Untuk accessor dpjp_name (bebas N+1): RANAP=dpjp, RAJAL/IGD=pemeriksa/jadwal.
                'visit.dpjp', 'visit.doctorExamination.doctor', 'visit.doctorSchedule.employee',
                // Bedakan badge "Pasca Bedah" vs "Pasca Tindakan" (klasifikasiAsalResep, bebas N+1).
                'visit.surgerySchedule:id,location_type',
            ])
            ->where('type', '!=', Prescription::TYPE_RANAP)
            ->where('status', 'SUBMITTED')
            // Tahan resep POLI selama pasien MASIH menunggu OPERASI (Ruang Bedah) yang belum
            // selesai agar resep poli tak nyangkut di Verifikasi sebelum prosedur usai —
            // pasien hanya mengambil obat SEKALI setelah operasi. Resep pasca-bedah
            // (is_post_op=true) DIKECUALIKAN dari penahanan ini: ia memang ditulis SETELAH
            // operasi, jadi harus selalu bisa diverifikasi. Tanpa pengecualian ini, revisi
            // obat pasca-bedah (yang me-reset verified_at) bisa TERJEBAK tak terlihat Farmasi
            // bila visit punya surgery_schedule berstatus SCHEDULED/IN_PROGRESS (mis. jadwal
            // ganda/stale yang dibuat setelah operasi) → obat hilang dari kwitansi & tak bisa
            // dipulihkan. whereDoesntHave lolos otomatis utk visit tanpa surgery_schedule &
            // utk status DONE/CANCELLED (operasi batal → obat poli tetap perlu diverifikasi).
            // HOLD HANYA utk RUANG_BEDAH (operasi). Tindakan laser (RUANG_TINDAKAN) TIDAK
            // ditahan: laser same-day & ringan, resep konsultasinya harus langsung bisa
            // diverifikasi Farmasi seperti resep poli biasa (jadwalnya kini selalu ada krn
            // default tanggal hari ini → tanpa carve-out ini resep laser ikut terjebak hold).
            // location_type null (jadwal lama) dianggap RUANG_BEDAH (backward-compat).
            //
            // RILIS PAKSA bila pasien SUDAH LEWAT Bedah (current_station = FARMASI/KASIR/
            // SELESAI/MENUNGGU_RANAP/RANAP): operasi pasti sudah usai (pasien dirutekan keluar
            // OK), jadi tahanan tak boleh berlaku lagi walau status jadwal MASIH SCHEDULED/
            // IN_PROGRESS karena data jadwal basi (jadwal ganda/stale yang tak ter-set DONE).
            // Tanpa ini, resep poli pasien yang operasinya selesai TERJEBAK tak terlihat Farmasi
            // selamanya → gate Kasir (assertObatVerified) buntu "menunggu verifikasi" tanpa
            // jalan keluar (akar bug: jadwal SCHEDULED basi walau time_out sudah terisi).
            ->where(fn ($q) => $q
                ->where('is_post_op', true)
                ->orWhereHas('visit', fn ($vq) => $vq
                    ->whereIn('current_station', ['FARMASI', 'KASIR', 'SELESAI', 'MENUNGGU_RANAP', 'RANAP']))
                ->orWhereDoesntHave('visit.surgerySchedule', fn ($s) => $s
                    ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
                    ->where(fn ($w) => $w
                        ->where('location_type', SurgerySchedule::LOCATION_RUANG_BEDAH)
                        ->orWhereNull('location_type'))))
            ->when(
                ! empty($filters['tanggal']),
                fn ($q) => $q->whereDate('created_at', $filters['tanggal']),
                // Default (tanpa filter tanggal) — resep tetap di worklist bila:
                //  (1) dibuat HARI INI (alur normal hari berjalan), ATAU
                //  (2) BELUM diverifikasi kapan pun (pekerjaan tertunda — selalu tampil), ATAU
                //  (3) sudah diverifikasi TAPI KASIR BELUM TUTUP (tak ada invoice
                //      PAID/PARTIALLY_PAID untuk kunjungan itu) DAN verifikasi masih ≤ H+7.
                // Klausa (3) mencegah resep BACK-DATED (kunjungan kemarin) LENYAP dari
                // worklist begitu dikunci — petugas tetap bisa melacak/dispense sampai
                // tagihannya benar-benar dibayar (akar keluhan "resep hilang setelah
                // verifikasi & kunci"). Batas H+7 (dari tgl verifikasi) = jaring agar
                // resep yang tagihannya tak kunjung tutup tak menumpuk selamanya;
                // sesudahnya tetap bisa dibuka via filter tanggal.
                fn ($q) => $q->where(fn ($w) => $w
                    ->whereDate('created_at', today())
                    ->orWhereNull('verified_at')
                    ->orWhere(fn ($v) => $v
                        ->whereNotNull('verified_at')
                        ->whereDate('verified_at', '>=', today()->subDays(7))
                        ->whereHas('visit', fn ($vq) => $vq
                            ->whereDoesntHave('billingInvoice', fn ($i) => $i
                                ->whereIn('status', ['PAID', 'PARTIALLY_PAID']))))),
            )
            ->when(! empty($filters['search']), fn ($q) => $q->whereHas('visit.patient', fn ($p) => $p
                ->where('name', 'ilike', "%{$filters['search']}%")
                ->orWhere('no_rm', 'ilike', "%{$filters['search']}%")))
            ->orderByRaw('verified_at IS NOT NULL')   // belum diverifikasi dulu
            ->orderBy('created_at')
            ->get();

        // REVISI pasca-tagih: bila visit sudah punya invoice aktif (DRAFT/FINALIZED, belum
        // CANCELLED), resep unverified di antrean ini = revisi dokter setelah Kirim ke Kasir
        // → butuh verifikasi ulang. Cek per-visit dalam 1 query (hindari N+1).
        $visitIds = $rows->pluck('visit_id')->filter()->unique()->values()->all();
        $visitsWithInvoice = $visitIds
            ? \App\Models\BillingInvoice::whereIn('visit_id', $visitIds)
                ->where('status', '!=', 'CANCELLED')
                ->pluck('visit_id')->flip()
            : collect();

        // Varian kemasan jual per obat — utk dropdown FE + estimasi harga kemasan.
        // 1 query batch (medication unik lintas resep), dipetakan per resep di bawah.
        $allMedIds = $rows->flatMap(fn ($rx) => $rx->items->pluck('medication_id'))->filter()->unique()->values();
        $saleUnitsByMed = $allMedIds->isEmpty()
            ? collect()
            : MedicationSaleUnit::whereIn('medication_id', $allMedIds)
                ->where('is_active', true)
                ->get()
                ->groupBy('medication_id');

        $kasir = app(KasirService::class);
        $rows->each(function ($rx) use ($kasir, $visitsWithInvoice, $saleUnitsByMed) {
            $guarantor = $rx->visit?->guarantor_type ?: 'UMUM';
            $insurerId = $rx->visit?->insurer_id;

            // Harga kemasan utk item ber-sale_unit (resolusi penjamin pasien, batch).
            $kemasanItems = $rx->items->whereNotNull('sale_unit_id')->filter(fn ($it) => $it->saleUnit);
            $kemasanPrices = $kemasanItems->isEmpty() ? [] : $kasir->resolveSaleUnitPrices(
                $kemasanItems->map(fn ($it) => [
                    'medication_id'    => $it->medication_id,
                    'label'            => $it->saleUnit->label,
                    'fallback_unit_id' => $it->sale_unit_id,
                ])->values()->all(),
                $guarantor,
                $insurerId
            );

            $total = 0.0;
            foreach ($rx->items as $it) {
                if ($it->sale_unit_id && $it->saleUnit && $it->sale_unit_qty > 0) {
                    // Item ber-kemasan: estimasi per KEMASAN (selaras buildObatLines).
                    $key = $it->medication_id . '|' . mb_strtolower($it->saleUnit->label);
                    $it->est_unit_price  = $kemasanPrices[$key] ?? 0.0;
                    $it->est_total_price = $it->est_unit_price * (int) $it->sale_unit_qty;
                } else {
                    $price = 0.0;
                    try {
                        $price = (float) $kasir->getPrice('medication', $it->medication_id, $guarantor, $insurerId);
                    } catch (\Throwable $e) {
                        $price = 0.0;
                    }
                    $it->est_unit_price  = $price;
                    $it->est_total_price = $price * (float) $it->quantity;
                }
                // Daftar kemasan tersedia utk dropdown FE (label + isi + harga indikatif).
                // Dedup per label: baris insurer pasien menang atas baris NULL "semua".
                $byLabel = [];
                foreach ($saleUnitsByMed->get($it->medication_id, collect()) as $u) {
                    if ($u->insurer_id !== null && $u->insurer_id !== $insurerId) continue;
                    $k = mb_strtolower($u->label);
                    if (! isset($byLabel[$k]) || ($u->insurer_id === $insurerId && $byLabel[$k]->insurer_id === null)) {
                        $byLabel[$k] = $u;
                    }
                }
                $it->available_sale_units = collect(array_values($byLabel))
                    ->sortBy('label')->values()
                    ->map(fn ($u) => ['id' => $u->id, 'label' => $u->label, 'isi' => (int) $u->isi, 'price' => (float) $u->price]);
                $total += $it->est_total_price;
            }
            $rx->est_total = $total;
            // Tagihan sudah ada tapi resep ini belum diverifikasi → revisi pasca-kirim.
            $rx->is_revision = is_null($rx->verified_at) && $visitsWithInvoice->has($rx->visit_id);

            // Badge asal resep (Rawat Jalan / Rawat Inap / Pasca Bedah / RAJAL & Pasca Bedah / IGD)
            // + DPJP, agar Farmasi tahu konteks pasien saat verifikasi.
            [$rx->jenis_kode, $rx->sumber] = $this->klasifikasiAsalResep($rx->visit, $rx);
            $rx->visit?->append('dpjp_name');
        });

        // Lampirkan BHP dokter (belum diserahkan) ke visit tiap kartu → Farmasi verifikasi
        // BHP berbarengan dgn resep. Batch 1 query (hindari N+1).
        $this->attachVisitBhpUsages($rows);

        return $rows;
    }

    /** Tempel BHP dokter belum-diserahkan ke visit tiap resep (utk verifikasi BHP di kartu). */
    private function attachVisitBhpUsages(Collection $rows): void
    {
        $visitIds = $rows->pluck('visit_id')->filter()->unique()->values();
        if ($visitIds->isEmpty()) {
            return;
        }
        $byVisit = VisitBhpUsage::with('bhpItem')
            ->whereIn('visit_id', $visitIds)
            ->whereNull('consumed_batches')   // belum diserahkan
            ->orderBy('created_at')
            ->get()
            ->groupBy('visit_id');
        $rows->each(function ($rx) use ($byVisit) {
            if ($rx->visit) {
                $rx->visit->setRelation('bhpUsages', $byVisit->get($rx->visit_id, collect())->values());
            }
        });
    }

    /**
     * Visit dengan BHP dokter BELUM-VERIF & belum diserahkan yang TIDAK punya resep di
     * worklist verifikasi → kartu BHP-only. Tanpa ini, pasien injeksi/prosedur tanpa
     * resep obat tak bisa diverifikasi & gate tagihan BHP (assertObatVerified) buntu.
     *
     * Tab Verifikasi = HANYA rawat jalan/IGD (FARMASI/KASIR/SELESAI). BHP pasien RAWAT
     * INAP (MENUNGGU_RANAP/RANAP) SENGAJA dikecualikan dari sini dan dipindah ke tab
     * "Dispensing Rawat Inap" (getRanapBhpVisits) → satu pintu Farmasi per pasien ranap,
     * tak lagi tercampur antrean pra-Kasir rajal.
     */
    public function getBhpOnlyVerificationVisits(array $excludeVisitIds = [], array $filters = []): Collection
    {
        return $this->bhpVisitsByStations(['FARMASI', 'KASIR', 'SELESAI'], $excludeVisitIds, $filters);
    }

    /**
     * BHP pasien RAWAT INAP (station MENUNGGU_RANAP/RANAP) yang belum diverifikasi —
     * ditampilkan di tab "Dispensing Rawat Inap" bersama permintaan obat ranap pasien
     * yang sama. Tagihan tetap lewat jalur kwitansi BHP (verified_at gate); di sini
     * Farmasi cukup verifikasi+serah dalam konteks ranap.
     */
    public function getRanapBhpVisits(array $filters = []): Collection
    {
        return $this->bhpVisitsByStations(['MENUNGGU_RANAP', 'RANAP'], [], $filters);
    }

    /**
     * Helper bersama: visit dengan BHP belum-verif & belum diserahkan pada station
     * tertentu. KASIR/SELESAI = titik gate tagihan rajal (aman dari buntu); RANAP =
     * konteks rawat inap. Lihat getBhpOnlyVerificationVisits & getRanapBhpVisits.
     */
    private function bhpVisitsByStations(array $stations, array $excludeVisitIds, array $filters): Collection
    {
        $visitIds = VisitBhpUsage::whereNull('consumed_batches')
            ->whereNull('verified_at')
            ->when($excludeVisitIds, fn ($q) => $q->whereNotIn('visit_id', $excludeVisitIds))
            ->distinct()->pluck('visit_id');
        if ($visitIds->isEmpty()) {
            return new Collection();
        }

        $visits = Visit::with([
                'patient', 'dpjp', 'doctorExamination.doctor', 'doctorSchedule.employee',
                'surgerySchedule:id,location_type',
                'bhpUsages' => fn ($q) => $q->whereNull('consumed_batches')->with('bhpItem')->orderBy('created_at'),
            ])
            ->whereIn('id', $visitIds)
            ->whereIn('current_station', $stations)
            ->when(! empty($filters['search']), fn ($q) => $q->whereHas('patient', fn ($p) => $p
                ->where('name', 'ilike', "%{$filters['search']}%")
                ->orWhere('no_rm', 'ilike', "%{$filters['search']}%")))
            ->get();
        $visits->each(fn ($v) => $v->append('dpjp_name'));

        return $visits;
    }

    /** Verifikasi & kunci SEMUA BHP dokter belum-verif pada kunjungan (mirror verifyPrescription). Idempoten. */
    public function verifyVisitBhp(string $visitId): Collection
    {
        $user = auth('api')->user();
        $pending = VisitBhpUsage::where('visit_id', $visitId)
            ->whereNull('consumed_batches')
            ->whereNull('verified_at')
            ->get();
        if ($pending->isNotEmpty()) {
            VisitBhpUsage::whereIn('id', $pending->pluck('id'))
                ->update(['verified_at' => now(), 'verified_by_id' => $user?->employee_id]);
            $this->log($user?->id, 'VERIFY_BHP', Visit::class, $visitId, "Verifikasi & kunci {$pending->count()} BHP dokter");
            // Pasca-verif: bila invoice belum-bayar sudah ada, rebuild agar BHP masuk kwitansi.
            $this->reconsolidateVisitInvoiceIfAny($visitId);
        }
        return $this->visitBhpUndispensed($visitId);
    }

    /** Buka kunci verifikasi BHP (koreksi sebelum bayar). Tolak bila invoice sudah dibuat. */
    public function unverifyVisitBhp(string $visitId): Collection
    {
        $adaInvoice = \App\Models\BillingInvoice::where('visit_id', $visitId)
            ->whereNotIn('status', ['CANCELLED'])->exists();
        if ($adaInvoice) {
            throw new \Exception('Tagihan sudah dibuat di Kasir — batalkan invoice dulu sebelum membuka kunci verifikasi BHP.', 422);
        }
        $user = auth('api')->user();
        VisitBhpUsage::where('visit_id', $visitId)
            ->whereNull('consumed_batches')
            ->whereNotNull('verified_at')
            ->update(['verified_at' => null, 'verified_by_id' => null]);
        $this->log($user?->id, 'UNVERIFY_BHP', Visit::class, $visitId, 'Buka kunci verifikasi BHP (koreksi sebelum bayar)');
        return $this->visitBhpUndispensed($visitId);
    }

    /** Farmasi ubah qty satu BHP saat verifikasi (belum dikunci & belum diserahkan). */
    public function updateBhpUsageQty(string $id, int $qty): VisitBhpUsage
    {
        $usage = VisitBhpUsage::with('bhpItem')->findOrFail($id);
        $this->assertBhpEditable($usage);
        $usage->update(['quantity' => max(1, $qty)]);
        $this->log(auth('api')->id(), 'UPDATE_BHP_FARMASI', Visit::class, $usage->visit_id, "Farmasi ubah qty BHP {$usage->bhp_item_id} → " . max(1, $qty));
        $this->reconsolidateVisitInvoiceIfAny($usage->visit_id);
        return $usage->fresh('bhpItem');
    }

    /** Farmasi hapus satu BHP saat verifikasi (wajib alasan). Stok belum dipotong → soft-delete. */
    public function removeBhpUsage(string $id, ?string $reason = null): void
    {
        $usage = VisitBhpUsage::findOrFail($id);
        $this->assertBhpEditable($usage);
        $visitId = $usage->visit_id;
        $note = $reason ? mb_substr($reason, 0, 200) : null;
        if ($note) {
            $usage->update(['notes' => $note]);
        }
        $usage->delete();
        $this->log(auth('api')->id(), 'DELETE_BHP_FARMASI', Visit::class, $visitId, 'Farmasi hapus BHP saat verifikasi' . ($note ? " — {$note}" : ''));
        $this->reconsolidateVisitInvoiceIfAny($visitId);
    }

    /** BHP boleh disunting/dihapus Farmasi HANYA saat fase verifikasi: belum dikunci & belum diserahkan. */
    private function assertBhpEditable(VisitBhpUsage $usage): void
    {
        if ($usage->consumed_batches) {
            throw new \Exception('BHP sudah diserahkan — tak bisa diubah/dihapus.', 422);
        }
        if (! is_null($usage->verified_at)) {
            throw new \Exception('BHP sudah dikunci — buka kunci verifikasi dulu sebelum mengubah/menghapus.', 422);
        }
    }

    private function visitBhpUndispensed(string $visitId): Collection
    {
        return VisitBhpUsage::with('bhpItem')
            ->where('visit_id', $visitId)
            ->whereNull('consumed_batches')
            ->orderBy('created_at')
            ->get();
    }

    private function reconsolidateVisitInvoiceIfAny(string $visitId): void
    {
        try {
            $invoice = \App\Models\BillingInvoice::where('visit_id', $visitId)
                ->whereIn('status', ['DRAFT', 'FINALIZED'])->first();
            if ($invoice) {
                app(KasirService::class)->reconsolidateInvoice($invoice->id);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('reconsolidate invoice pasca aksi BHP gagal: ' . $e->getMessage(), ['visit_id' => $visitId]);
        }
    }

    /** SUBMITTED (belum verified) → set verified_at/by. Idempoten (dobel-klik aman). */
    public function verifyPrescription(string $prescriptionId): Prescription
    {
        $prescription = Prescription::findOrFail($prescriptionId);

        if ($prescription->type === Prescription::TYPE_RANAP) {
            throw new \Exception('Permintaan rawat inap tidak melalui verifikasi loket.', 422);
        }
        if ($prescription->status !== 'SUBMITTED') {
            throw new \Exception('Hanya resep yang sudah dikirim dokter (status "Siap diverifikasi") yang bisa diverifikasi.', 422);
        }
        if ($prescription->verified_at) {
            return $prescription->fresh(['items.medication', 'verifiedBy']);   // sudah terverifikasi
        }

        $user = auth('api')->user();
        $prescription->update([
            'verified_at'    => now(),
            'verified_by_id' => $user?->employee_id,
        ]);
        $this->log($user?->id, 'VERIFY_RESEP', Prescription::class, $prescriptionId, 'Resep diverifikasi & dikunci Farmasi');

        // Verifikasi ulang pasca revisi dokter: bila tagihan belum-bayar sudah ada,
        // bangun ulang agar obat yang baru dikunci ini masuk kembali ke kwitansi.
        // FINALIZED ikut (bukan hanya DRAFT): pembayaran ter-unblock pasca re-verify
        // (gate assertObatVerified) — tanpa rebuild, total invoice basi terhadap
        // revisi qty/kemasan. reconsolidateInvoice aman utk FINALIZED (rebuild in-place).
        // No-op bila belum ada invoice (alur normal: kasir konsolidasi belakangan).
        try {
            $invoice = \App\Models\BillingInvoice::where('visit_id', $prescription->visit_id)
                ->whereIn('status', ['DRAFT', 'FINALIZED'])
                ->first();
            if ($invoice) {
                app(KasirService::class)->reconsolidateInvoice($invoice->id);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('reconsolidate pasca verifikasi gagal: ' . $e->getMessage(), ['prescription_id' => $prescriptionId]);
        }

        return $prescription->fresh(['items.medication', 'verifiedBy']);
    }

    /**
     * Buka kunci verifikasi (untuk koreksi SEBELUM tagihan dibuat). Ditolak bila
     * invoice sudah dibuat di Kasir — batalkan invoice dulu (cegah desync tagihan).
     */
    public function unverifyPrescription(string $prescriptionId): Prescription
    {
        $prescription = Prescription::findOrFail($prescriptionId);

        if (is_null($prescription->verified_at)) {
            return $prescription->fresh(['items.medication']);   // sudah terbuka
        }

        $adaInvoice = \App\Models\BillingInvoice::where('visit_id', $prescription->visit_id)
            ->whereNotIn('status', ['CANCELLED'])
            ->exists();
        if ($adaInvoice) {
            throw new \Exception('Tagihan sudah dibuat di Kasir — batalkan invoice dulu sebelum membuka kunci verifikasi.', 422);
        }

        $user = auth('api')->user();
        $prescription->update(['verified_at' => null, 'verified_by_id' => null]);
        $this->log($user?->id, 'UNVERIFY_RESEP', Prescription::class, $prescriptionId, 'Buka kunci verifikasi resep (koreksi sebelum bayar)');

        return $prescription->fresh(['items.medication']);
    }

    /**
     * Resep boleh disunting hanya pada FASE VERIFIKASI (belum dikunci) dan belum
     * diserahkan. Mengunci perubahan obat setelah verifikasi/tagihan — cegah kebocoran
     * (tambah obat tak tertagih / batal tak di-refund pasca-bayar).
     */
    private function assertResepEditable(Prescription $prescription): void
    {
        if ($prescription->status === 'DISPENSED') {
            throw new \Exception('Resep sudah diselesaikan, tidak bisa diubah.', 422);
        }
        if ($prescription->verified_at !== null) {
            throw new \Exception('Resep sudah diverifikasi & dikunci. Buka kunci verifikasi dulu (sebelum tagihan dibuat) untuk mengubah.', 422);
        }
    }

    // -------------------------------------------------------------------------
    // Stok helper (dipakai dispensing rawat jalan & rawat inap)

    /**
     * Cek kecukupan stok unit FARMASI (per-batch FEFO via inventory_stocks, BUKAN
     * kolom legacy medications.stock) untuk semua item resep. Pengecekan di luar
     * transaksi → pesan jelas lebih awal. Lempar 422 bila kurang (minta transfer).
     */
    private function assertStockSufficient(Prescription $prescription): void
    {
        foreach ($prescription->items as $item) {
            if (! $item->medication) {
                continue;
            }
            $onHand = $this->stockService->onHand('MEDICATION', $item->medication_id, InventoryStock::LOC_FARMASI);
            if ($onHand < $item->quantity) {
                throw new \Exception(
                    "Stok unit FARMASI untuk {$item->medication->name} tidak mencukupi. Tersedia: {$onHand}, dibutuhkan: {$item->quantity}. Minta transfer dari gudang dulu.",
                    422
                );
            }
        }
    }

    /**
     * Potong stok unit FARMASI per item (FEFO, per-batch). consume() throw 422
     * bila stok berubah & jadi tak cukup (race). WAJIB dipanggil dalam transaksi.
     */
    private function consumePrescriptionStock(Prescription $prescription): void
    {
        foreach ($prescription->items as $item) {
            if ($item->medication) {
                $this->stockService->consume('MEDICATION', $item->medication_id, (float) $item->quantity, InventoryStock::LOC_FARMASI);
            }
        }
    }

    // =========================================================================
    // DISPENSING RAWAT INAP — permintaan obat pasien dirawat (type RANAP)
    //
    // Alur terpisah dari antrean loket: perawat/dokter di ward membuat permintaan
    // (RanapService::createMedicationRequest → Prescription type RANAP, status
    // SUBMITTED), farmasi men-Siapkan lalu Serah ke ruangan. Saat serah: potong
    // stok unit FARMASI + tagih tiap item ke inpatient_charges (RanapService::
    // addObat) → ikut invoice discharge. buildObatLines sudah skip RANAP (anti-dobel).
    // =========================================================================

    /**
     * Daftar permintaan obat rawat inap yang perlu dilayani farmasi: belum serah
     * (SUBMITTED/DISPENSING) + yang sudah DISPENSED hari ini (riwayat singkat).
     */
    public function getRanapRequests(): Collection
    {
        $rows = Prescription::with([
            'visit.patient',
            'visit.room',
            'visit.bed',
            // Pengkajian klinis: sumber alergi pasien (allergy_notes persisten + asesmen
            // perawat bila ada triase). Untuk inap tanpa triase, andalkan allergy_notes.
            'visit.nurseAssessment:id,visit_id,has_allergy,allergy_detail',
            'prescribedBy',
            'dispensedBy',
            'verifiedBy',
            'items.medication',
        ])
            ->where('type', Prescription::TYPE_RANAP)
            ->where(function ($q) {
                $q->whereIn('status', ['SUBMITTED', 'DISPENSING'])
                    ->orWhere(fn ($w) => $w->where('status', 'DISPENSED')->whereDate('dispensed_at', today()));
            })
            ->orderByRaw("CASE status WHEN 'DISPENSING' THEN 0 WHEN 'SUBMITTED' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->get();

        // Flag DUPLIKASI obat: medication_id yang muncul di >1 permintaan AKTIF
        // (SUBMITTED/DISPENSING) pada kunjungan yang sama → bantu apoteker mendeteksi
        // peresepan ganda lintas-permintaan. Disisipkan sbg atribut transien per resep.
        foreach ($rows->groupBy('visit_id') as $group) {
            $active = $group->whereIn('status', ['SUBMITTED', 'DISPENSING']);
            $counts = $active->flatMap(fn ($p) => $p->items->pluck('medication_id'))
                ->countBy();
            $dupIds = $counts->filter(fn ($c) => $c > 1)->keys()->values();
            foreach ($group as $p) {
                $p->setAttribute('duplicate_medication_ids', $dupIds->all());
            }
        }

        return $rows;
    }

    /**
     * SUBMITTED → DISPENSING (mulai siapkan). Tanpa lapor Antrol — tak ada antrean Farmasi.
     *
     * Titik PENGKAJIAN RESEP (Permenkes 72/2016 & PKPO 5.1): obat ranap tidak melewati
     * tab Verifikasi pra-Kasir, jadi telaah resep (administratif/farmasetik/klinis) oleh
     * apoteker dilakukan DI SINI sebelum obat disiapkan. Jejak audit direkam dengan
     * MENGISI verified_at/verified_by_id (kolom yang sama dengan gate rajal; untuk RANAP
     * maknanya "apoteker telah mengkaji resep"). Idempoten bila sudah terisi.
     */
    public function startRanapDispensing(string $prescriptionId): Prescription
    {
        $prescription = Prescription::where('type', Prescription::TYPE_RANAP)->findOrFail($prescriptionId);

        if (! in_array($prescription->status, ['SUBMITTED', 'DRAFT'])) {
            throw new \Exception('Permintaan tidak dalam status yang bisa disiapkan.', 422);
        }

        $prescription->update([
            'status'         => 'DISPENSING',
            'verified_at'    => $prescription->verified_at ?? now(),
            'verified_by_id' => $prescription->verified_by_id ?? auth('api')->user()?->employee_id,
        ]);
        $this->log(auth('api')->id(), 'START_RANAP_DISPENSING', Prescription::class, $prescriptionId, 'Pengkajian resep ranap oleh apoteker → mulai siapkan');

        return $prescription->fresh(['items.medication', 'verifiedBy', 'visit.patient', 'visit.room', 'visit.bed']);
    }

    /**
     * DISPENSING → DISPENSED: potong stok unit FARMASI + tagih ke inpatient_charges
     * (harga getPrice per penjamin) untuk tiap item sesuai qty AKTUAL yang diserahkan.
     */
    public function serahRanapRequest(string $prescriptionId): Prescription
    {
        $prescription = Prescription::with(['items.medication', 'visit'])
            ->where('type', Prescription::TYPE_RANAP)
            ->findOrFail($prescriptionId);

        if ($prescription->status !== 'DISPENSING') {
            throw new \Exception('Permintaan harus dalam status "Disiapkan" sebelum diserahkan.', 422);
        }
        if (! $prescription->visit) {
            throw new \Exception('Data kunjungan rawat inap tidak ditemukan.', 422);
        }

        $this->assertStockSufficient($prescription);

        $user  = auth('api')->user();
        $ranap = app(RanapService::class);

        DB::transaction(function () use ($prescription, $user, $ranap) {
            // 1. Potong stok unit FARMASI (FEFO per-batch).
            $this->consumePrescriptionStock($prescription);

            // 2. Tagih tiap item ke inpatient_charges OBAT (qty aktual = qty item).
            foreach ($prescription->items as $item) {
                if ($item->medication) {
                    $ranap->addObat($prescription->visit, $item->medication_id, (float) $item->quantity);
                }
            }

            $prescription->update([
                'status'          => 'DISPENSED',
                'dispensed_by_id' => $user->employee_id,
                'dispensed_at'    => now(),
            ]);
        });

        $this->log(
            $user->id,
            'SERAH_RANAP_OBAT',
            Prescription::class,
            $prescriptionId,
            "Obat rawat inap diserahkan ke ruangan — {$prescription->items->count()} item"
        );

        return $prescription->fresh(['items.medication', 'dispensedBy', 'visit.patient', 'visit.room', 'visit.bed']);
    }

    /** Tolak/batal permintaan rawat inap (sebelum serah). Tanpa stok/charge. */
    public function tolakRanapRequest(string $prescriptionId): Prescription
    {
        $prescription = Prescription::where('type', Prescription::TYPE_RANAP)->findOrFail($prescriptionId);

        if ($prescription->status === 'DISPENSED') {
            throw new \Exception('Permintaan yang sudah diserahkan tidak bisa dibatalkan.', 422);
        }

        $prescription->update(['status' => 'CANCELLED']);
        $this->log(auth('api')->id(), 'TOLAK_RANAP_OBAT', Prescription::class, $prescriptionId);

        return $prescription->fresh();
    }

    // =========================================================================
    // RIWAYAT PEMBERIAN OBAT — "obat ini diberikan ke siapa"
    // =========================================================================

    /**
     * Riwayat satu obat diberikan ke pasien/pembeli: gabung resep yang sudah
     * DISPENSED (rawat jalan / obat pulang / permintaan rawat inap) + penjualan
     * bebas POS yang PAID. Urut tanggal terbaru. Dipakai tab Laporan Farmasi.
     *
     * @return array<array{tanggal:?string,pasien:string,no_rm:?string,quantity:float,sumber:string,petugas:?string}>
     */
    public function getMedicationDispenseHistory(string $medicationId, array $filters = []): array
    {
        $limit = (int) ($filters['limit'] ?? 200);

        // 1. Dari resep ter-dispense (item per obat).
        $rxRows = PrescriptionItem::query()
            ->where('prescription_items.medication_id', $medicationId)
            ->whereHas('prescription', fn ($q) => $q->where('status', 'DISPENSED'))
            ->with([
                'prescription.visit.patient',
                'prescription.dispensedBy',
            ])
            ->get()
            ->map(function ($item) {
                $rx    = $item->prescription;
                $visit = $rx?->visit;
                $sumber = $this->labelJenisPemberian($visit, $rx);

                return [
                    'tanggal'  => optional($rx?->dispensed_at)->toIso8601String(),
                    'pasien'   => $visit?->patient?->name ?? '—',
                    'no_rm'    => $visit?->patient?->no_rm,
                    'quantity' => (float) $item->quantity,
                    'sumber'   => $sumber,
                    'petugas'  => $rx?->dispensedBy?->name,
                ];
            });

        // 2. Dari penjualan bebas POS (PAID).
        $posRows = PharmacySaleItem::query()
            ->where('pharmacy_sale_items.medication_id', $medicationId)
            ->whereHas('sale', fn ($q) => $q->where('status', 'PAID'))
            ->with(['sale.soldBy'])
            ->get()
            ->map(fn ($item) => [
                'tanggal'  => optional($item->sale?->created_at)->toIso8601String(),
                'pasien'   => $item->sale?->buyer_name ?: 'Umum (POS)',
                'no_rm'    => null,
                'quantity' => (float) $item->quantity,
                'sumber'   => 'Penjualan Bebas',
                'petugas'  => $item->sale?->soldBy?->name,
            ]);

        return $rxRows->concat($posRows)
            ->sortByDesc('tanggal')
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * Label jenis pemberian resep: Rawat Jalan / Rawat Inap / Bedah / IGD.
     * jenis_pelayanan = penanda kanonik (RANAP/IGD/RAJAL); Bedah dikenali dari
     * surgery_schedule_id / visit_type PREOP_BEDAH (visit_type tak memuat RANAP/IGD).
     */
    private function labelJenisPemberian(?Visit $visit, ?Prescription $rx): string
    {
        $jenis = $visit?->jenis_pelayanan;

        if ($jenis === 'IGD') {
            return 'IGD';
        }
        if ($rx?->type === Prescription::TYPE_RANAP || $jenis === 'RANAP') {
            return 'Rawat Inap';
        }
        if ($visit?->surgery_schedule_id !== null || $visit?->visit_type === 'PREOP_BEDAH') {
            return 'Bedah';
        }

        return 'Rawat Jalan';
    }

    /**
     * Klasifikasi asal resep untuk badge antrean Verifikasi Farmasi → [kode, label].
     * Klasifikasi PER-RESEP (bukan per-visit): pasien operasi/tindakan hari-sama bisa
     * punya dua resep obat pulang dalam satu visit — (1) dari dokter POLIKLINIK saat
     * planning, dan (2) PASCA BEDAH/TINDAKAN yang ditambahkan setelah prosedur. Flag
     * `is_post_op` (per-resep) membedakan keduanya agar Farmasi tahu sumbernya; bedah vs
     * tindakan dibedakan via `surgerySchedule.location_type`.
     */
    private function klasifikasiAsalResep(?Visit $visit, ?Prescription $rx): array
    {
        $jenis = $visit?->jenis_pelayanan;

        // Instruksi obat pre-operasi dokter jaga (stat-dose di Triase, visit
        // PREOP_BEDAH) — bedakan dari resep dokter/pasca-bedah agar petugas
        // Farmasi tahu obat ini harus diberikan SEBELUM pasien naik OT.
        if ($rx?->is_pre_op) {
            return ['PRE_OP', 'Pre-Op (Dokter Jaga)'];
        }

        if ($jenis === 'IGD') {
            return ['IGD', 'IGD'];
        }
        if ($rx?->type === Prescription::TYPE_RANAP || $jenis === 'RANAP') {
            return ['RANAP', 'Rawat Inap'];
        }

        // Resep PASCA prosedur (ditambahkan dokter SETELAH bedah/tindakan selesai).
        // Label bedah vs tindakan dari lokasi jadwal.
        if ($rx?->is_post_op) {
            return $visit?->surgerySchedule?->location_type === SurgerySchedule::LOCATION_RUANG_TINDAKAN
                ? ['TINDAKAN', 'Pasca Tindakan']
                : ['BEDAH', 'Pasca Bedah'];
        }

        // Resep dari POLIKLINIK pada visit yang juga punya jadwal bedah/tindakan → tandai
        // "Poliklinik" agar Farmasi tahu obat ini diresepkan dokter poli (bukan pasca-prosedur).
        $adaBedah = $visit?->surgery_schedule_id !== null || $visit?->visit_type === 'PREOP_BEDAH';
        if ($adaBedah) {
            return ['POLI', 'Poliklinik'];
        }

        return ['RAJAL', 'Rawat Jalan'];
    }

    /**
     * Riwayat GLOBAL obat yang diberikan ke pasien (semua obat) — gabungan
     * resep ter-dispense (rawat jalan/inap) + penjualan bebas POS. Dipakai tab
     * "Riwayat Pemberian" di Farmasi. Server-side: search (nama obat/pasien/no_rm),
     * rentang tanggal, dan paginasi (per_page dibatasi 10..100, default 50).
     *
     * UNION ALL dua sumber lalu dipaginasi di subquery agar tanggal terurut
     * benar lintas-sumber tanpa memuat seluruh baris ke memori.
     */
    public function getDispenseHistory(array $filters = []): LengthAwarePaginator
    {
        $perPage = max(10, min((int) ($filters['per_page'] ?? 50), 100));

        return $this->dispenseHistoryQuery($filters)->paginate($perPage);
    }

    /**
     * Batas baris export riwayat pemberian.
     *  - XLSX: PhpSpreadsheet menahan seluruh sheet di RAM (~9KB/baris) → cap rendah.
     *  - CSV : ditulis streaming (memory-flat) → cap tinggi sbg fallback data besar.
     */
    public const DISPENSE_EXPORT_XLSX_MAX = 20000;
    public const DISPENSE_EXPORT_CSV_MAX  = 200000;

    /**
     * Versi export: baris riwayat pemberian (sesuai filter) sebagai LazyCollection
     * streaming — dipakai unduh tab Riwayat Pemberian. Pakai cursor() agar baris tak
     * dimuat sekaligus ke memori; dibatasi $limit baris (terbaru lebih dulu).
     *
     * @return \Illuminate\Support\LazyCollection<int,object>
     */
    public function exportDispenseHistory(array $filters = [], int $limit = self::DISPENSE_EXPORT_XLSX_MAX): \Illuminate\Support\LazyCollection
    {
        return $this->dispenseHistoryQuery($filters)
            ->limit(max(1, $limit))
            ->cursor();
    }

    /**
     * Query gabungan (UNION ALL) riwayat pemberian — dipakai bersama oleh paginasi
     * tab & export. Belum di-paginate/get agar pemanggil menentukan bentuk hasil.
     */
    private function dispenseHistoryQuery(array $filters = []): \Illuminate\Database\Query\Builder
    {
        $search   = trim((string) ($filters['search'] ?? ''));
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo   = $filters['date_to'] ?? null;
        // Filter jenis pelayanan: RAJAL | RANAP | BEDAH | IGD | POS (kosong = semua).
        $jenis    = strtoupper(trim((string) ($filters['jenis'] ?? '')));

        // Klasifikasi sumber resep — jenis_pelayanan adalah penanda kanonik
        // (RANAP/IGD/RAJAL). Pemberian obat pasca-BEDAH TIDAK jadi kategori sendiri:
        // dilebur ke Rawat Inap (bedah ranap) atau Rawat Jalan (bedah rajal) mengikuti
        // jenis pelayanan visit-nya — bedah ranap tertangkap cabang RANAP, bedah rajal
        // jatuh ke ELSE (RAJAL). (visit_type HANYA REGULAR/PREOP_BEDAH.)
        $kodeExpr = "CASE
                WHEN v.jenis_pelayanan = 'IGD' THEN 'IGD'
                WHEN p.type = 'RANAP' OR v.jenis_pelayanan = 'RANAP' THEN 'RANAP'
                ELSE 'RAJAL'
            END";
        $labelExpr = "CASE
                WHEN v.jenis_pelayanan = 'IGD' THEN 'IGD'
                WHEN p.type = 'RANAP' OR v.jenis_pelayanan = 'RANAP' THEN 'Rawat Inap'
                ELSE 'Rawat Jalan'
            END";

        // Sumber 1 — item resep yang sudah diserahkan (DISPENSED).
        $rx = DB::table('prescription_items as pi')
            ->join('prescriptions as p', 'p.id', '=', 'pi.prescription_id')
            ->join('medications as m', 'm.id', '=', 'pi.medication_id')
            ->leftJoin('visits as v', 'v.id', '=', 'p.visit_id')
            ->leftJoin('patients as pt', 'pt.id', '=', 'v.patient_id')
            ->leftJoin('employees as e', 'e.id', '=', 'p.dispensed_by_id')
            ->whereNull('pi.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.status', 'DISPENSED')
            // dispensed_at bisa NULL pada data lama → pakai updated_at sbg fallback
            // agar tanggal selalu terisi (urut & filter rentang tanggal tetap akurat).
            ->when($dateFrom, fn ($q) => $q->whereRaw('COALESCE(p.dispensed_at, p.updated_at)::date >= ?', [$dateFrom]))
            ->when($dateTo, fn ($q) => $q->whereRaw('COALESCE(p.dispensed_at, p.updated_at)::date <= ?', [$dateTo]))
            ->when($search !== '', function ($q) use ($search) {
                $t = "%{$search}%";
                $q->where(fn ($w) => $w
                    ->where('m.name', 'ilike', $t)
                    ->orWhere('pt.name', 'ilike', $t)
                    ->orWhere('pt.no_rm', 'ilike', $t));
            })
            ->select([
                'pi.id as id',
                DB::raw('COALESCE(p.dispensed_at, p.updated_at) as tanggal'),
                DB::raw("COALESCE(pt.name, '—') as pasien"),
                DB::raw('pt.no_rm as no_rm'),
                'm.name as obat',
                DB::raw('pi.quantity::numeric as quantity'),
                DB::raw("{$kodeExpr} as jenis_kode"),
                DB::raw("{$labelExpr} as sumber"),
                DB::raw('e.name as petugas'),
            ]);

        // Sumber 2 — penjualan obat bebas POS (PAID).
        $pos = DB::table('pharmacy_sale_items as si')
            ->join('pharmacy_sales as s', 's.id', '=', 'si.pharmacy_sale_id')
            ->join('medications as m', 'm.id', '=', 'si.medication_id')
            ->leftJoin('employees as e', 'e.id', '=', 's.sold_by_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when($dateFrom, fn ($q) => $q->whereDate('s.created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('s.created_at', '<=', $dateTo))
            ->when($search !== '', function ($q) use ($search) {
                $t = "%{$search}%";
                $q->where(fn ($w) => $w
                    ->where('m.name', 'ilike', $t)
                    ->orWhere('s.buyer_name', 'ilike', $t)
                    ->orWhere('s.sale_number', 'ilike', $t));
            })
            ->select([
                'si.id as id',
                DB::raw('s.created_at as tanggal'),
                DB::raw("COALESCE(NULLIF(s.buyer_name, ''), 'Umum (POS)') as pasien"),
                DB::raw('NULL::varchar as no_rm'),
                'm.name as obat',
                DB::raw('si.quantity::numeric as quantity'),
                DB::raw("'POS' as jenis_kode"),
                DB::raw("'Penjualan Bebas' as sumber"),
                DB::raw('e.name as petugas'),
            ]);

        return DB::query()
            ->fromSub($rx->unionAll($pos), 't')
            // Saring jenis pelayanan pada hasil gabungan (RAJAL/RANAP/IGD/POS).
            ->when(in_array($jenis, ['RAJAL', 'RANAP', 'IGD', 'POS'], true),
                fn ($q) => $q->where('jenis_kode', $jenis))
            ->orderByRaw('tanggal DESC NULLS LAST');
    }

    // -------------------------------------------------------------------------
    // Item dispensing CRUD

    public function storeItemDispensing(string $prescriptionId, array $items): Collection
    {
        $prescription = Prescription::findOrFail($prescriptionId);

        // Hanya boleh menambah item pada fase verifikasi (belum dikunci) — cegah
        // penambahan obat tak tertagih setelah verifikasi/tagihan.
        $this->assertResepEditable($prescription);

        $userId     = auth('api')->id();
        $employeeId = auth('api')->user()?->employee_id;

        return DB::transaction(function () use ($prescriptionId, $items, $employeeId, $userId) {
            $created = [];
            $adaTambahan = false;
            foreach ($items as $item) {
                $source = ($item['source'] ?? 'RESEP') === 'TAMBAHAN' ? 'TAMBAHAN' : 'RESEP';

                // Item tambahan apotek: hanya obat BEBAS/BEBAS_TERBATAS + catat petugas.
                if ($source === 'TAMBAHAN') {
                    $this->assertObatBolehTambahan($item['medication_id']);
                    $adaTambahan = true;
                }

                $hasReason = ! empty($item['change_reason']);
                $created[] = PrescriptionItem::create([
                    'prescription_id' => $prescriptionId,
                    'medication_id'   => $item['medication_id'],
                    'source'          => $source,
                    'added_by_id'     => $source === 'TAMBAHAN' ? $employeeId : null,
                    'quantity'        => $item['quantity'],
                    'dosage'          => $item['dosage'] ?? null,
                    'instructions'    => $item['instructions'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                    // Audit penambahan saat verifikasi (alasan terstruktur).
                    'change_reason'   => $hasReason ? $item['change_reason'] : null,
                    'changed_by_id'   => $hasReason ? $employeeId : null,
                    'changed_at'      => $hasReason ? now() : null,
                ]);
            }

            if ($adaTambahan) {
                $this->log($userId, 'ADD_ITEM_TAMBAHAN', Prescription::class, $prescriptionId,
                    'Tambah obat di luar resep dokter (TAMBAHAN apotek)');
            }

            // Eloquent collection (bukan base collection) supaya ->load() valid.
            return PrescriptionItem::with('medication')
                ->whereIn('id', collect($created)->pluck('id'))
                ->get();
        });
    }

    /**
     * Penjualan obat tambahan (OTC) untuk pasien antrean Farmasi yang BELUM punya
     * resep. Buat Prescription baru atas nama petugas farmasi (prescribed_by_id),
     * status DISPENSING (skip verifikasi), semua item dipaksa source=TAMBAHAN.
     */
    public function createOtcPrescription(string $visitId, array $items): Prescription
    {
        $visit = Visit::findOrFail($visitId);

        // Hanya untuk pasien yang ada di papan FARMASI (boardVisible = hari ini ATAU
        // masih aktif lintas-hari ≤7 hari) — selaras dgn getPatientQueue, supaya
        // pasien lintas-hari yang tampil di papan tak ditolak saat tambah OTC.
        $diFarmasi = Queue::where('visit_id', $visitId)
            ->where('station', Queue::STATION_FARMASI)
            ->boardVisible()
            ->exists();
        if (! $diFarmasi) {
            throw new \Exception('Penjualan obat tambahan hanya untuk pasien yang ada di antrean Farmasi.', 422);
        }

        $userId     = auth('api')->id();
        $employeeId = auth('api')->user()?->employee_id;

        return DB::transaction(function () use ($visit, $items, $employeeId, $userId) {
            $prescription = Prescription::create([
                'visit_id'         => $visit->id,
                'prescribed_by_id' => $employeeId,
                'status'           => 'DISPENSING',
                'notes'            => 'Pembelian obat tambahan (OTC) di apotek',
            ]);

            foreach ($items as $item) {
                $this->assertObatBolehTambahan($item['medication_id']);
                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    'medication_id'   => $item['medication_id'],
                    'source'          => 'TAMBAHAN',
                    'added_by_id'     => $employeeId,
                    'quantity'        => $item['quantity'],
                    'dosage'          => $item['dosage'] ?? null,
                    'instructions'    => $item['instructions'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            $this->log($userId, 'CREATE_OTC_PRESCRIPTION', Prescription::class, $prescription->id,
                'Penjualan obat tambahan (OTC) — ' . count($items) . ' item');

            return $prescription->load('items.medication');
        });
    }

    /**
     * Pastikan obat boleh dijual sebagai item TAMBAHAN tanpa resep dokter.
     *
     * Hanya golongan setara BEBAS / BEBAS_TERBATAS / SUPLEMEN / JAMU yang boleh.
     * Obat KERAS/NARKOTIKA/PSIKOTROPIKA dan obat TANPA label golongan (NULL) DITOLAK
     * (konservatif — petugas wajib melengkapi golongan di master dulu).
     *
     * NB: master `golongan` di DB tidak seragam (mis. "OBAT KERAS", "OBAT BEBAS",
     * "SUPLEMEN", NULL), jadi normalisasi via kata kunci, bukan match enum persis.
     */
    public function assertObatBolehTambahan(string $medicationId): void
    {
        $med = Medication::findOrFail($medicationId);
        $g = strtoupper(trim((string) $med->golongan));

        $terlarang = $g === ''
            || str_contains($g, 'KERAS')
            || str_contains($g, 'NARKOTIKA')
            || str_contains($g, 'PSIKOTROPIKA');

        $boleh = ! $terlarang && (
            str_contains($g, 'BEBAS')
            || str_contains($g, 'SUPLEMEN')
            || str_contains($g, 'JAMU')
        );

        if (! $boleh) {
            $label = $g === '' ? 'tanpa golongan' : "golongan {$med->golongan}";
            throw new \Exception(
                "Obat {$med->name} ({$label}) tidak boleh ditambahkan tanpa resep dokter. " .
                "Hanya obat bebas/bebas terbatas/suplemen/jamu yang boleh dijual sebagai tambahan apotek. " .
                "Lengkapi golongan obat di master bila perlu.",
                422
            );
        }
    }

    public function updateItemDispensing(string $id, array $data): PrescriptionItem
    {
        $item = PrescriptionItem::with('prescription')->findOrFail($id);
        $this->assertResepEditable($item->prescription);

        $update = [];
        foreach (['quantity', 'dosage', 'instructions', 'notes'] as $f) {
            if (array_key_exists($f, $data) && ! is_null($data[$f])) {
                $update[$f] = $data[$f];
            }
        }

        // Ubah qty manual pada item ber-kemasan → kemasan DIBATALKAN (kembali satuan
        // kecil; quantity = sumber kebenaran stok). Menjaga invarian
        // quantity = sale_unit_qty × isi. FE menampilkan konfirmasi sebelum ini.
        if (array_key_exists('quantity', $update) && $item->sale_unit_id
            && (int) $update['quantity'] !== (int) $item->quantity) {
            $update['sale_unit_id']  = null;
            $update['sale_unit_qty'] = null;
        }

        // Substitusi obat (ganti medication_id) → simpan obat asli dokter SEKALI utk audit.
        if (! empty($data['medication_id']) && $data['medication_id'] !== $item->medication_id) {
            if (is_null($item->original_medication_id)) {
                $update['original_medication_id'] = $item->medication_id;
            }
            $update['medication_id'] = $data['medication_id'];
            // Kemasan milik obat lama tidak berlaku utk obat pengganti → reset.
            $update['sale_unit_id']  = null;
            $update['sale_unit_qty'] = null;
        }

        // Jejak perubahan terstruktur (alasan divalidasi di controller).
        if (! empty($data['change_reason'])) {
            $update['change_reason'] = $data['change_reason'];
            $update['changed_by_id'] = auth('api')->user()?->employee_id;
            $update['changed_at']    = now();
        }

        $item->update($update);
        $this->log(auth('api')->id(), 'UPDATE_ITEM_RESEP', PrescriptionItem::class, $id);

        return $item->fresh('medication');
    }

    /**
     * Pilih VARIAN KEMASAN JUAL (per Strip/Box, harga independen) untuk satu item
     * resep — hanya pada FASE VERIFIKASI (pra-kunci), oleh Farmasi.
     *
     * INVARIAN: quantity (satuan kecil, sumber kebenaran STOK) = sale_unit_qty × isi
     * → dispensing/potong stok tidak berubah; billing (buildObatLines) menagih per
     * kemasan (qty=sale_unit_qty × harga kemasan).
     *
     * split_remainder: bila qty kemasan baru < quantity lama → sisa dipecah jadi
     * item saudara satuan kecil (PECAH_KEMASAN) — skenario "25 Tab = 2 Strip + 5 Tab"
     * dalam satu aksi server-side (FE verifikasi tak punya jalur tambah obat keras).
     *
     * @param array{sale_unit_id?:?string,sale_unit_qty?:int,split_remainder?:bool,change_reason?:?string} $data
     */
    public function setKemasanItem(string $id, array $data): PrescriptionItem
    {
        $item = PrescriptionItem::with(['prescription.visit', 'medication'])->findOrFail($id);
        $rx   = $item->prescription;

        $this->assertResepEditable($rx);
        if ($rx->type === Prescription::TYPE_RANAP) {
            throw new \Exception('Kemasan jual hanya untuk resep rawat jalan/bedah (RANAP ditagih per satuan).', 422);
        }
        // OTC dibuat langsung DISPENSING (skip verifikasi) → di luar scope kemasan v1.
        if (! in_array($rx->status, ['DRAFT', 'SUBMITTED'], true)) {
            throw new \Exception('Kemasan hanya bisa dipilih pada fase verifikasi resep.', 422);
        }
        $jenis = $rx->visit?->jenis_pelayanan ?? 'RAJAL';
        if (in_array($jenis, ['RANAP', 'IGD'], true)) {
            throw new \Exception('Kemasan jual tidak berlaku untuk pasien RANAP/IGD (obat ditagih per satuan lewat biaya perawatan).', 422);
        }
        // Obat komponen paket BEDAH ditagih dari snapshot paket (bukan resep) —
        // memilih kemasan di sini = no-op billing yang menyesatkan. Tolak.
        if ($item->is_bedah || ($rx->visit && isset(app(KasirService::class)->paketObatMedIds($rx->visit)[$item->medication_id]))) {
            throw new \Exception('Obat ini termasuk komponen paket bedah — ditagih lewat harga paket, tidak memakai kemasan jual.', 422);
        }

        $employeeId = auth('api')->user()?->employee_id;

        return DB::transaction(function () use ($item, $data, $employeeId, $id) {
            // Lepas kemasan → kembali satuan kecil (quantity tidak diubah).
            if (empty($data['sale_unit_id'])) {
                $item->update(['sale_unit_id' => null, 'sale_unit_qty' => null]);
                $this->log(auth('api')->id(), 'SET_KEMASAN_ITEM', PrescriptionItem::class, $id, 'Kemasan dilepas (kembali satuan kecil)');
                return $item->fresh(['medication', 'saleUnit']);
            }

            $unit = MedicationSaleUnit::findOrFail($data['sale_unit_id']);
            if ($unit->medication_id !== $item->medication_id) {
                throw new \Exception('Kemasan terpilih bukan milik obat ini.', 422);
            }
            if (! $unit->is_active) {
                throw new \Exception('Kemasan jual ini sedang nonaktif.', 422);
            }

            $saleQty = max(1, (int) ($data['sale_unit_qty'] ?? 1));
            $newQty  = $saleQty * (int) $unit->isi;
            $oldQty  = (int) $item->quantity;

            // Pecah sisa: 25 Tab → 2 Strip (20) + item saudara 5 Tab satuan kecil.
            if (! empty($data['split_remainder']) && $newQty < $oldQty) {
                PrescriptionItem::create([
                    'prescription_id' => $item->prescription_id,
                    'medication_id'   => $item->medication_id,
                    'source'          => $item->source ?? 'RESEP',
                    'quantity'        => $oldQty - $newQty,
                    'dosage'          => $item->dosage,
                    'instructions'    => $item->instructions,
                    'notes'           => $item->notes,
                    'dose'            => $item->dose,
                    'frequency'       => $item->frequency,
                    'route'           => $item->route,
                    'duration_days'   => $item->duration_days,
                    'change_reason'   => 'PECAH_KEMASAN',
                    'changed_by_id'   => $employeeId,
                    'changed_at'      => now(),
                ]);
            }

            $item->update([
                'sale_unit_id'  => $unit->id,
                'sale_unit_qty' => $saleQty,
                'quantity'      => $newQty,
                'change_reason' => $data['change_reason'] ?? $item->change_reason,
                'changed_by_id' => $employeeId,
                'changed_at'    => now(),
            ]);

            $this->log(auth('api')->id(), 'SET_KEMASAN_ITEM', PrescriptionItem::class, $id,
                "Kemasan {$unit->label} × {$saleQty} (= {$newQty} satuan)");

            return $item->fresh(['medication', 'saleUnit']);
        });
    }

    public function deleteItemDispensing(string $id, ?string $reason = null): void
    {
        $item = PrescriptionItem::with('prescription')->findOrFail($id);
        $this->assertResepEditable($item->prescription);

        // Catat alasan sebelum soft-delete → baris trashed menyimpan jejak audit.
        if (! empty($reason)) {
            $item->update([
                'change_reason' => $reason,
                'changed_by_id' => auth('api')->user()?->employee_id,
                'changed_at'    => now(),
            ]);
        }

        $item->delete();
        $this->log(auth('api')->id(), 'DELETE_ITEM_RESEP', PrescriptionItem::class, $id, $reason ? "Alasan: {$reason}" : null);
    }

    // =========================================================================
    // SURGERY REQUEST — BHP + IOL
    // =========================================================================

    public function getSurgeryRequests(array $filters = []): Collection
    {
        $query = SurgeryRequest::with([
            'visit.patient',
            'surgerySchedule.surgeryPackage',
            'requestedBy',
            'bhpItems.bhpItem',
            'iolItems.iolItem',
        ]);

        $query->where('status', $filters['status'] ?? 'REQUESTED');

        if (! empty($filters['tanggal'])) {
            $query->whereDate('created_at', $filters['tanggal']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function getSurgeryRequestById(string $id): SurgeryRequest
    {
        return SurgeryRequest::with([
            'visit.patient',
            'surgerySchedule.surgeryPackage',
            'requestedBy',
            'bhpItems.bhpItem',
            'iolItems.iolItem',
        ])->findOrFail($id);
    }

    /**
     * Tandai bahwa Farmasi sedang menyiapkan item.
     * Tidak mengubah status — hanya log sebagai audit trail.
     */
    public function siapkanSurgeryRequest(string $requestId): SurgeryRequest
    {
        $request = SurgeryRequest::findOrFail($requestId);

        if ($request->status !== 'REQUESTED') {
            throw new \Exception('Hanya request dengan status REQUESTED yang bisa disiapkan.', 422);
        }

        $this->log(
            auth('api')->id(),
            'SIAPKAN_SURGERY_REQUEST',
            SurgeryRequest::class,
            $requestId,
            'Farmasi mulai menyiapkan BHP+IOL'
        );

        return $request->load(['bhpItems.bhpItem', 'iolItems.iolItem']);
    }

    /**
     * Assign IOL item spesifik ke surgery_request_iol.
     * Validasi: iol_item belum dipakai + power/type cocok dengan permintaan.
     */
    public function assignIolToRequest(string $requestIolId, string $iolItemId): SurgeryRequestIol
    {
        $requestIol = SurgeryRequestIol::with('surgeryRequest')->findOrFail($requestIolId);
        $iolItem    = IolItem::findOrFail($iolItemId);

        if ($iolItem->is_used) {
            throw new \Exception("IOL {$iolItem->brand} {$iolItem->model} (P:{$iolItem->power}) sudah digunakan.", 422);
        }

        if (! $iolItem->is_active) {
            throw new \Exception('IOL item tidak aktif.', 422);
        }

        // Validasi power: toleransi ±0.5 D
        if (
            $requestIol->requested_power
            && abs($iolItem->power - $requestIol->requested_power) > 0.5
        ) {
            throw new \Exception(
                "Power IOL tidak cocok. Diminta: {$requestIol->requested_power} D, tersedia: {$iolItem->power} D (toleransi ±0.5 D).",
                422
            );
        }

        $requestIol->update(['iol_item_id' => $iolItemId]);

        $this->log(
            auth('api')->id(),
            'ASSIGN_IOL',
            SurgeryRequestIol::class,
            $requestIolId,
            "IOL {$iolItem->brand} {$iolItem->model} P{$iolItem->power} di-assign ke mata {$requestIol->eye_side}"
        );

        return $requestIol->fresh('iolItem');
    }

    /**
     * Kirim supply ke Bedah (REQUESTED → SENT).
     * Guard: semua IOL item wajib sudah di-assign.
     * Side-effect: deduct BHP stock.
     */
    public function kirimSurgeryRequest(string $requestId): SurgeryRequest
    {
        $surgeryRequest = SurgeryRequest::with([
            'bhpItems.bhpItem',
            'iolItems.iolItem',
        ])->findOrFail($requestId);

        if ($surgeryRequest->status !== 'REQUESTED') {
            throw new \Exception('Request harus dalam status REQUESTED untuk dikirim.', 422);
        }

        // Semua IOL harus sudah di-assign
        $unassignedIol = $surgeryRequest->iolItems->filter(fn ($i) => ! $i->iol_item_id);
        if ($unassignedIol->isNotEmpty()) {
            throw new \Exception(
                "Belum semua IOL di-assign. Mata belum di-assign: "
                . $unassignedIol->pluck('eye_side')->implode(', ') . '.',
                422
            );
        }

        DB::transaction(function () use ($surgeryRequest) {
            // Deduct BHP dari inventory_stocks lokasi UNIT BEDAH (FEFO, per-batch,
            // strict) — bukan kolom legacy bhp_items.stock. Kalau stok unit BEDAH
            // kurang → minta transfer dari gudang dulu.
            foreach ($surgeryRequest->bhpItems as $item) {
                if ($item->bhpItem) {
                    $onHand = $this->stockService->onHand('BHP', $item->bhp_item_id, InventoryStock::LOC_BEDAH);
                    if ($onHand < $item->quantity) {
                        throw new \Exception(
                            "Stok unit BEDAH untuk BHP {$item->bhpItem->name} tidak mencukupi. Tersedia: {$onHand}. Minta transfer dari gudang dulu.",
                            422
                        );
                    }
                    $this->stockService->consume('BHP', $item->bhp_item_id, (float) $item->quantity, InventoryStock::LOC_BEDAH);
                }
            }

            $surgeryRequest->update([
                'status'  => 'SENT',
                'sent_at' => now(),
            ]);
        });

        $this->log(
            auth('api')->id(),
            'KIRIM_SURGERY_REQUEST',
            SurgeryRequest::class,
            $requestId,
            'BHP+IOL dikirim ke Bedah'
        );

        return $surgeryRequest->fresh(['bhpItems.bhpItem', 'iolItems.iolItem']);
    }

    // =========================================================================
    // STOK — OBAT
    // =========================================================================

    public function getStokObat(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = $this->withFarmasiOnHand(Medication::query(), 'MEDICATION');

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('medications.name', 'ilike', "%{$keyword}%")
                ->orWhere('medications.code', 'ilike', "%{$keyword}%")
                ->orWhere('medications.generic_name', 'ilike', "%{$keyword}%")
            );
        }

        if (! empty($filters['formularium'])) {
            $query->where('medications.formularium', $filters['formularium']);
        }

        if (! empty($filters['alert'])) {
            $query->whereRaw('COALESCE(farmasi_stock.qty, 0) <= medications.min_stock');
        }

        $query->orderBy('medications.name');

        // per_page = 'all' (atau <= 0) → kembalikan SEMUA baris tanpa paginasi. Daftar
        // stok ini dipakai UTUH di FE Farmasi (on-hand dispensing, daftar OTC, hitung
        // low-stock), jadi tak boleh terpotong di halaman pertama saat master obat > per_page.
        $perPage = $filters['per_page'] ?? 25;
        $unpaginated = $perPage === 'all' || (is_numeric($perPage) && (int) $perPage <= 0);
        $result = $unpaginated ? $query->get() : $query->paginate((int) $perPage);

        $rows = $unpaginated ? $result : $result->getCollection();
        $rows->each(fn ($m) => $m->stock = (float) $m->farmasi_qty);

        // Lampirkan harga jual obat dari Buku Tarif (medication_tariffs, baris insurer
        // UMUM = harga tunggal) — dipakai POS penjualan obat bebas untuk preview
        // harga/total di UI. Field tetap bernama `hja` agar FE POS tak berubah.
        $ids = $rows->pluck('id')->all();
        if (! empty($ids)) {
            $umumId = \App\Models\Insurer::where('is_system', true)->where('type', 'UMUM')->value('id');
            $hjaMap = $umumId
                ? DB::table('medication_tariffs')
                    ->where('insurer_id', $umumId)
                    ->whereIn('medication_id', $ids)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->pluck('price', 'medication_id')
                : collect();
            $rows->each(fn ($m) => $m->hja = isset($hjaMap[$m->id]) ? (float) $hjaMap[$m->id] : null);
        }

        return $result;
    }

    public function updateStokObat(string $id, array $data): Medication
    {
        $medication = Medication::findOrFail($id);

        // Atribut master (min_stock/price) tetap di tabel medications.
        $medication->update(array_filter([
            'min_stock' => $data['min_stock'] ?? null,
            'price'     => $data['price'] ?? null,
        ], fn ($v) => ! is_null($v)));

        // Stok adalah per-batch di inventory_stocks lokasi FARMASI (sumber kebenaran
        // yang dikonsumsi dispensing). Opname set-total ke lokasi FARMASI.
        if (array_key_exists('stock', $data) && $data['stock'] !== null) {
            $this->stockService->opname([
                'item_type' => 'MEDICATION',
                'item_id'   => $id,
                'location'  => InventoryStock::LOC_FARMASI,
                'new_qty'   => (float) $data['stock'],
                'reason'    => 'Koreksi stok manual (Farmasi)',
            ]);
        }

        $onHand = $this->stockService->onHand('MEDICATION', $id, InventoryStock::LOC_FARMASI);
        $medication->stock = $onHand;

        return $medication;
    }

    // =========================================================================
    // STOK — BHP
    // =========================================================================

    public function getStokBhp(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = $this->withFarmasiOnHand(BhpItem::query(), 'BHP');

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('bhp_items.name', 'ilike', "%{$keyword}%")
                ->orWhere('bhp_items.code', 'ilike', "%{$keyword}%")
            );
        }

        if (! empty($filters['alert'])) {
            $query->whereRaw('COALESCE(farmasi_stock.qty, 0) <= bhp_items.min_stock');
        }

        $query->orderBy('bhp_items.name');

        // per_page = 'all' (atau <= 0) → kembalikan SEMUA baris tanpa paginasi
        // (selaras getStokObat; dipakai tab Manajemen Stok yang paginasi client-side).
        $perPage = $filters['per_page'] ?? 25;
        $unpaginated = $perPage === 'all' || (is_numeric($perPage) && (int) $perPage <= 0);
        $result = $unpaginated ? $query->get() : $query->paginate((int) $perPage);

        $rows = $unpaginated ? $result : $result->getCollection();
        $rows->each(fn ($b) => $b->stock = (float) $b->farmasi_qty);

        return $result;
    }

    public function updateStokBhp(string $id, array $data): BhpItem
    {
        $bhp = BhpItem::findOrFail($id);

        $bhp->update(array_filter([
            'min_stock' => $data['min_stock'] ?? null,
            'price'     => $data['price'] ?? null,
        ], fn ($v) => ! is_null($v)));

        if (array_key_exists('stock', $data) && $data['stock'] !== null) {
            $this->stockService->opname([
                'item_type' => 'BHP',
                'item_id'   => $id,
                'location'  => InventoryStock::LOC_FARMASI,
                'new_qty'   => (float) $data['stock'],
                'reason'    => 'Koreksi stok manual (Farmasi)',
            ]);
        }

        $bhp->stock = $this->stockService->onHand('BHP', $id, InventoryStock::LOC_FARMASI);

        return $bhp;
    }

    // =========================================================================
    // STOK — IOL
    // =========================================================================

    public function getStokIol(array $filters = []): LengthAwarePaginator
    {
        // on_hand dari inventory_stocks (sumber stok tunggal per-tipe).
        $query = IolItem::withOnHand()->where('iol_items.is_active', true);

        // available_only (per-tipe): on_hand > 0 (BUKAN lagi is_used legacy).
        if (! empty($filters['available_only'])) {
            $query->whereRaw('COALESCE(iol_stock.qty, 0) > 0');
        }

        if (! empty($filters['iol_type'])) {
            $query->where('iol_items.iol_type', $filters['iol_type']);
        }

        if (! empty($filters['brand'])) {
            $query->where('iol_items.brand', 'ilike', "%{$filters['brand']}%");
        }

        if (! empty($filters['power'])) {
            $query->where('iol_items.power', $filters['power']);
        }

        $page = $query->orderBy('iol_items.brand')->orderBy('iol_items.power')->paginate($filters['per_page'] ?? 25);
        // Tampilkan stok nyata di field `stock` untuk konsistensi UI (override legacy).
        $page->getCollection()->each(fn ($i) => $i->stock = (float) ($i->on_hand ?? 0));

        return $page;
    }

    public function updateStokIol(string $id, array $data): IolItem
    {
        $iol = IolItem::findOrFail($id);

        $iol->update(array_filter([
            'brand'         => $data['brand'] ?? null,
            'model'         => $data['model'] ?? null,
            'iol_type'      => $data['iol_type'] ?? null,
            'material'      => $data['material'] ?? null,
            'power'         => $data['power'] ?? null,
            'lot_number'    => $data['lot_number'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'gs1_barcode'   => $data['gs1_barcode'] ?? null,
            'price'         => $data['price'] ?? null,
            'is_active'     => $data['is_active'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_STOK_IOL', IolItem::class, $id);

        return $iol->fresh();
    }

    // =========================================================================
    // STOK ALERT (semua tipe)
    // =========================================================================

    public function getStokAlert(): array
    {
        $obatAlert = $this->withFarmasiOnHand(Medication::query(), 'MEDICATION')
            ->where('medications.is_active', true)
            ->whereRaw('COALESCE(farmasi_stock.qty, 0) <= medications.min_stock')
            ->get(['medications.id', 'medications.code', 'medications.name', 'medications.min_stock', 'medications.unit'])
            ->each(fn ($m) => $m->stock = (float) $m->farmasi_qty);

        $bhpAlert = $this->withFarmasiOnHand(BhpItem::query(), 'BHP')
            ->where('bhp_items.is_active', true)
            ->whereRaw('COALESCE(farmasi_stock.qty, 0) <= bhp_items.min_stock')
            ->get(['bhp_items.id', 'bhp_items.code', 'bhp_items.name', 'bhp_items.min_stock', 'bhp_items.unit'])
            ->each(fn ($b) => $b->stock = (float) $b->farmasi_qty);

        return [
            'obat'  => $obatAlert,
            'bhp'   => $bhpAlert,
            'total' => $obatAlert->count() + $bhpAlert->count(),
        ];
    }

    /**
     * Join sub-query SUM(qty_on_hand) inventory_stocks lokasi FARMASI ke query
     * master (medications / bhp_items). Menyediakan kolom alias `farmasi_qty`
     * (stok riil unit Farmasi yang dikonsumsi dispensing) + tabel `farmasi_stock`
     * untuk dipakai di whereRaw alert. Kolom legacy `stock` di master TIDAK lagi
     * otoritatif — selalu di-overlay dengan `farmasi_qty` oleh caller.
     */
    private function withFarmasiOnHand($query, string $itemType)
    {
        $table = $query->getModel()->getTable();

        // inventory_stocks TIDAK pakai SoftDeletes — JANGAN tambah whereNull('deleted_at')
        // (kolomnya tak ada → 500, bug kelas #4/#5 di audit pra-go-live).
        $sub = DB::table('inventory_stocks')
            ->select('item_id', DB::raw('SUM(qty_on_hand) as qty'))
            ->where('item_type', $itemType)
            ->where('location', InventoryStock::LOC_FARMASI)
            ->groupBy('item_id');

        return $query
            ->leftJoinSub($sub, 'farmasi_stock', "farmasi_stock.item_id", '=', "{$table}.id")
            ->select("{$table}.*", DB::raw('COALESCE(farmasi_stock.qty, 0) as farmasi_qty'));
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function log(
        ?string $userId,
        string $action,
        ?string $model = null,
        ?string $modelId = null,
        ?string $description = null
    ): void {
        SystemLog::create([
            'user_id'     => $userId,
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'description' => $description,
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
}
