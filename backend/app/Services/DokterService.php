<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BpjsControlLetter;
use App\Models\BpjsPoliMapping;
use App\Models\BpjsReferralOut;
use App\Models\ClinicProfile;
use App\Models\IntegrationConfig;
use App\Models\DiagnosticOrder;
use App\Models\DiagnosticTestType;
use App\Models\DoctorExamination;
use App\Models\DoctorSchedule;
use App\Models\DocumentType;
use App\Models\DocumentVerification;
use App\Models\Icd9Code;
use App\Models\Icd10Code;
use App\Models\InventoryStock;
use App\Models\Medication;
use App\Models\IolItem;
use App\Models\IolRecommendation;
use App\Models\MedicalResume;
use App\Models\Notification;
use App\Models\PatientDocument;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Procedure;
use App\Models\Queue;
use App\Models\SurgerySchedule;
use App\Models\SystemLog;
use App\Models\SurgeryPackage;
use App\Models\Visit;
use App\Models\VisitService;
use App\Models\VisitSurgeryPackage;
use App\Models\VisitSurgeryPackageItem;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DokterService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
        private readonly KasirService $kasirService,
        private readonly BpjsVClaimService $vclaim,
    ) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    public function getPatientQueue(): Collection
    {
        $user = auth('api')->user();

        $query = Queue::with([
            'visit.patient',
            // assessedBy/examinedBy → nama pemeriksa utk label "diperiksa oleh X"
            // di panel referensi read-only DokterView (TTV Perawat / Visus Refraksionis).
            'visit.nurseAssessment.assessedBy:id,name',
            'visit.refractionRecord.examinedBy:id,name',
            'visit.internalReferralFromSchedule:id,poliklinik',
        ])
            ->where('station', 'DOKTER')
            // hari ini / aktif lintas-hari ≤7 hari, ATAU pasien "belum tutup kasir"
            // (boleh dibuka ulang utk tambah obat/tindakan & revisi SOAP/resume/paket).
            ->boardVisibleOpenBilling()
            ->whereHas('visit');   // exclude zombie row (visit soft-deleted)

        // Superadmin melihat seluruh antrean DOKTER. Dokter biasa hanya melihat
        // pasien yang memilih dirinya saat admisi
        // (visits.doctor_schedule_id → doctor_schedules.employee_id).
        if (! $user?->isSuperadmin()) {
            $employeeId = $user?->employee_id;
            $query->whereHas('visit.doctorSchedule', function ($q) use ($employeeId) {
                // employeeId null (user tanpa employee) → tidak match apa pun → antrean kosong.
                $q->where('employee_id', $employeeId);
            });
        }

        return $query->orderBy('queue_sequence')->get();
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)
            ->with('visit.doctorSchedule')
            ->findOrFail($queueId);
        $this->authorizeQueueOwnership($queue);

        return $this->queueService->panggil($queue->id);
    }

    /**
     * Lewati/skip pasien di antrean DOKTER → turun 1 posisi (tukar queue_sequence
     * dengan pasien aktif berikutnya). Delegasi ke QueueService::lewati agar
     * otoritatif di server + broadcast TV (sumber tunggal reorder antrean).
     */
    public function lewatiAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)
            ->with('visit.doctorSchedule')
            ->findOrFail($queueId);
        $this->authorizeQueueOwnership($queue);

        return $this->queueService->lewati($queue->id);
    }

    /**
     * Selesai antrian Dokter → advance ke PENUNJANG / BEDAH / KASIR
     * (lihat QueueService::resolveNextStation Section 11.3).
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)
            ->with('visit.doctorSchedule')
            ->findOrFail($queueId);
        $this->authorizeQueueOwnership($queue);

        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_DOKTER);
    }

    /**
     * Kirim pasien ke pemeriksaan penunjang: baris DOKTER di-pause (status DI_PENUNJANG)
     * dan diturunkan ke paling bawah antrean. Baris tetap milik dokter — saat semua
     * order penunjang selesai, PenunjangService menaikkannya kembali (SELESAI_PENUNJANG).
     */
    public function kirimKePenunjang(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)
            ->with('visit.doctorSchedule')
            ->findOrFail($queueId);
        $this->authorizeQueueOwnership($queue);

        $hasOpenOrder = DiagnosticOrder::where('visit_id', $queue->visit_id)
            ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
            ->exists();
        if (! $hasOpenOrder) {
            throw new \Exception('Belum ada order penunjang untuk pasien ini.', 422);
        }

        $maxSeq = Queue::byStation(Queue::STATION_DOKTER)
            ->whereDate('created_at', today())
            ->max('queue_sequence') ?? 0;

        $queue->update([
            'status'         => Queue::STATUS_AT_PENUNJANG,
            'queue_sequence' => $maxSeq + 1,
            'called_at'      => null,
            'started_at'     => null,
        ]);

        return $queue->fresh(['visit.patient']);
    }

    /**
     * Pastikan queue ini milik dokter yang sedang login.
     * Superadmin dikecualikan (boleh memanggil/menyelesaikan antrean siapa pun).
     * Dokter lain → tolak (403), konsisten dengan filter di getPatientQueue().
     */
    private function authorizeQueueOwnership(Queue $queue): void
    {
        $this->assertOwnedByCurrentDoctor($queue->visit?->doctorSchedule?->employee_id);
    }

    /**
     * Pastikan dokter login berhak atas kunjungan (visit) ini, lalu kembalikan
     * Visit-nya (doctorSchedule sudah ter-load) agar bisa dipakai ulang pemanggil.
     * Dipakai semua endpoint per-visit supaya dokter tidak bisa melihat / mengubah
     * pasien milik dokter lain. Superadmin dikecualikan.
     */
    private function authorizeVisitOwnership(string $visitId): Visit
    {
        $visit = Visit::with('doctorSchedule')->findOrFail($visitId);
        $this->assertOwnedByCurrentDoctor($visit->doctorSchedule?->employee_id);

        return $visit;
    }

    /**
     * Inti pengecekan kepemilikan: bandingkan employee_id pemilik kunjungan dengan
     * dokter login. Superadmin selalu lolos; pemilik null / berbeda → 403.
     */
    private function assertOwnedByCurrentDoctor(?string $ownerEmployeeId): void
    {
        $user = auth('api')->user();
        if ($user?->isSuperadmin()) {
            return;
        }

        if (! $ownerEmployeeId || $ownerEmployeeId !== $user?->employee_id) {
            throw new \Exception('Pasien ini bukan pasien Anda.', 403);
        }
    }

    /**
     * Tolak penulisan Tab 3 (tindakan/resep) bila pemeriksaan sudah difinalisasi.
     * Melindungi dari race autosave yang nyangkut menulis setelah finalize —
     * tanpa guard ini tindakan/resep bisa hilang/berubah dari tagihan kasir.
     */
    private function assertNotFinalized(string $visitId): void
    {
        $examination = DoctorExamination::where('visit_id', $visitId)->first();
        if ($examination && $examination->is_finalized) {
            throw new \Exception('Pemeriksaan sudah dikunci/difinalisasi, perubahan tindakan/resep ditolak.', 422);
        }
    }

    // =========================================================================
    // TAB 1 — DATA PASIEN (READONLY: triase + refraksi)
    // =========================================================================

    public function getPatientData(string $visitId): Visit
    {
        $this->authorizeVisitOwnership($visitId);

        return Visit::with([
            'patient',
            'insurer',
            'queues'            => fn ($q) => $q->where('station', 'DOKTER'),
            'nurseAssessment'   => fn ($q) => $q->with('assessedBy'),
            'refractionRecord'  => fn ($q) => $q->with(['examinedBy', 'prescription']),
            'iolRecommendations',
            'diagnosticOrders'  => fn ($q) => $q->with('results'),
            'doctorExamination' => fn ($q) => $q->with(['doctor', 'surgeryPackage']),
        ])->findOrFail($visitId);
    }

    // =========================================================================
    // TAB 2 — ANAMNESE + SEGMEN ANTERIOR/POSTERIOR
    // =========================================================================

    public function getTab2(string $visitId): ?DoctorExamination
    {
        $this->authorizeVisitOwnership($visitId);

        return DoctorExamination::where('visit_id', $visitId)->first();
    }

    /**
     * Create Tab 2 (anamnese + segmen). One per visit.
     */
    public function storeExamination(string $visitId, array $data): DoctorExamination
    {
        $this->authorizeVisitOwnership($visitId);

        if (DoctorExamination::where('visit_id', $visitId)->exists()) {
            throw new \Exception('Data pemeriksaan sudah ada. Gunakan update.', 422);
        }

        $user = auth('api')->user();

        $examination = DoctorExamination::create([
            'visit_id'  => $visitId,
            'doctor_id' => $user->employee_id,

            'anamnese'       => $data['anamnese'] ?? null,

            // Palpebra (anterior, di atas Kornea)
            'sa_palpebra_od' => $data['sa_palpebra_od'] ?? null,
            'sa_palpebra_os' => $data['sa_palpebra_os'] ?? null,
            // Segmen Anterior OD
            'sa_kornea_od' => $data['sa_kornea_od'] ?? null,
            'sa_coa_od'    => $data['sa_coa_od'] ?? null,
            'sa_iris_od'   => $data['sa_iris_od'] ?? null,
            'sa_pupil_od'  => $data['sa_pupil_od'] ?? null,
            'sa_lensa_od'  => $data['sa_lensa_od'] ?? null,
            // Segmen Anterior OS
            'sa_kornea_os' => $data['sa_kornea_os'] ?? null,
            'sa_coa_os'    => $data['sa_coa_os'] ?? null,
            'sa_iris_os'   => $data['sa_iris_os'] ?? null,
            'sa_pupil_os'  => $data['sa_pupil_os'] ?? null,
            'sa_lensa_os'  => $data['sa_lensa_os'] ?? null,
            // Catatan bebas segmen anterior
            'sa_notes'     => $data['sa_notes'] ?? null,

            // Segmen Posterior OD
            'sp_papil_od'    => $data['sp_papil_od'] ?? null,
            'sp_macula_od'   => $data['sp_macula_od'] ?? null,
            'sp_retina_od'   => $data['sp_retina_od'] ?? null,
            'sp_vitreous_od' => $data['sp_vitreous_od'] ?? null,
            // Segmen Posterior OS
            'sp_papil_os'    => $data['sp_papil_os'] ?? null,
            'sp_macula_os'   => $data['sp_macula_os'] ?? null,
            'sp_retina_os'   => $data['sp_retina_os'] ?? null,
            'sp_vitreous_os' => $data['sp_vitreous_os'] ?? null,
            // Catatan bebas segmen posterior
            'sp_notes'       => $data['sp_notes'] ?? null,

            // Diagnosis (ICD-10 + naratif) + kode ICD-9 tindakan — pindah ke Tab 2.
            // Nullable saat simpan; diwajibkan hanya saat Finalisasi.
            'diagnosis_utama'    => $data['diagnosis_utama'] ?? null,
            'diagnosis_sekunder' => $data['diagnosis_sekunder'] ?? [],
            'diagnosis_text'     => $data['diagnosis_text'] ?? null,
            'tindakan_codes'     => $data['tindakan_codes'] ?? [],

            'is_finalized' => false,
        ]);

        $this->log($user->id, 'STORE_TAB2', DoctorExamination::class, $examination->id, "Tab 2 dibuat untuk kunjungan {$visitId}");

        return $examination->load('doctor');
    }

    public function updateExamination(string $visitId, array $data): DoctorExamination
    {
        $this->authorizeVisitOwnership($visitId);

        $examination = DoctorExamination::where('visit_id', $visitId)->firstOrFail();

        if ($examination->is_finalized) {
            throw new \Exception('Pemeriksaan sudah dikunci, tidak bisa diubah.', 422);
        }

        $examination->update(array_intersect_key($data, array_flip($examination->getFillable())));

        $this->log(auth('api')->id(), 'UPDATE_TAB2', DoctorExamination::class, $examination->id);

        return $examination->fresh('doctor');
    }

    // =========================================================================
    // TAB 3 — TINDAKAN + RESEP OBAT
    // =========================================================================

    /**
     * Daftar tindakan + tarif sesuai metode bayar kunjungan (guarantor_type + insurer).
     * Tarif diresolusi pakai logika kanonik KasirService::getPrice (fallback 3-level).
     */
    public function getTarifTindakan(string $visitId): array
    {
        $visit = $this->authorizeVisitOwnership($visitId);

        return Procedure::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category'])
            ->map(fn ($p) => [
                'id'       => $p->id,
                'code'     => $p->code,
                'name'     => $p->name,
                'category' => $p->category,
                'price'    => $this->kasirService->getPrice('procedure', $p->id, $visit->guarantor_type, $visit->insurer_id),
            ])
            ->all();
    }

    /**
     * Daftar obat aktif untuk e-resep dokter. LEFT JOIN ke medication_tariffs
     * (baris insurer UMUM = harga jual tunggal di Buku Tarif): obat yang belum
     * di-set harga tetap MUNCUL & bisa diresepkan dengan hja=0 (konsisten dengan
     * perilaku Tarif Tindakan). Harga final tetap di-resolve di kasir (getPrice);
     * hja di sini hanya hint estimasi untuk dokter.
     */
    /**
     * Query dasar daftar obat ber-harga (dipakai versi flat & paginated).
     * Stok = on-hand lokasi FARMASI (inventory_stocks), BUKAN medications.stock
     * legacy. Harga jual = baris insurer UMUM di medication_tariffs. Menampilkan
     * SEMUA obat (selaras Farmasi getStokObat yg TIDAK filter is_active); obat
     * nonaktif tetap muncul, ditandai is_active=false agar FE beri badge "nonaktif".
     */
    private function daftarObatQuery(?string $search = null, bool $farmasiOnly = false)
    {
        $farmasiStock = DB::table('inventory_stocks')
            ->select('item_id', DB::raw('SUM(qty_on_hand) as qty'))
            ->where('item_type', InventoryStock::TYPE_MEDICATION)
            ->where('location', InventoryStock::LOC_FARMASI)
            ->groupBy('item_id');

        $umumId = \App\Models\Insurer::where('is_system', true)->where('type', 'UMUM')->value('id');

        return DB::table('medications as m')
            ->leftJoin('medication_tariffs as ip', function ($j) use ($umumId) {
                $j->on('ip.medication_id', '=', 'm.id')
                  ->where('ip.insurer_id', '=', $umumId)
                  ->where('ip.is_active', '=', true)
                  ->whereNull('ip.deleted_at');
            })
            // $farmasiOnly = true → INNER join: hanya obat yang TERDAFTAR di inventori
            // unit Farmasi (punya baris inventory_stocks lokasi FARMASI, termasuk stok 0).
            // Default leftJoin: seluruh master (picker Dokter) — perilaku lama dipertahankan.
            ->when(
                $farmasiOnly,
                fn ($q) => $q->joinSub($farmasiStock, 'fs', fn ($j) => $j->on('fs.item_id', '=', 'm.id')),
                fn ($q) => $q->leftJoinSub($farmasiStock, 'fs', fn ($j) => $j->on('fs.item_id', '=', 'm.id')),
            )
            ->whereNull('m.deleted_at')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('m.name', 'ilike', "%{$search}%")
                      ->orWhere('m.code', 'ilike', "%{$search}%")
                      ->orWhere('m.generic_name', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('m.name');
    }

    /** Map satu baris query daftar obat → bentuk yang dipakai FE picker resep. */
    private function mapObatRow($r): array
    {
        return [
            'id'        => $r->id,
            'code'      => $r->code,
            'name'      => $r->name,
            'form'      => $r->form_sediaan,
            'golongan'  => $r->golongan,
            'unit'      => $r->unit,
            'stock'     => (float) ($r->farmasi_qty ?? 0),
            'hja'       => (float) $r->hja,
            'is_active' => (bool) $r->is_active,
        ];
    }

    private const OBAT_COLS = ['m.id', 'm.code', 'm.name', 'm.form_sediaan', 'm.golongan', 'm.unit', 'm.is_active', 'ip.price as hja', 'fs.qty as farmasi_qty'];

    /**
     * Versi FLAT (legacy) — dipakai Bedah & RuangTindakan via delegasi. Tetap
     * dibatasi 100 baris (picker mereka filter sisi-klien). Jangan ubah shape-nya.
     */
    public function getDaftarObat(?string $search = null, bool $farmasiOnly = false): array
    {
        return $this->daftarObatQuery($search, $farmasiOnly)
            ->limit(100)
            ->get(self::OBAT_COLS)
            ->map(fn ($r) => $this->mapObatRow($r))
            ->all();
    }

    /**
     * Versi PAGINATED + server-side search — dipakai DokterView (resep). Tanpa cap
     * 100: seluruh master obat (500+) terjangkau lewat halaman / pencarian server.
     * Return: ['items'=>[], 'total'=>N, 'page'=>p, 'per_page'=>pp, 'last_page'=>lp].
     */
    public function getDaftarObatPaged(?string $search = null, int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min($perPage, 200));   // clamp wajar utk dropdown
        $page    = max(1, $page);

        $total = (clone $this->daftarObatQuery($search))->count('m.id');
        $items = $this->daftarObatQuery($search)
            ->forPage($page, $perPage)
            ->get(self::OBAT_COLS)
            ->map(fn ($r) => $this->mapObatRow($r))
            ->all();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    public function getVisitServices(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        return VisitService::with('procedure')->where('visit_id', $visitId)->get();
    }

    /**
     * Replace seluruh tindakan kunjungan dengan daftar baru (sinkron dgn UI dokter).
     * Aman: di tahap dokter belum ada billing yang merujuk visit_services.
     * Array kosong = hapus semua tindakan.
     */
    public function storeVisitServices(string $visitId, array $services): Collection
    {
        $this->authorizeVisitOwnership($visitId);
        $user = auth('api')->user();

        // Guard race autosave vs finalisasi: autosave Tab 3 yang nyangkut tidak
        // boleh menulis tindakan setelah pemeriksaan dikunci (else tindakan bisa
        // hilang/berubah dari tagihan kasir).
        $this->assertNotFinalized($visitId);
        // Komit billing (Kirim ke Kasir) mengunci tindakan/resep walau RME belum final.
        $this->assertBillingNotCommitted($visitId);

        $created = DB::transaction(function () use ($visitId, $services, $user) {
            // Bersihkan tindakan lama lalu tulis ulang dari daftar terkini.
            VisitService::where('visit_id', $visitId)->delete();

            $rows = [];
            foreach ($services as $item) {
                $rows[] = VisitService::create([
                    'visit_id'        => $visitId,
                    'procedure_id'    => $item['procedure_id'],
                    'performed_by_id' => $user->employee_id,
                    'quantity'        => $item['quantity'] ?? 1,
                    'price'           => $item['price'] ?? 0,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            $this->log($user->id, 'STORE_TINDAKAN', Visit::class, $visitId, count($rows) . ' tindakan disimpan (replace)');

            return Collection::make($rows)->load('procedure');
        });

        // Revisi pasca-kirim: bila invoice (belum bayar) sudah ada, bangun ulang kwitansi
        // agar perubahan tindakan langsung tercermin.
        $this->reconsolidateAfterDoctorRevision($visitId);

        return $created;
    }

    public function deleteVisitService(string $id): void
    {
        $service = VisitService::findOrFail($id);
        $this->authorizeVisitOwnership($service->visit_id);
        $service->delete();
        $this->log(auth('api')->id(), 'DELETE_TINDAKAN', VisitService::class, $id);
    }

    public function getPrescriptions(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        $prescriptions = Prescription::with('items.medication')->where('visit_id', $visitId)->get();

        // Lampirkan harga per item dari Buku Tarif sesuai penjamin kunjungan (sumber &
        // logika sama dgn tagihan kasir, KasirService::getPrice) → dokter melihat nominal
        // yang AKAN ditagihkan (BPJS/UMUM), bukan sekadar HJA inventori. Atribut dinamis
        // `resolved_price` ikut terserialisasi ke JSON.
        $visit = Visit::find($visitId);
        $guarantor = $visit?->guarantor_type ?? 'UMUM';
        $insurerId = $visit?->insurer_id;
        foreach ($prescriptions as $rx) {
            foreach ($rx->items as $item) {
                $item->resolved_price = $this->kasirService->getPrice(
                    'medication', $item->medication_id, $guarantor, $insurerId
                );
            }
        }

        return $prescriptions;
    }

    /**
     * Replace resep DRAFT kunjungan dengan daftar baru (sinkron dgn UI dokter).
     * Hanya menyentuh resep berstatus DRAFT (yang sudah SUBMITTED/DISPENSING tidak diutak-atik).
     * items kosong = kosongkan resep (hapus draft, tidak buat baru).
     */
    public function storePrescription(string $visitId, array $data): ?Prescription
    {
        $this->authorizeVisitOwnership($visitId);
        $user = auth('api')->user();

        // Guard race autosave vs finalisasi (lihat storeVisitServices).
        $this->assertNotFinalized($visitId);
        $this->assertBillingNotCommitted($visitId);

        $items = $data['items'] ?? [];

        // Resep WAJIB punya peresep (prescriptions.prescribed_by_id NOT NULL, FK
        // employees). Akun tanpa employee (mis. Superadmin) tidak boleh membuat
        // resep berisi item — beri 422 jelas, bukan biarkan jadi 23502/500.
        // Pengosongan resep (items kosong) tetap diizinkan tanpa employee.
        if (!empty($items) && !$user->employee_id) {
            throw new \Exception('Akun Anda tidak terhubung ke data pegawai/dokter, sehingga tidak bisa membuat resep. Silakan login dengan akun dokter.', 422);
        }

        // Revisi pasca "Kirim ke Kasir": resep rawat jalan yang sudah diserahkan Farmasi
        // (DISPENSING/DISPENSED) tak boleh diubah — obat sudah keluar. Blok dengan jelas.
        $sudahDispense = Prescription::where('visit_id', $visitId)
            ->where('type', '!=', Prescription::TYPE_RANAP)
            ->whereIn('status', ['DISPENSING', 'DISPENSED'])
            ->exists();
        if ($sudahDispense) {
            throw new \Exception('Obat sudah diserahkan Farmasi, tidak bisa diubah.', 422);
        }

        $prescription = DB::transaction(function () use ($visitId, $data, $user, $items) {
            // Bersihkan resep rawat jalan yang masih bisa direvisi (DRAFT atau SUBMITTED)
            // + itemnya. SUBMITTED ikut dibersihkan agar revisi dokter pasca "Kirim ke
            // Kasir" mengganti resep lama (bukan menumpuk). Resep yang sudah diverifikasi
            // Farmasi otomatis ter-reset (delete+recreate → verified_at baru null) → wajib
            // verifikasi ulang. RANAP/CANCELLED/DISPENSING/DISPENSED tak disentuh.
            $drafts = Prescription::where('visit_id', $visitId)
                ->where('type', '!=', Prescription::TYPE_RANAP)
                ->whereIn('status', ['DRAFT', 'SUBMITTED'])
                ->get();
            foreach ($drafts as $d) {
                PrescriptionItem::where('prescription_id', $d->id)->delete();
                $d->delete();
            }

            if (empty($items)) {
                $this->log($user->id, 'STORE_RESEP', Prescription::class, $visitId, 'Resep dikosongkan');
                return null;
            }

            $prescription = Prescription::create([
                'visit_id'         => $visitId,
                'prescribed_by_id' => $user->employee_id,
                'status'           => 'DRAFT',
                'notes'            => $data['notes'] ?? null,
                'pharmacy_note'    => $data['pharmacy_note'] ?? null,
            ]);

            foreach ($items as $item) {
                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    'medication_id'   => $item['medication_id'],
                    'quantity'        => $item['quantity'] ?? 1,
                    'dose'            => $item['dose'] ?? null,
                    'frequency'       => $item['frequency'] ?? null,
                    'route'           => $item['route'] ?? null,
                    'duration_days'   => $item['duration_days'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            $this->log($user->id, 'STORE_RESEP', Prescription::class, $prescription->id, "Resep disimpan (replace) untuk kunjungan {$visitId}");

            return $prescription->load('items.medication');
        });

        // Revisi pasca-kirim: bila invoice (belum bayar) sudah ada, bangun ulang kwitansi
        // agar obat lama/baru tercermin. Obat hasil reset (verified_at null) tak ikut
        // tertagih sampai Farmasi verifikasi ulang (gate KasirService::buildObatLines).
        $this->reconsolidateAfterDoctorRevision($visitId);

        return $prescription;
    }

    // =========================================================================
    // TAB 4 — SOAP + ICD + PLANNING (KRITIS: include follow-up logic)
    // =========================================================================

    public function getTab4(string $visitId): ?DoctorExamination
    {
        $this->authorizeVisitOwnership($visitId);

        return DoctorExamination::with(['doctor', 'surgeryPackage', 'surgerySchedule'])
            ->where('visit_id', $visitId)
            ->first();
    }

    /**
     * Store Tab 4 data. If doctor_examination doesn't exist yet, create it.
     * Handles follow-up logic completely.
     */
    public function storePlanning(string $visitId, array $data): array
    {
        $this->authorizeVisitOwnership($visitId);
        $visit = Visit::with('patient')->findOrFail($visitId);
        $user  = auth('api')->user();

        return DB::transaction(function () use ($visit, $data, $user) {
            // Upsert doctor_examination Tab 4 fields
            $examination = DoctorExamination::firstOrCreate(
                ['visit_id' => $visit->id],
                ['doctor_id' => $user->employee_id]
            );

            if ($examination->is_finalized) {
                throw new \Exception('Pemeriksaan sudah dikunci, tidak bisa diubah.', 422);
            }

            // planning null-guard: payload Tab 3 hanya membawa field planning. Diagnosis
            // (Tab 2) & SOAP (Finalisasi) TIDAK ada di payload ini → pertahankan nilai
            // existing agar tak terhapus. planning sendiri di-guard ke nilai lama bila absen,
            // lalu di-inject balik ke $data agar helper hilir (resolveSurgerySchedule,
            // applyInpatientReason, handlePlanningFollowUp) memakai nilai yang sama.
            $planning = $data['planning'] ?? $examination->planning;
            $data['planning'] = $planning;

            // Planning BEDAH: buat/perbarui SurgerySchedule dari paket + tanggal yang dipilih
            // dokter. Routing ke stasiun BEDAH (jika tanggal = hari ini) bergantung pada
            // surgery_schedule_id ini (lihat QueueService::nextAfterDokter).
            $scheduleId = $this->resolveSurgerySchedule($examination, $data);

            $examination->update([
                'planning'           => $planning,
                'surgery_package_id' => $planning === 'BEDAH' ? ($data['surgery_package_id'] ?? null) : null,
                'surgery_schedule_id' => $scheduleId,
                // Rujukan eksternal non-BPJS: simpan hanya saat planning RUJUK, selain
                // itu bersihkan agar tak ada sisa data bila dokter ganti planning.
                'external_referral_facility' => $planning === 'RUJUK' ? ($data['external_referral_facility'] ?? null) : null,
                'external_referral_reason'   => $planning === 'RUJUK' ? ($data['external_referral_reason'] ?? null) : null,
            ]);

            // HIGH: propagasi surgery_schedule_id ke VISIT. BedahService::startOperation
            // me-resolve visit via Visit::where('surgery_schedule_id', ...). Tanpa baris
            // ini, bedah hari-ini langsung dari dokter (tanpa surgery_request) → visit
            // tak punya schedule → SurgeryRecord orphan, startOperation gagal update antrean.
            // Planning bukan BEDAH → bersihkan agar tak nyangkut jadwal lama.
            $visit->update([
                'surgery_schedule_id' => $planning === 'BEDAH' ? $scheduleId : null,
            ]);

            // Snapshot paket BEDAH pasien (komponen BHP/IOL/PROCEDURE → dasar diskon
            // paket di kwitansi). Planning bukan BEDAH / tanpa paket → snapshot dibuang.
            $this->syncVisitPackageSnapshot(
                $visit,
                $planning === 'BEDAH' ? ($data['surgery_package_id'] ?? null) : null,
                $scheduleId,
                VisitSurgeryPackage::TYPE_BEDAH,
                $planning === 'BEDAH' ? ($data['surgery_package_tariff_id'] ?? null) : null,
            );

            // Fase 8 — tandai alasan inap di visit (dibaca admisi & papan RANAP):
            //   RAWAT_INAP            → OBSERVASI (inap pemeriksaan tanpa operasi).
            //   BEDAH + perlu inap    → PRE_OP    (pasien datang H-1, lalu operasi).
            //   planning lain / batal → null      (bukan kandidat inap).
            $this->applyInpatientReason($visit, $data);

            // Handle planning-specific side-effects
            $this->handlePlanningFollowUp($visit, $data, $examination);

            $this->log($user->id, 'STORE_TAB4', DoctorExamination::class, $examination->id, "Planning: {$planning} — kunjungan {$visit->id}");

            return [
                'examination' => $examination->fresh(['doctor', 'surgeryPackage']),
                'visit'       => $visit->fresh(),
            ];
        });
    }

    public function updatePlanning(string $visitId, array $data): array
    {
        return $this->storePlanning($visitId, $data);
    }

    /**
     * Snapshot paket pasien (generik — dipakai paket BEDAH via storePlanning &
     * paket PEMERIKSAAN via applyExaminationPackage).
     *
     * Komponen paket master di-COPY jadi milik pasien ini (TANPA MEDICATION).
     * unit_price tiap komponen di-resolve dari Buku Tarif per penjamin pasien.
     * Snapshot = dasar diskon paket di kwitansi (BUKAN sumber tagih).
     *
     * packageId kosong → soft-delete snapshot existing (mis. dokter batal paket).
     * Idempoten: paket SAMA → replace header + items (pola withTrashed→restore);
     *            paket BERBEDA → TAMBAH snapshot baru (multi-paket per visit, mis.
     *            paket tindakan Phaco + paket anestesi TIVA).
     * Dipanggil DI DALAM transaksi pemanggil.
     *
     * @param string|null $clearType saat $packageId null — batasi soft-delete ke
     *        snapshot package_type ini (default BEDAH: jalur planning dokter membatalkan
     *        paket bedah saja, jangan ikut hapus paket PEMERIKSAAN / paket lain). null =
     *        hapus SEMUA snapshot visit.
     */
    public function syncVisitPackageSnapshot(Visit $visit, ?string $packageId, ?string $scheduleId = null, ?string $clearType = VisitSurgeryPackage::TYPE_BEDAH, ?string $selectedTariffId = null): void
    {
        // Tanpa paket → buang snapshot (default: hanya tipe yg di-scope, mis. BEDAH).
        if (! $packageId) {
            $q = VisitSurgeryPackage::where('visit_id', $visit->id);
            if ($clearType !== null) {
                $q->where('package_type', $clearType);
            }
            $q->delete();
            return;
        }

        $pkg = SurgeryPackage::with('items')->find($packageId);
        if (! $pkg) {
            return; // paket tak ditemukan — jangan gagalkan planning
        }

        // Resolve baris tarif penjamin pasien: harga + nama tampil khusus (mis. promo UMUM).
        // $selectedTariffId = varian yg dipilih dokter saat planning (1 penjamin bisa >1 varian).
        $tariff    = $this->kasirService->resolvePackageTariff(
            $pkg->id, $visit->guarantor_type, $visit->insurer_id, $selectedTariffId
        );
        $sellPrice = (float) ($tariff?->sell_price ?? 0);

        // Header per-(visit, source_package): withTrashed → restore/update (anti unique
        // vsp_visit_source_unique 23505) bila paket sama, atau create bila paket baru.
        $snap = VisitSurgeryPackage::withTrashed()
            ->where('visit_id', $visit->id)
            ->where('source_surgery_package_id', $pkg->id)
            ->first();
        $headerData = [
            'surgery_schedule_id'       => $scheduleId,
            'source_surgery_package_id' => $pkg->id,
            // Varian tarif terpilih (null = default). Sumber id = baris yg benar2 di-resolve.
            'surgery_package_tariff_id' => $tariff->id ?? null,
            'package_type'              => $pkg->package_type ?? VisitSurgeryPackage::TYPE_BEDAH,
            'package_name'              => $pkg->name,
            'package_code'              => $pkg->code,
            'sell_price'                => $sellPrice,
            // Nama tampil per-penjamin → effectiveLabel() (kwitansi & papan bedah). Null = pakai nama master.
            'label'                     => $tariff?->display_name ?: null,
            'is_active'                 => true,
        ];
        if ($snap) {
            if ($snap->trashed()) {
                $snap->restore();
            }
            $snap->update($headerData);
        } else {
            $snap = VisitSurgeryPackage::create(['visit_id' => $visit->id] + $headerData);
        }

        // Replace items: copy komponen paket, resolve harga Buku Tarif.
        $isPemeriksaan = ($pkg->package_type === SurgeryPackage::TYPE_PEMERIKSAAN);
        $snap->items()->delete();
        foreach ($pkg->items as $pi) {
            // Obat: hanya disnapshot untuk paket PEMERIKSAAN (daftar "ekspektasi" untuk
            // absorpsi diskon — obat tetap ditagih lewat resep, bukan dari snapshot).
            // Paket BEDAH: obat lewat resep/obat pulang, tak masuk snapshot.
            if ($pi->item_type === 'MEDICATION' && ! $isPemeriksaan) {
                continue;
            }
            $getPriceType = match ($pi->item_type) {
                'PROCEDURE'  => 'procedure',
                'BHP'        => 'bhp',
                'IOL'        => 'iol',
                'MEDICATION' => 'medication',
                default      => null,
            };
            if (! $getPriceType) {
                continue;
            }
            $unitPrice = $this->kasirService->getPrice(
                $getPriceType, $pi->item_id, $visit->guarantor_type, $visit->insurer_id
            );
            VisitSurgeryPackageItem::create([
                'visit_surgery_package_id' => $snap->id,
                'item_type'                => $pi->item_type,
                'item_id'                  => $pi->item_id,
                'quantity'                 => $pi->quantity ?? 1,
                'unit_price'               => $unitPrice,
                'notes'                    => $pi->notes ?? null,
            ]);
        }

        $snap->recalcTotalBasePrice();
        $this->log(auth('api')->id(), 'SNAPSHOT_VISIT_PACKAGE', VisitSurgeryPackage::class, $snap->id, "visit:{$visit->id} pkg:{$pkg->id}");
    }

    /**
     * Terapkan PAKET PEMERIKSAAN (poliklinik) ke kunjungan: merge komponen tindakan
     * paket ke visitServices (yang ditagih) + buat snapshot diskon. 1 transaksi.
     *
     * - Hanya paket package_type=PEMERIKSAAN (tolak paket BEDAH lewat jalur ini).
     * - Tindakan paket digabung ke daftar visitServices yang ADA (tak menghapus
     *   tindakan manual dokter). Harga = Buku Tarif per penjamin.
     * - Snapshot dibuat lewat syncVisitPackageSnapshot (komponen PROCEDURE).
     */
    public function applyExaminationPackage(string $visitId, string $packageId): array
    {
        $this->authorizeVisitOwnership($visitId);
        $this->assertNotFinalized($visitId);
        $visit = Visit::findOrFail($visitId);

        $pkg = SurgeryPackage::with('items')->findOrFail($packageId);
        if (($pkg->package_type ?? SurgeryPackage::TYPE_BEDAH) !== SurgeryPackage::TYPE_PEMERIKSAAN) {
            throw new \Exception('Hanya paket pemeriksaan yang dapat diterapkan di sini.', 422);
        }

        return DB::transaction(function () use ($visit, $pkg) {
            // Daftar tindakan saat ini (procedure_id => row) supaya tak hilang & tak dobel.
            $current = VisitService::where('visit_id', $visit->id)->get();
            $byProc  = $current->keyBy('procedure_id');

            // Merge komponen PROCEDURE paket ke list (harga Buku Tarif per penjamin).
            $services = $current->map(fn ($vs) => [
                'procedure_id' => $vs->procedure_id,
                'quantity'     => $vs->quantity,
                'price'        => $vs->price,
            ])->values()->all();

            foreach ($pkg->items as $pi) {
                if ($pi->item_type !== 'PROCEDURE') {
                    continue; // paket pemeriksaan komponen = PROCEDURE
                }
                if ($byProc->has($pi->item_id)) {
                    continue; // sudah ada di daftar — jangan dobel
                }
                $price = $this->kasirService->getPrice('procedure', $pi->item_id, $visit->guarantor_type, $visit->insurer_id);
                $services[] = [
                    'procedure_id' => $pi->item_id,
                    'quantity'     => $pi->quantity ?? 1,
                    'price'        => $price,
                ];
            }

            // Replace seluruh list (storeVisitServices = replace; union sudah dihitung).
            $this->storeVisitServices($visit->id, $services);

            // Poliklinik = 1 paket pemeriksaan: ganti paket PEMERIKSAAN lama (bila ada
            // & beda) sebelum snapshot baru. Paket BEDAH/anestesi tak terganggu.
            VisitSurgeryPackage::where('visit_id', $visit->id)
                ->where('package_type', VisitSurgeryPackage::TYPE_PEMERIKSAAN)
                ->where('source_surgery_package_id', '!=', $pkg->id)
                ->delete();

            // Snapshot diskon (komponen PROCEDURE) — tanpa schedule (poli).
            $this->syncVisitPackageSnapshot($visit, $pkg->id, null);

            $snap = VisitSurgeryPackage::with('items')
                ->where('visit_id', $visit->id)
                ->where('source_surgery_package_id', $pkg->id)
                ->first();
            // Obat "ekspektasi" paket (hint utk dokter — TIDAK auto-resep; obat terserap ke
            // diskon saat dokter benar-benar meresepkannya).
            $medHint = $pkg->items->where('item_type', 'MEDICATION')->map(function ($pi) {
                $med = \App\Models\Medication::find($pi->item_id);
                return [
                    'medication_id' => $pi->item_id,
                    'name'          => $med?->name ?? 'Obat',
                    'quantity'      => (int) ($pi->quantity ?? 1),
                ];
            })->values()->all();

            return [
                'visit_services' => $this->getVisitServices($visit->id),
                'snapshot'       => $snap ? [
                    'id'               => $snap->id,
                    'package_name'     => $snap->package_name,
                    'package_type'     => $snap->package_type,
                    'label'            => $snap->label,
                    'sell_price'       => (float) $snap->sell_price,
                    'total_base_price' => (float) $snap->total_base_price,
                    'discount_amount'  => $snap->discountAmount(),
                ] : null,
                'package_medications' => $medHint,
            ];
        });
    }

    /** Lepas paket pemeriksaan: buang snapshot PEMERIKSAAN saja (tindakan dibiarkan,
     *  dokter kelola manual). Paket BEDAH/lain di visit yang sama tak terganggu. */
    public function removeExaminationPackage(string $visitId): void
    {
        $this->authorizeVisitOwnership($visitId);
        $this->assertNotFinalized($visitId);
        VisitSurgeryPackage::where('visit_id', $visitId)
            ->where('package_type', VisitSurgeryPackage::TYPE_PEMERIKSAAN)
            ->delete();
        $this->log(auth('api')->id(), 'REMOVE_VISIT_PACKAGE', VisitSurgeryPackage::class, $visitId, "visit:{$visitId}");
    }

    /**
     * Fase 8 — set visits.inpatient_reason dari planning Tab 4.
     *   RAWAT_INAP         → OBSERVASI (inap pemeriksaan/observasi tanpa operasi).
     *   BEDAH + perlu inap → PRE_OP    (inap karena operasi; pasien datang H-1).
     *   selain itu         → null      (bukan kandidat inap — bersihkan bila ganti planning).
     */
    private function applyInpatientReason(Visit $visit, array $data): void
    {
        $planning = $data['planning'] ?? null;

        $reason = null;
        if ($planning === 'RAWAT_INAP') {
            $reason = 'OBSERVASI';
        } elseif ($planning === 'BEDAH' && ! empty($data['requires_inpatient'])) {
            $reason = 'PRE_OP';
        }

        $visit->update(['inpatient_reason' => $reason]);
    }

    /**
     * Handle all follow-up side effects after planning is saved.
     */
    private function handlePlanningFollowUp(Visit $visit, array $data, DoctorExamination $examination): void
    {
        $hasFollowUp = ! empty($data['follow_up_date']);

        if ($hasFollowUp) {
            // 1. Update visit follow-up fields
            $visit->update([
                'planning_follow_up' => true,
                'follow_up_date'     => $data['follow_up_date'],
                'follow_up_reason'   => $data['follow_up_reason'] ?? null,
            ]);

            // 2. Append to medical_resume.resume_p (if resume exists)
            $resume = MedicalResume::where('visit_id', $visit->id)->first();
            if ($resume && $resume->is_editable) {
                $appendText = "\nKontrol Ulang: {$data['follow_up_date']}";
                if (! empty($data['follow_up_reason'])) {
                    $appendText .= " — {$data['follow_up_reason']}";
                }
                $resume->update(['resume_p' => $resume->resume_p . $appendText]);
            }

            // 3. If BPJS → create BpjsControlLetter (DRAFT)
            if ($visit->guarantor_type === 'BPJS') {
                $existingLetter = BpjsControlLetter::where('visit_id', $visit->id)
                    ->whereNotIn('status', ['SUBMITTED', 'SUCCESS'])
                    ->first();

                if (! $existingLetter) {
                    BpjsControlLetter::create([
                        'visit_id'                => $visit->id,
                        'tanggal_rencana_kontrol' => $data['follow_up_date'],
                        'status'                  => 'DRAFT',
                        'is_notified_expired'     => false,
                    ]);
                } else {
                    $existingLetter->update(['tanggal_rencana_kontrol' => $data['follow_up_date']]);
                }
            }

            // 4. Create PatientDocument (Surat Kontrol) — DRAFT
            $docType = DocumentType::where('code', 'FOLLOW_UP_LETTER')
                ->orWhere('name', 'like', '%Surat Kontrol%')
                ->first();

            if ($docType) {
                PatientDocument::firstOrCreate(
                    [
                        'visit_id'         => $visit->id,
                        'document_type_id' => $docType->id,
                    ],
                    [
                        'patient_id'           => $visit->patient_id,
                        'status'               => 'DRAFT',
                        'created_by_station'   => 'DOKTER',
                        'pending_signature_roles' => ['DOCTOR'],
                        'signatures'           => [],
                        'printed_count'        => 0,
                    ]
                );
            }
        } else {
            // Clear follow-up fields
            $visit->update([
                'planning_follow_up' => false,
                'follow_up_date'     => null,
                'follow_up_reason'   => null,
            ]);
        }
    }

    /**
     * Tentukan surgery_schedule_id untuk planning Tab 4.
     *
     * - planning != BEDAH               → null (dan jadwal lama yang masih SCHEDULED dibatalkan).
     * - surgery_schedule_id eksplisit   → dipakai apa adanya (preop flow / pilih jadwal existing).
     * - BEDAH + paket + tanggal         → buat baru, atau perbarui jadwal yang sudah terhubung
     *                                     ke examination ini selama belum mulai (status SCHEDULED).
     * - BEDAH tanpa paket/tanggal       → biarkan jadwal lama apa adanya (dokter belum lengkap isi).
     */
    private function resolveSurgerySchedule(DoctorExamination $examination, array $data): ?string
    {
        // Bukan bedah → lepas & batalkan jadwal yang sebelumnya dibuat dari examination ini.
        if (($data['planning'] ?? null) !== 'BEDAH') {
            if ($examination->surgery_schedule_id) {
                SurgerySchedule::where('id', $examination->surgery_schedule_id)
                    ->where('status', 'SCHEDULED')
                    ->update(['status' => 'CANCELLED']);
            }
            return null;
        }

        // Jadwal dipilih eksplisit (mis. preop flow) → hormati.
        if (! empty($data['surgery_schedule_id'])) {
            return $data['surgery_schedule_id'];
        }

        $packageId = $data['surgery_package_id'] ?? null;
        $date      = $data['surgery_date'] ?? null;

        // Lokasi pelaksanaan: RUANG_BEDAH (operasi) | RUANG_TINDAKAN (laser YAG/PRP).
        $locationType = ($data['location_type'] ?? null) === SurgerySchedule::LOCATION_RUANG_TINDAKAN
            ? SurgerySchedule::LOCATION_RUANG_TINDAKAN
            : SurgerySchedule::LOCATION_RUANG_BEDAH;
        $isTindakan = $locationType === SurgerySchedule::LOCATION_RUANG_TINDAKAN;

        // Syarat minimal membuat jadwal:
        //   - Operasi (RUANG_BEDAH)      → paket + tanggal WAJIB.
        //   - Tindakan laser (RUANG_TINDAKAN) → cukup tanggal; paket OPSIONAL
        //     (laser ditagih via procedure/visit_services, bukan komponen paket).
        // Belum lengkap → pertahankan jadwal lama (kalau ada).
        if (! $date || (! $isTindakan && ! $packageId)) {
            return $examination->surgery_schedule_id;
        }

        // Default ruang OK dari Profil Klinik (ambil yang pertama bila ada).
        $defaultRoom = ClinicProfile::query()->value('operating_rooms');
        $defaultRoom = is_array($defaultRoom) ? ($defaultRoom[0] ?? null) : null;

        $payload = [
            'surgery_package_id' => $packageId,
            'location_type'      => $locationType,
            'scheduled_date'     => $date,
            'scheduled_time'     => $data['surgery_time'] ?? null,
            'operation_room'     => $data['operation_room'] ?? $defaultRoom,
            'status'             => 'SCHEDULED',
            // Fase 8 — pre-op H-1: jadwal bedah ini butuh rawat inap.
            // Tindakan laser tak pakai pre-op inap → paksa false.
            'requires_inpatient' => ! $isTindakan && (bool) ($data['requires_inpatient'] ?? false),
        ];

        // Perbarui jadwal yang sudah terhubung & belum mulai; selain itu buat baru.
        $existing = $examination->surgery_schedule_id
            ? SurgerySchedule::where('id', $examination->surgery_schedule_id)
                ->where('status', 'SCHEDULED')
                ->first()
            : null;

        if ($existing) {
            $existing->update($payload);
            return $existing->id;
        }

        return SurgerySchedule::create($payload)->id;
    }

    /**
     * Preview ringkas jadwal bedah pada satu tanggal (Tab 4 → Jadwalkan Bedah).
     * Hanya jadwal aktif (status SCHEDULED). Mengembalikan total + daftar jam terisi
     * (untuk menandai slot bentrok di dropdown jam dokter).
     */
    public function getBedahSlot(string $tanggal, ?string $locationType = null): array
    {
        // Pisahkan slot per lokasi: Ruang Bedah (operasi) ≠ Ruang Tindakan (laser).
        // Default (null) = RUANG_BEDAH agar preview operasi tak tercampur jadwal laser.
        $loc = $locationType === SurgerySchedule::LOCATION_RUANG_TINDAKAN
            ? SurgerySchedule::LOCATION_RUANG_TINDAKAN
            : SurgerySchedule::LOCATION_RUANG_BEDAH;

        $rows = SurgerySchedule::with('surgeryPackage:id,name')
            ->whereDate('scheduled_date', $tanggal)
            ->where('status', 'SCHEDULED')
            // location_type null (jadwal lama) dianggap RUANG_BEDAH (backward-compat).
            ->when($loc === SurgerySchedule::LOCATION_RUANG_BEDAH,
                fn ($q) => $q->where(fn ($w) =>
                    $w->where('location_type', SurgerySchedule::LOCATION_RUANG_BEDAH)
                      ->orWhereNull('location_type')),
                fn ($q) => $q->where('location_type', SurgerySchedule::LOCATION_RUANG_TINDAKAN))
            ->orderBy('scheduled_time')
            ->get(['id', 'scheduled_time', 'operation_room', 'surgery_package_id', 'location_type']);

        return [
            'tanggal' => $tanggal,
            'total'   => $rows->count(),
            'slots'   => $rows->map(fn ($s) => [
                'time'         => $s->scheduled_time ? substr($s->scheduled_time, 0, 5) : null,
                'room'         => $s->operation_room,
                'package_name' => $s->surgeryPackage?->name,
            ])->values()->all(),
        ];
    }

    // =========================================================================
    // FOLLOW-UP STANDALONE ENDPOINTS
    // =========================================================================

    public function storeFollowUp(string $visitId, array $data): Visit
    {
        $this->authorizeVisitOwnership($visitId);
        $visit = Visit::findOrFail($visitId);

        if ($visit->guarantor_type !== 'BPJS' && empty($data['follow_up_date'])) {
            throw new \Exception('Tanggal kontrol ulang wajib diisi.', 422);
        }

        $examination = DoctorExamination::where('visit_id', $visitId)->first();

        $this->handlePlanningFollowUp($visit, $data, $examination ?? new DoctorExamination());

        $this->log(auth('api')->id(), 'STORE_FOLLOW_UP', Visit::class, $visitId);

        return $visit->fresh();
    }

    public function updateFollowUp(string $visitId, array $data): Visit
    {
        return $this->storeFollowUp($visitId, $data);
    }

    public function deleteFollowUp(string $visitId): Visit
    {
        $this->authorizeVisitOwnership($visitId);
        $visit = Visit::findOrFail($visitId);

        $visit->update([
            'planning_follow_up' => false,
            'follow_up_date'     => null,
            'follow_up_reason'   => null,
        ]);

        // Revoke draft BPJS control letter if exists
        BpjsControlLetter::where('visit_id', $visitId)
            ->where('status', 'DRAFT')
            ->delete();

        // Soft-delete draft follow-up documents
        $docType = DocumentType::where('code', 'FOLLOW_UP_LETTER')
            ->orWhere('name', 'like', '%Surat Kontrol%')
            ->first();

        if ($docType) {
            PatientDocument::where('visit_id', $visitId)
                ->where('document_type_id', $docType->id)
                ->where('status', 'DRAFT')
                ->delete();
        }

        $this->log(auth('api')->id(), 'DELETE_FOLLOW_UP', Visit::class, $visitId);

        return $visit->fresh();
    }

    // =========================================================================
    // FINALIZE KUNJUNGAN
    // =========================================================================

    public function finalizeKunjungan(string $visitId, array $soap = []): DoctorExamination
    {
        $this->authorizeVisitOwnership($visitId);

        $examination = DoctorExamination::where('visit_id', $visitId)->firstOrFail();

        if ($examination->is_finalized) {
            throw new \Exception('Pemeriksaan sudah dikunci.', 422);
        }

        // Isi SOAP final (subset fillable) sebelum validasi assessment.
        $soapFields = array_intersect_key($soap, array_flip([
            'soap_subjective', 'soap_objective', 'soap_assessment', 'soap_plan',
        ]));
        if ($soapFields) {
            $examination->fill($soapFields);
        }

        // Diagnosa boleh berupa kode ICD-10 utama ATAU teks bebas (dokter ragu kode).
        $hasDiagnosis = $examination->diagnosis_utama
            || trim((string) $examination->diagnosis_text) !== '';
        if (! $hasDiagnosis || ! $examination->planning) {
            throw new \Exception('Diagnosis (kode ICD-10 atau teks) dan planning wajib diisi sebelum mengunci.', 422);
        }
        if (! $examination->soap_assessment) {
            throw new \Exception('Assessment (SOAP) wajib diisi sebelum mengunci.', 422);
        }

        // Tanda tangan digital = identitas akun dokter yang sedang login (otoritatif
        // di server, tidak bergantung input klien). Sekaligus pastikan doctor_id terikat
        // ke penandatangan walau record sempat dibuat oleh tab lain.
        $user      = auth('api')->user();
        $employee  = $user?->employee;
        $signer    = $employee?->name ?? $user?->name ?? 'Dokter';
        if ($employee?->sip) {
            $signer .= " (SIP: {$employee->sip})";
        }

        $examination->fill([
            'is_finalized'        => true,
            'finalized_at'        => now(),
            'digital_signature'   => $signer,
            'signature_timestamp' => now(),
            'doctor_id'           => $examination->doctor_id ?? $employee?->id,
        ]);
        $examination->save();

        // Sinkron alur D→K→F: bila finalisasi LANGSUNG (tanpa "Kirim ke Kasir" Tab 3),
        // resep rawat jalan masih DRAFT. Flip → SUBMITTED (sama spt kirimKeKasir) agar
        // muncul di worklist verifikasi Farmasi & bisa dikunci. Tanpa ini, gate
        // consolidateBilling menolak DRAFT-belum-verified sementara Farmasi tak melihat
        // resep DRAFT → tagihan buntu tanpa jalan keluar.
        Prescription::where('visit_id', $visitId)
            ->where('type', '!=', Prescription::TYPE_RANAP)
            ->where('status', 'DRAFT')
            ->update(['status' => 'SUBMITTED']);

        // Majukan antrean idempoten: bila pasien BELUM pernah "Kirim ke Kasir"
        // (finalisasi langsung tanpa lewat Tab 3) baris DOKTER masih aktif → maju
        // sekarang. Bila sudah maju (COMPLETED) → no-op, tidak melempar.
        $this->advanceDokterQueueIfActive($visitId);

        $this->log(auth('api')->id(), 'FINALIZE_KUNJUNGAN', DoctorExamination::class, $examination->id, "Planning: {$examination->planning}");

        return $examination->fresh(['doctor', 'surgeryPackage']);
    }

    /**
     * Kirim ke Kasir (Tab 3): komit billing & majukan antrean TANPA mengunci RME.
     * Simpan planning (reuse storePlanning: jadwal bedah + inap + follow-up + snapshot)
     * lalu advance idempoten. is_finalized tetap false → segmen/diagnosis/SOAP masih
     * bisa dilengkapi belakangan (buka ulang dari filter "Selesai").
     */
    public function kirimKeKasir(string $visitId, array $data): array
    {
        $result = $this->storePlanning($visitId, $data);

        // Tandai resep rawat jalan (DRAFT) → SUBMITTED: sinyal "dokter selesai, siap
        // diverifikasi Farmasi". verified_at TETAP null → Kasir terkunci sampai Farmasi
        // verifikasi & kunci (lihat KasirService::consolidateBilling gate). RANAP & yang
        // sudah CANCELLED/DISPENSING/DISPENSED tak disentuh.
        Prescription::where('visit_id', $visitId)
            ->where('type', '!=', Prescription::TYPE_RANAP)
            ->where('status', 'DRAFT')
            ->update(['status' => 'SUBMITTED']);

        $advance = $this->advanceDokterQueueIfActive($visitId);

        return array_merge($result, ['advance' => $advance]);
    }

    /**
     * Majukan baris antrean DOKTER kunjungan ini ke stasiun berikutnya — IDEMPOTEN.
     * Cari baris DOKTER yang masih aktif (status ∉ {COMPLETED, CANCELLED}); ada →
     * advanceFromStation; tidak ada → no-op. Kunci agar "Kirim ke Kasir" lalu
     * "Finalisasi" (atau panggilan ganda) tidak melempar "Antrian sudah ditutup".
     *
     * @return array{advanced: bool, next_station?: ?string}
     */
    public function advanceDokterQueueIfActive(string $visitId): array
    {
        $queue = Queue::where('visit_id', $visitId)
            ->where('station', Queue::STATION_DOKTER)
            ->whereNotIn('status', [Queue::STATUS_COMPLETED, Queue::STATUS_CANCELLED])
            ->latest('created_at')
            ->first();

        if (! $queue) {
            return ['advanced' => false];
        }

        $result = $this->queueService->advanceFromStation($queue->id, Queue::STATION_DOKTER);

        return ['advanced' => true, 'next_station' => $result['next_station'] ?? null];
    }

    /**
     * Tolak ubah tindakan/resep HANYA bila pembayaran sudah dikonfirmasi (invoice
     * PAID/PARTIALLY_PAID). Selama belum bayar, dokter boleh "Buka Kembali" Tab 3 untuk
     * revisi obat/tindakan walau pasien sudah dikirim ke kasir — perubahan mengalir ke
     * verifikasi Farmasi & kwitansi (reconsolidate). Batas kunci = PEMBAYARAN, bukan
     * "terkirim ke kasir". Mengunci billing TANPA mengunci RME. Pelengkap assertNotFinalized.
     */
    private function assertBillingNotCommitted(string $visitId): void
    {
        $paid = BillingInvoice::where('visit_id', $visitId)
            ->whereIn('status', ['PAID', 'PARTIALLY_PAID'])
            ->exists();

        if ($paid) {
            throw new \Exception('Pembayaran sudah dikonfirmasi di kasir — perubahan tindakan/resep ditolak. Batalkan/kembalikan dari kasir bila perlu mengubah.', 422);
        }
    }

    /**
     * Status tagihan ringkas untuk dokter — menentukan apakah Tab 3 masih boleh
     * "Buka Kembali" (revisi tindakan/obat). is_paid true → terkunci pembayaran.
     */
    public function getBillingStatus(string $visitId): array
    {
        $this->authorizeVisitOwnership($visitId);

        $invoice = BillingInvoice::where('visit_id', $visitId)
            ->where('status', '!=', 'CANCELLED')
            ->first();

        return [
            'has_invoice' => (bool) $invoice,
            'status'      => $invoice?->status,
            'is_paid'     => $invoice ? in_array($invoice->status, ['PAID', 'PARTIALLY_PAID'], true) : false,
        ];
    }

    /**
     * Invoice aktif (belum dibatalkan) untuk visit ini, bila ada. Dipakai pemicu
     * reconsolidate saat dokter merevisi tindakan/resep pasca "Kirim ke Kasir".
     */
    private function existingActiveInvoice(string $visitId): ?BillingInvoice
    {
        return BillingInvoice::where('visit_id', $visitId)
            ->where('status', '!=', 'CANCELLED')
            ->first();
    }

    /**
     * Setelah dokter merevisi tindakan/resep (Tab 3) saat invoice sudah ada tapi belum
     * dibayar: bangun ulang kwitansi agar mencerminkan perubahan. Invoice FINALIZED
     * dikembalikan ke DRAFT supaya kasir mengonfirmasi ulang. Obat yang belum (atau baru
     * di-reset) verified tidak ikut tertagih (lihat KasirService::buildObatLines). Aman
     * dipanggil tanpa invoice (no-op). Tidak melempar — revisi dokter tak boleh gagal
     * gara-gara rebuild kwitansi.
     */
    private function reconsolidateAfterDoctorRevision(string $visitId): void
    {
        $invoice = $this->existingActiveInvoice($visitId);
        if (! $invoice || in_array($invoice->status, ['PAID', 'PARTIALLY_PAID'], true)) {
            return;
        }
        try {
            if ($invoice->status === 'FINALIZED') {
                $invoice->update(['status' => 'DRAFT']);
            }
            $this->kasirService->reconsolidateInvoice($invoice->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('reconsolidateAfterDoctorRevision gagal: ' . $e->getMessage(), ['visit_id' => $visitId]);
        }
    }

    // =========================================================================
    // ORDER PENUNJANG
    // =========================================================================

    public function getOrderPenunjang(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        return DiagnosticOrder::with(['orderedBy', 'results'])
            ->where('visit_id', $visitId)
            ->get();
    }

    public function storeOrderPenunjang(array $data): DiagnosticOrder
    {
        $this->authorizeVisitOwnership($data['visit_id']);

        $user  = auth('api')->user();
        $order = DiagnosticOrder::create([
            'visit_id'         => $data['visit_id'],
            'ordered_by_id'    => $user->employee_id,
            'test_type'        => $data['test_type'],
            // Accession DICOM (kunci pencocokan worklist/ingest alat). Lihat AccessionService.
            'accession_number' => app(AccessionService::class)->next(),
            'eye_side'         => $data['eye_side'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'status'           => 'REQUESTED',
        ]);

        // Route visit to PENUNJANG station (but keep in DOKTER until comes back)
        // Create PENUNJANG queue so penunjang can see the order
        $lastSeq  = Queue::where('station', 'PENUNJANG')->whereDate('created_at', today())->max('queue_sequence') ?? 0;
        $sequence = $lastSeq + 1;

        Queue::create([
            'visit_id'       => $data['visit_id'],
            'station'        => 'PENUNJANG',
            'queue_prefix'   => 'P',
            'queue_sequence' => $sequence,
            'queue_number'   => 'P-' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
            'status'         => 'WAITING',
        ]);

        $this->log($user->id, 'ORDER_PENUNJANG', DiagnosticOrder::class, $order->id, "Order {$data['test_type']} untuk kunjungan {$data['visit_id']}");

        return $order->load('orderedBy');
    }

    public function cancelOrderPenunjang(string $id): void
    {
        $order = DiagnosticOrder::findOrFail($id);
        $this->authorizeVisitOwnership($order->visit_id);

        if ($order->status !== 'REQUESTED') {
            throw new \Exception('Order tidak bisa dibatalkan — sudah diproses.', 422);
        }

        $order->update(['status' => 'CANCELLED']);
        $this->log(auth('api')->id(), 'CANCEL_ORDER_PENUNJANG', DiagnosticOrder::class, $id);
    }

    public function getHasilPenunjang(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        return DiagnosticOrder::with('results')
            ->where('visit_id', $visitId)
            ->whereIn('status', ['COMPLETED', 'IN_PROGRESS'])
            ->get();
    }

    public function getIolRekomendasi(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        return IolRecommendation::with(['approvedBy', 'decidedBy', 'iolItem'])
            ->where('visit_id', $visitId)
            ->get();
    }

    /**
     * Data lengkap layar keputusan IOL untuk satu kunjungan:
     *  - biometry: nilai ukur + tabel hitung IOL (per A-constant/formula) hasil
     *    parse alat Quantel dari expertise_data.biometry (mata OD/OS).
     *  - iol_masters: lensa master aktif (brand/model/power/a_constant + stok)
     *    → FE memetakan A-constant baris tabel ke lensa yang ada stoknya.
     *  - decisions: keputusan IOL dokter yang sudah tersimpan (per mata).
     */
    public function getBiometriIol(string $visitId): array
    {
        $this->authorizeVisitOwnership($visitId);

        // Ambil hasil Biometri terbaru yang punya blok biometry terstruktur.
        $order = DiagnosticOrder::with('results')
            ->where('visit_id', $visitId)
            ->where('test_type', DiagnosticTestType::BIOMETRI_CODE)
            ->whereIn('status', ['COMPLETED', 'IN_PROGRESS'])
            ->latest('created_at')
            ->get()
            ->first(fn ($o) => ! empty(optional($o->results->first())->expertise_data['biometry'] ?? null));

        $biometry = $order
            ? (optional($order->results->first())->expertise_data['biometry'] ?? null)
            : null;

        $iolMasters = IolItem::active()->withOnHand()
            ->orderBy('brand')->orderBy('power')
            ->get(['id', 'brand', 'model', 'iol_type', 'power', 'a_constant'])
            ->map(fn ($i) => [
                'id'         => $i->id,
                'brand'      => $i->brand,
                'model'      => $i->model,
                'iol_type'   => $i->iol_type,
                'power'      => $i->power !== null ? (float) $i->power : null,
                'a_constant' => $i->a_constant !== null ? (float) $i->a_constant : null,
                'on_hand'    => (float) ($i->on_hand ?? 0),
                'label'      => trim(($i->brand ?? '') . ' ' . ($i->model ?? '')),
            ]);

        $decisions = IolRecommendation::with('iolItem')
            ->where('visit_id', $visitId)
            ->get()
            ->keyBy('eye_side');

        return [
            'visit_id'      => $visitId,
            'result_id'     => $order ? optional($order->results->first())->id : null,
            'biometry'      => $biometry,   // {exam_key,exam_date,eyes:{OD:{biometry,iol_calc},OS:...}}
            'iol_masters'   => $iolMasters,
            'decisions'     => $decisions,
        ];
    }

    /**
     * Simpan KEPUTUSAN IOL dokter untuk satu mata (updateOrCreate per visit+eye).
     * Keputusan final inilah yang dibaca Bedah (buildRequestPreviewFromSchedule)
     * untuk request IOL/BHP ke gudang.
     */
    public function decideIol(string $visitId, array $data): IolRecommendation
    {
        $this->authorizeVisitOwnership($visitId);

        $iol = ! empty($data['iol_item_id']) ? IolItem::find($data['iol_item_id']) : null;

        $rec = IolRecommendation::updateOrCreate(
            ['visit_id' => $visitId, 'eye_side' => $data['eye_side']],
            [
                'diagnostic_result_id' => $data['diagnostic_result_id'] ?? null,
                'iol_item_id'          => $iol?->id,
                'recommended_power'    => $data['recommended_power'] ?? null,
                'formula'              => $data['formula'] ?? null,
                'a_constant'           => $data['a_constant'] ?? ($iol?->a_constant),
                'target_refraction'    => $data['target_refraction'] ?? null,
                'predicted_refraction' => $data['predicted_refraction'] ?? null,
                'iol_type'             => $data['iol_type'] ?? $iol?->iol_type,
                'brand'                => $data['brand'] ?? $iol?->brand,
                'notes'                => $data['notes'] ?? null,
                'is_final'             => true,
                'decided_by_id'        => auth('api')->user()?->employee_id,
                'decided_at'           => now(),
            ]
        );

        $this->log(auth('api')->id(), 'DECIDE_IOL', IolRecommendation::class, $rec->id,
            "Keputusan IOL {$data['eye_side']} kunjungan {$visitId}");

        return $rec->fresh('iolItem');
    }

    // CATATAN: getPenunjangBilling() DIHAPUS. Penunjang tidak lagi ditagih dari
    // diagnostic_orders. Sejak alur terbaru, dokter menambahkan pemeriksaan penunjang
    // sebagai TINDAKAN lewat Tab 3 ("Tambah Tindakan") → tertarif via Buku Tarif di
    // getTarifTindakan/buildTindakanLines. Order penunjang murni operasional.

    // =========================================================================
    // MEDICAL RESUME
    // =========================================================================

    public function getResumeMedis(string $visitId): ?MedicalResume
    {
        $this->authorizeVisitOwnership($visitId);

        return MedicalResume::where('visit_id', $visitId)->first();
    }

    /**
     * Auto-generate resume from all available visit data.
     */
    public function generateMedicalResume(string $visitId): MedicalResume
    {
        $this->authorizeVisitOwnership($visitId);

        $visit = Visit::with([
            'patient',
            'nurseAssessment',
            'refractionRecord',
            'doctorExamination',
            'diagnosticOrders.results',
        ])->findOrFail($visitId);

        $nurse     = $visit->nurseAssessment;
        $refraksi  = $visit->refractionRecord;
        $doctor    = $visit->doctorExamination;
        $user      = auth('api')->user();

        // S — Subjective: anamnese
        $s = $doctor->anamnese ?? $nurse?->chief_complaint ?? '-';

        // O — Objective: TTV + visus + IOP
        $tvvParts  = [];
        if ($nurse) {
            $tvvParts[] = "TD: {$nurse->td_sistol}/{$nurse->td_diastol} mmHg";
            $tvvParts[] = "Nadi: {$nurse->nadi} x/mnt";
            $tvvParts[] = "Suhu: {$nurse->suhu} °C";
            $tvvParts[] = "SpO2: {$nurse->spo2}%";
        }

        $visusParts = [];
        if ($refraksi) {
            $visusParts[] = "Visus OD: " . ($refraksi->visus_akhir_od ?? '-') . ", OS: " . ($refraksi->visus_akhir_os ?? '-');
            if ($refraksi->iop_od || $refraksi->iop_os) {
                $visusParts[] = "IOP OD: {$refraksi->iop_od} mmHg, OS: {$refraksi->iop_os} mmHg";
            }
        }

        // Objektif RO (refraksionis) ikut O bila ada (soap_o / visus+subjektif+TIO).
        $roObjektifO = $this->refraksiObjektifResume($refraksi);
        $oParts = array_merge($tvvParts, $visusParts);
        if ($roObjektifO !== '') {
            $oParts[] = str_replace("\n", '. ', $roObjektifO);
        }
        $o = implode('. ', $oParts) ?: '-';

        // A — Assessment: ICD-10 (kode + nama) + diagnosa teks bebas bila ada.
        $aParts = [];
        if ($doctor?->diagnosis_utama) {
            $aParts[] = $this->labelIcd10($doctor->diagnosis_utama);
        }
        foreach ($doctor->diagnosis_sekunder ?? [] as $kode) {
            $aParts[] = $this->labelIcd10($kode);
        }
        if ($doctor?->diagnosis_text) {
            $aParts[] = $doctor->diagnosis_text;
        }
        $a = implode("\n", array_filter($aParts)) ?: '-';

        // P — Plan: tindakan ICD-9 (kode + nama) + planning + follow-up
        $pParts = [];
        foreach ($doctor->tindakan_codes ?? [] as $kode) {
            $pParts[] = $this->labelIcd9($kode);
        }
        if ($doctor?->planning) {
            $pParts[] = "Planning: {$doctor->planning}";
        }
        $pParts = array_filter($pParts);

        $p = implode('. ', $pParts) ?: '-';

        // Append follow-up to resume_p
        $visit->refresh();
        if ($visit->planning_follow_up && $visit->follow_up_date) {
            $p .= "\nKontrol Ulang: {$visit->follow_up_date->format('Y-m-d')}";
            if ($visit->follow_up_reason) {
                $p .= " — {$visit->follow_up_reason}";
            }
        }

        // Penunjang results JSONB
        $penunjangResults = $visit->diagnosticOrders
            ->filter(fn ($o) => $o->status === 'COMPLETED')
            ->flatMap(fn ($o) => $o->results->map(fn ($r) => [
                'test_type'  => $o->test_type,
                'eye_side'   => $o->eye_side,
                'result'     => $r->expertise_data ?? [],
                'date'       => $r->created_at?->toDateString(),
            ]))
            ->values()
            ->toArray();

        // Resume Medis Rawat Jalan (RM 1.7/RMRJ/22): field formulir resmi, auto-isi
        // dari sumber yang sudah ada. Tindakan & Riwayat/Instruksi sengaja KOSONG
        // (diisi dokter di modal preview). S/O/A/P di atas tetap diisi utk backward-compat.
        $rmrjData = $this->buildRmrjData($visit, $nurse, $refraksi, $doctor);

        // Upsert MedicalResume
        $resume = MedicalResume::updateOrCreate(
            ['visit_id' => $visitId],
            [
                'doctor_id'          => $user->employee_id,
                'resume_s'           => $s,
                'resume_o'           => $o,
                'resume_a'           => $a,
                'resume_p'           => $p,
                'penunjang_results'  => $penunjangResults,
                'rmrj_data'          => $rmrjData,
                'is_editable'        => true,
                'is_finalized'       => false,
                'generated_at'       => now(),
            ]
        );

        // Link to doctor_examination
        if ($doctor) {
            $doctor->update(['medical_resume_id' => $resume->id]);
        }

        $this->log($user->id, 'GENERATE_RESUME', MedicalResume::class, $resume->id);

        return $resume->fresh();
    }

    /**
     * Susun field Resume Medis Rawat Jalan (RM 1.7/RMRJ/22) dari data kunjungan.
     *
     * Field cetak (header/footer) ikut disimpan agar resume final tetap utuh walau
     * data sumber berubah kemudian. Field naratif (anamnese/pemeriksaan_fisik/dst)
     * di-auto-isi; tindakan & riwayat/instruksi dikosongkan untuk diisi dokter.
     */
    private function buildRmrjData(Visit $visit, $nurse, $refraksi, $doctor): array
    {
        // --- Pemeriksaan Fisik: tanda vital (triase) + Objektif RO (refraksionis) ---
        $fisikParts = [];
        if ($nurse) {
            $ttv = [];
            if ($nurse->td_sistol || $nurse->td_diastol) { $ttv[] = "TD {$nurse->td_sistol}/{$nurse->td_diastol} mmHg"; }
            if ($nurse->nadi)      { $ttv[] = "Nadi {$nurse->nadi} x/mnt"; }
            if ($nurse->respirasi) { $ttv[] = "RR {$nurse->respirasi} x/mnt"; }
            if ($nurse->suhu)      { $ttv[] = "Suhu {$nurse->suhu} C"; }
            if ($nurse->spo2)      { $ttv[] = "SpO2 {$nurse->spo2}%"; }
            if ($nurse->kgd)       { $ttv[] = "KGD {$nurse->kgd} mg/dL"; }
            if ($ttv) { $fisikParts[] = 'Tanda Vital (Triase): ' . implode(', ', $ttv); }
        }
        // Objektif RO: soap_o tersimpan atau rangkaian visus akhir + subjektif + TIO.
        $roObjektif = $this->refraksiObjektifResume($refraksi);
        if ($roObjektif !== '') {
            $fisikParts[] = "Status Oftalmologi (RO):\n" . $roObjektif;
        }
        $pemeriksaanFisik = implode("\n", $fisikParts);

        // --- Alergi Obat (dari triase) ---
        $alergi = ($nurse && $nurse->has_allergy)
            ? ($nurse->allergy_detail ?: 'Ada alergi (detail tidak dicatat)')
            : 'Tidak ada';

        // --- Hasil Penunjang Medis: nama order penunjang yang COMPLETED ---
        $penunjangParts = [];
        foreach ($visit->diagnosticOrders ?? [] as $ord) {
            if ($ord->status !== 'COMPLETED') {
                continue;
            }
            $type = DiagnosticTestType::where('code', $ord->test_type)->value('name') ?? $ord->test_type;
            $eye  = $ord->eye_side ? ' (' . strtoupper($ord->eye_side) . ')' : '';
            $penunjangParts[] = $type . $eye;
        }
        $hasilPenunjang = implode("\n", array_unique($penunjangParts));

        // --- Diagnosa: ICD-10 utama + sekunder (kode + nama) ---
        $diagParts = [];
        if ($doctor?->diagnosis_utama) {
            $diagParts[] = $this->labelIcd10($doctor->diagnosis_utama);
        }
        foreach ($doctor->diagnosis_sekunder ?? [] as $kode) {
            $diagParts[] = $this->labelIcd10($kode);
        }
        if ($doctor?->diagnosis_text) {
            $diagParts[] = $doctor->diagnosis_text;
        }
        $diagnosa = implode("\n", array_filter($diagParts));

        // --- Tindakan: prosedur ICD-9 (kode + nama) ---
        $tindakanParts = [];
        foreach ($doctor->tindakan_codes ?? [] as $kode) {
            $tindakanParts[] = $this->labelIcd9($kode);
        }
        $tindakan = implode("\n", array_filter($tindakanParts));

        // --- Terapi: obat resep (nama + dosis/aturan pakai) ---
        $terapiParts = [];
        $prescriptions = Prescription::with('items.medication')
            ->where('visit_id', $visit->id)
            // Resume rawat jalan → hanya resep RAJAL (jangan campur obat RANAP/ward).
            ->where(fn ($q) => $q->where('type', 'RAJAL')->orWhereNull('type'))
            ->whereIn('status', ['DRAFT', 'SUBMITTED', 'DISPENSING', 'DISPENSED'])
            ->get();
        foreach ($prescriptions as $presc) {
            foreach ($presc->items as $it) {
                $nama = $it->medication?->name ?? Medication::where('id', $it->medication_id)->value('name') ?? 'Obat';
                $aturan = trim(implode(' ', array_filter([
                    $it->quantity ? ('x' . $it->quantity) : null,
                    $it->dose,
                    $it->frequency,
                    $it->route,
                    $it->duration_days ? ('selama ' . $it->duration_days . ' hari') : null,
                ])));
                $terapiParts[] = $aturan ? "{$nama} - {$aturan}" : $nama;
            }
        }
        $terapi = implode("\n", $terapiParts);

        // --- Header & footer (untuk cetak) ---
        $visit->loadMissing(['doctorSchedule', 'insurer', 'patient']);
        $doctorName = $doctor?->doctor?->name
            ?? $visit->doctorSchedule?->employee?->name
            ?? auth('api')->user()?->name
            ?? '-';
        $ruangPoli = $visit->doctorSchedule?->poliklinik ?: '-';
        $penjamin  = $visit->insurer?->name ?: ($visit->guarantor_type ?: 'UMUM');

        // Tanggal kontrol resume: follow-up planning DULU; bila tak ada, ambil dari
        // jadwal bedah/tindakan (planning BEDAH/laser) — tanggal pasien kembali.
        $kontrolTanggal = ($visit->planning_follow_up && $visit->follow_up_date)
            ? $visit->follow_up_date->format('Y-m-d')
            : null;
        if (! $kontrolTanggal && $doctor?->surgery_schedule_id) {
            $sched = $doctor->relationLoaded('surgerySchedule')
                ? $doctor->surgerySchedule
                : SurgerySchedule::find($doctor->surgery_schedule_id);
            if ($sched?->scheduled_date) {
                $kontrolTanggal = $sched->scheduled_date->format('Y-m-d');
            }
        }
        $clinicName = ClinicProfile::query()->value('clinic_name');

        return [
            // Header
            'tanggal_berobat'      => optional($visit->visit_date)->format('Y-m-d') ?? now()->format('Y-m-d'),
            'dokter_merawat'       => $doctorName,
            'ruang_poli'           => $ruangPoli,
            'penanggung_bayar'     => $penjamin,
            // Isi (naratif)
            'anamnese'             => $doctor?->anamnese ?? $nurse?->chief_complaint ?? '',
            'pemeriksaan_fisik'    => $pemeriksaanFisik,
            'alergi_obat'          => $alergi,
            'hasil_penunjang'      => $hasilPenunjang,
            'diagnosa'             => $diagnosa,
            'tindakan'             => $tindakan,   // prosedur ICD-9 (kode + nama)
            'terapi'               => $terapi,
            'riwayat_inap_operasi' => '',   // diisi dokter
            'instruksi_edukasi'    => '',   // diisi dokter
            // Kontrol
            'kontrol_tanggal'      => $kontrolTanggal,
            'kontrol_tempat'       => $kontrolTanggal ? ($clinicName ?: '') : '',
        ];
    }

    private array $icd10NameCache = [];

    /** "H25.1 - Katarak senilis..." (nama dari icd10_codes bila ada). */
    private function labelIcd10(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        if (! array_key_exists($code, $this->icd10NameCache)) {
            $row = Icd10Code::where('code', $code)->first();
            $this->icd10NameCache[$code] = $row?->indonesian_description ?: $row?->description;
        }
        $name = $this->icd10NameCache[$code];

        return $name ? "{$code} - {$name}" : $code;
    }

    private array $icd9NameCache = [];

    /** "13.41 - Fakoemulsifikasi + IOL" (nama dari icd9_codes bila ada). */
    private function labelIcd9(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        if (! array_key_exists($code, $this->icd9NameCache)) {
            $row = Icd9Code::where('code', $code)->first();
            $this->icd9NameCache[$code] = $row?->indonesian_description ?: $row?->description;
        }
        $name = $this->icd9NameCache[$code];

        return $name ? "{$code} - {$name}" : $code;
    }

    /** Objektif refraksionis (RO) untuk resume: pakai soap_o tersimpan; bila kosong
     *  rangkai dari visus akhir + refraksi subjektif + TIO. */
    private function refraksiObjektifResume($refraksi): string
    {
        if (! $refraksi) {
            return '';
        }
        // Sumber tunggal: soap_o (ditulis RefraksionisView oDerived). Fallback di bawah
        // hanya untuk record lama tanpa soap_o. Urutan SELARAS dgn oDerived &
        // RmeAggregator::refraksiObjektif: Visus awal → Subjektif (S/C/X) → Visus akhir → ADD → IOP → PD.
        if ($refraksi->soap_o) {
            return trim($refraksi->soap_o);
        }
        $parts = [];
        $sg = fn ($n) => $n === null ? null : (($n >= 0 ? '+' : '') . $n);
        // 1. Visus awal (UCVA)
        if ($refraksi->visus_awal_od || $refraksi->visus_awal_os) {
            $parts[] = 'Visus awal OD ' . ($refraksi->visus_awal_od ?? '-') . ' / OS ' . ($refraksi->visus_awal_os ?? '-');
        }
        // 2. Refraksi subjektif S/C/X (tanpa ADD)
        $scx = function ($sph, $cyl, $axis) use ($sg) {
            if ($sph === null && $cyl === null && $axis === null) {
                return '';
            }
            $p = [];
            if ($sph !== null)  { $p[] = 'S ' . $sg($sph); }
            if ($cyl !== null)  { $p[] = 'C ' . $sg($cyl); }
            if ($axis !== null) { $p[] = "X {$axis}"; }
            return implode(' / ', $p);
        };
        $rxOd = $scx($refraksi->refraksi_subjektif_od_sph, $refraksi->refraksi_subjektif_od_cyl, $refraksi->refraksi_subjektif_od_axis);
        $rxOs = $scx($refraksi->refraksi_subjektif_os_sph, $refraksi->refraksi_subjektif_os_cyl, $refraksi->refraksi_subjektif_os_axis);
        if ($rxOd || $rxOs) {
            $parts[] = 'Refraksi subjektif OD ' . ($rxOd ?: '-') . ' | OS ' . ($rxOs ?: '-');
        }
        // 3. Visus akhir (BCVA)
        if ($refraksi->visus_akhir_od || $refraksi->visus_akhir_os) {
            $parts[] = 'Visus akhir OD ' . ($refraksi->visus_akhir_od ?? '-') . ' / OS ' . ($refraksi->visus_akhir_os ?? '-');
        }
        // 4. ADD (adisi baca)
        $hasAdd = ($refraksi->add_power_od !== null && (float) $refraksi->add_power_od != 0.0)
            || ($refraksi->add_power_os !== null && (float) $refraksi->add_power_os != 0.0);
        if ($hasAdd) {
            $parts[] = 'Add OD ' . ($sg($refraksi->add_power_od) ?? '-') . ' / OS ' . ($sg($refraksi->add_power_os) ?? '-');
        }
        // 5. IOP/TIO
        if ($refraksi->iop_od || $refraksi->iop_os) {
            $parts[] = 'TIO OD ' . ($refraksi->iop_od ?? '-') . ' / OS ' . ($refraksi->iop_os ?? '-') . ' mmHg' . ($refraksi->iop_method ? " ({$refraksi->iop_method})" : '');
        }
        // 6. PD (pupillary distance) — paling bawah
        if ($refraksi->pd_distance !== null && $refraksi->pd_distance !== '') {
            $pd = rtrim(rtrim((string) $refraksi->pd_distance, '0'), '.');
            $parts[] = 'PD ' . $pd . ' mm';
        }
        return implode("\n", $parts);
    }

    public function updateResumeMedis(string $id, array $data): MedicalResume
    {
        $resume = MedicalResume::findOrFail($id);
        $this->authorizeVisitOwnership($resume->visit_id);

        if ($resume->is_finalized) {
            throw new \Exception('Resume medis sudah dikunci, tidak bisa diubah.', 422);
        }

        if (! $resume->is_editable) {
            throw new \Exception('Resume medis tidak bisa diedit.', 422);
        }

        // S/O/A/P lama (backward-compat) + rmrj_data (field formulir RM 1.7).
        $payload = array_intersect_key($data, array_flip(['resume_s', 'resume_o', 'resume_a', 'resume_p']));

        if (array_key_exists('rmrj_data', $data) && is_array($data['rmrj_data'])) {
            // Merge agar field header/footer yang tak dikirim FE tidak hilang.
            $payload['rmrj_data'] = array_merge($resume->rmrj_data ?? [], $data['rmrj_data']);
        }

        $resume->update($payload);

        $this->log(auth('api')->id(), 'UPDATE_RESUME', MedicalResume::class, $id);

        return $resume->fresh();
    }

    public function finalizeResumeMedis(string $id): MedicalResume
    {
        $resume = MedicalResume::findOrFail($id);
        $this->authorizeVisitOwnership($resume->visit_id);

        if ($resume->is_finalized) {
            throw new \Exception('Resume medis sudah dikunci.', 422);
        }

        return DB::transaction(function () use ($resume, $id) {
            $resume->update([
                'is_finalized' => true,
                'is_editable'  => false,
                'finalized_at' => now(),
            ]);

            // Terbitkan sebagai PatientDocument (tipe RMRJ) agar muncul di menu
            // "Dokumen" RME dengan tombol Print (render dari rendered_html).
            $this->publishResumeDocument($resume->fresh());

            $this->log(auth('api')->id(), 'FINALIZE_RESUME', MedicalResume::class, $id);

            return $resume->fresh();
        });
    }

    /**
     * Buat/update PatientDocument "Resume Medis Rawat Jalan" (RM 1.7/RMRJ/22)
     * dari resume yang sudah final, beserta snapshot HTML cetak (rendered_html).
     * Idempoten per (visit, tipe RMRJ).
     */
    private function publishResumeDocument(MedicalResume $resume): void
    {
        $visit = Visit::with('patient')->find($resume->visit_id);
        if (! $visit) {
            return;
        }

        $docType = DocumentType::firstOrCreate(
            ['code' => 'RMRJ'],
            [
                'name'           => 'Resume Medis Rawat Jalan',
                'fill_frequency' => 'PER_VISIT',
                'generate_type'  => 'MANUAL',
                'show_in_rme'    => true,
                'is_active'      => true,
            ]
        );

        $html = $this->buildRmrjHtml($resume, $visit);

        PatientDocument::updateOrCreate(
            ['visit_id' => $visit->id, 'document_type_id' => $docType->id],
            [
                'patient_id'              => $visit->patient_id,
                'status'                  => 'FINAL',
                'created_by_station'      => 'DOKTER',
                'template_code'           => 'RMRJ',
                'rendered_html'           => $html,
                'pending_signature_roles' => [],
                'signatures'              => [],
                'finalized_at'            => now(),
                'printed_count'           => 0,
            ]
        );
    }

    /** HTML A4 Resume Medis Rawat Jalan (RM 1.7/RMRJ/22) dari rmrj_data. */
    public function buildRmrjHtml(MedicalResume $resume, ?Visit $visit = null): string
    {
        $visit   = $visit ?? Visit::with('patient')->find($resume->visit_id);
        $patient = $visit?->patient;
        $clinic  = ClinicProfile::query()->first();
        $r       = $resume->rmrj_data ?? [];

        $e = fn ($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
        $nl = fn ($s) => nl2br($e($s));

        $logo = $clinic?->logo_path
            ? '<img src="' . $e($clinic->logo_path) . '" style="max-height:54px;max-width:180px;object-fit:contain"/>'
            : '<div style="font-weight:700;font-size:13px">' . $e($clinic?->clinic_name ?: 'LOGO') . '</div>';
        $gender = $patient?->gender === 'L' ? 'L' : ($patient?->gender === 'P' ? 'P' : '-');
        $dob    = optional($patient?->date_of_birth)->format('Y-m-d');
        $doctorName = $resume->doctor?->name ?? ($r['dokter_merawat'] ?? '');
        $finalDate  = optional($resume->finalized_at)->format('Y-m-d') ?: ($r['tanggal_berobat'] ?? '');

        $row = fn ($label, $val) => '<tr><td class="lbl">' . $e($label) . '</td><td class="val">' . $nl($val) . '</td></tr>';
        $pair = fn ($l1, $v1, $l2, $v2) =>
            '<tr><td class="lbl">' . $e($l1) . '</td><td class="val">' . $e($v1) . '</td>'
            . '<td class="lbl">' . $e($l2) . '</td><td class="val">' . $e($v2) . '</td></tr>';

        return '<div class="rmrj-doc"><style>'
            . '.rmrj-doc { font-family: "Times New Roman", serif; font-size: 11pt; color:#000; }'
            . '.rmrj-doc table { border-collapse: collapse; width: 100%; }'
            . '.rmrj-doc td { border: 1px solid #000; padding: 3px 6px; vertical-align: top; }'
            . '.rmrj-doc .ident td { border: none; padding: 1px 4px; font-size: 10pt; }'
            . '.rmrj-doc .code { text-align: right; font-size: 9pt; margin-bottom: 2px; }'
            . '.rmrj-doc .title { text-align: center; font-weight: 700; font-size: 11.5pt; padding: 4px; }'
            . '.rmrj-doc .lbl { width: 26%; font-weight: 600; } .rmrj-doc .val { width: 24%; }'
            . '.rmrj-doc .full .val { width: 74%; }'
            . '.rmrj-doc .sign-wrap { margin-top: 18px; width: 320px; margin-left: auto; text-align: center; font-size: 10.5pt; }'
            . '.rmrj-doc .sign-line { margin-top: 52px; border-top: 1px solid #000; padding-top: 2px; }'
            . '</style>'
            . '<div class="code">RM 1.7/RMRJ/22</div>'
            . '<table><tr><td style="width:50%;border:none">' . $logo . '</td>'
            . '<td style="width:50%;border:none"><table class="ident">'
            . '<tr><td style="width:30%">Nama</td><td>: ' . $e($patient?->name) . '</td></tr>'
            . '<tr><td>Tgl Lahir</td><td>: ' . $e($dob) . ' &nbsp; ' . $e($gender) . '</td></tr>'
            . '<tr><td>No.RM</td><td>: ' . $e($patient?->no_rm) . '</td></tr>'
            . '<tr><td>NIK</td><td>: ' . $e($patient?->nik) . '</td></tr>'
            . '</table></td></tr></table>'
            . '<table><tr><td colspan="4" class="title">RESUME MEDIS RAWAT JALAN</td></tr>'
            . $pair('Tanggal Berobat', $r['tanggal_berobat'] ?? '', 'Dokter yang Merawat', $r['dokter_merawat'] ?? '')
            . $pair('Ruang Poli', $r['ruang_poli'] ?? '', 'Penanggung Pembayaran', $r['penanggung_bayar'] ?? '')
            . '</table>'
            . '<table class="full">'
            . $row('Anamnese', $r['anamnese'] ?? '')
            . $row('Pemeriksaan Fisik', $r['pemeriksaan_fisik'] ?? '')
            . $row('Alergi Obat', $r['alergi_obat'] ?? '')
            . $row('Hasil Penunjang Medis Laboratorium/Radiologi/dll', $r['hasil_penunjang'] ?? '')
            . $row('Diagnosa', $r['diagnosa'] ?? '')
            . $row('Tindakan', $r['tindakan'] ?? '')
            . $row('Terapi', $r['terapi'] ?? '')
            . $row('Riwayat/Rawat Inap/Operasi/Tindakan', $r['riwayat_inap_operasi'] ?? '')
            . $row('Instruksi/Anjuran dan Edukasi Lanjutan', $r['instruksi_edukasi'] ?? '')
            . '<tr><td class="lbl">Kontrol Tanggal: ' . $e($r['kontrol_tanggal'] ?? '') . '</td>'
            . '<td class="val">Di: ' . $e($r['kontrol_tempat'] ?? '') . '</td></tr>'
            . '</table>'
            . '<div class="sign-wrap"><div>Tanggal, ' . $e($finalDate) . '</div>'
            . '<div>Dokter yang Memeriksa,</div>'
            . '<div class="sign-line">' . $e($doctorName) . '</div>'
            . '<div>Nama Jelas dan Tandatangan</div></div>'
            . '</div>';
    }

    // =========================================================================
    // RUJUKAN KELUAR
    // =========================================================================

    /**
     * Buat rujukan keluar (ke RS/faskes lain). Untuk pasien BPJS yang sudah
     * punya SEP, dikirim LANGSUNG (blocking) ke VClaim insertRujukanKeluar dan
     * noRujukan dari BPJS disimpan. Untuk non-BPJS / VClaim non-aktif, hanya
     * disimpan sebagai catatan lokal (status LOCAL).
     */
    public function storeRujukanKeluar(array $data): BpjsReferralOut
    {
        $visit = $this->authorizeVisitOwnership($data['visit_id']);
        $visit->loadMissing(['patient', 'doctorSchedule.employee']);
        $user  = auth('api')->user();

        $tglRujukan = $data['tgl_rujukan'] ?? now('Asia/Jakarta')->toDateString();

        return DB::transaction(function () use ($visit, $data, $user, $tglRujukan) {
            $rujukan = BpjsReferralOut::create([
                'visit_id'           => $visit->id,
                'faskes_tujuan_kode' => $data['faskes_tujuan_kode'],
                'faskes_tujuan_nama' => $data['faskes_tujuan_nama'] ?? null,
                'kode_spesialis'     => $data['kode_spesialis'] ?? null,
                'poli_rujukan'       => $data['poli_rujukan'] ?? null,
                'poli_rujukan_nama'  => $data['poli_rujukan_nama'] ?? null,
                'tipe_rujukan'       => $data['tipe_rujukan'] ?? '1', // partial
                'jns_pelayanan'      => $data['jns_pelayanan'] ?? '2', // rawat jalan
                'tgl_rujukan'        => $tglRujukan,
                'urgency'            => $data['urgency'] ?? 'ELEKTIF',
                'diagnosa_rujukan'   => $data['diagnosa_rujukan'],
                'diagnosa_nama'      => $data['diagnosa_nama'] ?? null,
                'catatan_rujukan'    => $data['catatan_rujukan'] ?? null,
                'status'             => 'DRAFT',
            ]);

            // Kirim ke VClaim hanya bila pasien BPJS, sudah ada SEP, & VCLAIM aktif.
            $vclaimEnabled = IntegrationConfig::where('system_name', 'VCLAIM')->value('is_enabled');

            if ($visit->guarantor_type === 'BPJS' && $visit->no_sep && $vclaimEnabled) {
                $tRujukan = [
                    'noSep'        => $visit->no_sep,
                    'tglRujukan'   => $tglRujukan,
                    'ppkDirujuk'   => $data['faskes_tujuan_kode'],
                    'jnsPelayanan' => $rujukan->jns_pelayanan,
                    'catatan'      => $rujukan->catatan_rujukan ?? '',
                    'diagRujukan'  => $data['diagnosa_rujukan'],
                    'tipeRujukan'  => $rujukan->tipe_rujukan,
                    'poliRujukan'  => $data['poli_rujukan'] ?? '',
                    'user'         => $user?->name ?? 'Arumed',
                ];

                $res = $this->vclaim->insertRujukanKeluar($tRujukan, $visit->id);

                $code = (string) ($res['metaData']['code'] ?? '');
                if ($code !== '200') {
                    // Blocking: dokter melihat pesan error BPJS agar bisa diperbaiki.
                    throw new \Exception(
                        'Gagal terbitkan rujukan BPJS: ' . ($res['metaData']['message'] ?? 'respons tidak dikenal'),
                        422
                    );
                }

                $noRujukan = $res['response']['rujukan']['noRujukan']
                    ?? $res['response']['noRujukan']
                    ?? null;

                $rujukan->update([
                    'no_rujukan'      => $noRujukan,
                    'status'          => 'SUCCESS',
                    'vclaim_response' => $res['response'] ?? null,
                ]);
            } elseif ($visit->guarantor_type !== 'BPJS') {
                // Non-BPJS: rujukan dicatat lokal saja (tidak ada VClaim).
                $rujukan->update(['status' => 'LOCAL']);
            }
            // BPJS tapi belum SEP / VCLAIM non-aktif → biarkan DRAFT (bisa dikirim nanti).

            $this->log($user?->id, 'STORE_RUJUKAN_KELUAR', BpjsReferralOut::class, $rujukan->id,
                "Rujukan keluar {$rujukan->status} → {$rujukan->faskes_tujuan_nama}");

            return $rujukan->fresh();
        });
    }

    // =========================================================================
    // SURAT KONTROL BPJS (Rencana Kontrol) — untuk planning Pulang/berobat jalan
    // =========================================================================

    /**
     * Status Surat Kontrol BPJS milik kunjungan ini (untuk panel Tab 4 Pulang).
     * Mengembalikan letter terbaru (DRAFT/SUCCESS/FAILED) atau null bila belum ada.
     * DRAFT dibuat otomatis oleh handlePlanningFollowUp saat dokter set tgl kontrol.
     */
    public function getSuratKontrol(string $visitId): ?BpjsControlLetter
    {
        $this->authorizeVisitOwnership($visitId);

        return BpjsControlLetter::where('visit_id', $visitId)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Terbitkan Surat Kontrol DRAFT milik kunjungan ini ke VClaim
     * (POST /RencanaKontrol/v2/Insert). Blocking — dokter melihat noSuratKontrol
     * atau pesan error BPJS. Mapping dokter/poli dari kunjungan asal.
     */
    public function submitSuratKontrol(string $visitId): BpjsControlLetter
    {
        $visit = $this->authorizeVisitOwnership($visitId);
        $visit->loadMissing(['doctorSchedule.employee']);
        $user  = auth('api')->user();

        $letter = BpjsControlLetter::where('visit_id', $visitId)
            ->orderByDesc('created_at')
            ->firstOrFail();

        if ($letter->status === 'SUCCESS') {
            throw new \Exception("Surat Kontrol sudah terbit: {$letter->no_surat_kontrol}", 422);
        }
        if (! $visit->no_sep) {
            throw new \Exception('Pasien BPJS belum punya SEP. Terbitkan SEP di Admisi dulu.', 422);
        }

        $vclaimEnabled = IntegrationConfig::where('system_name', 'VCLAIM')->value('is_enabled');
        if (! $vclaimEnabled) {
            throw new \Exception('Integrasi VClaim belum diaktifkan.', 503);
        }

        $schedule   = $visit->doctorSchedule;
        $kodeDokter = $schedule?->employee?->bpjs_dpjp_code;
        $kodePoli   = BpjsPoliMapping::bpjsCodeFor($schedule?->poli_code);

        if (! $kodePoli) {
            throw new \Exception("Poli '{$schedule?->poli_code}' belum dipetakan ke kode BPJS. Atur di Jadwal Dokter → Pemetaan BPJS.", 422);
        }

        $result = $this->vclaim->postSuratKontrol([
            'noSEP'             => $visit->no_sep,
            'kodeDokter'        => $kodeDokter,
            'poliKontrol'       => $kodePoli,
            'tglRencanaKontrol' => $letter->tanggal_rencana_kontrol?->format('Y-m-d'),
            'user'              => $user?->name ?? 'arumed',
        ], $visitId);

        $code = (string) ($result['metaData']['code'] ?? '');
        $noSuratKontrol = $result['response']['noSuratKontrol'] ?? null;

        if ($code !== '200' || ! $noSuratKontrol) {
            $letter->update(['status' => 'FAILED', 'vclaim_response' => $result]);
            throw new \Exception(
                'Gagal terbitkan Surat Kontrol: ' . ($result['metaData']['message'] ?? 'respons tidak dikenal'),
                422
            );
        }

        $letter->update([
            'status'           => 'SUCCESS',
            'no_surat_kontrol' => $noSuratKontrol,
            'vclaim_response'  => $result,
        ]);

        $this->log($user?->id, 'SUBMIT_SURAT_KONTROL', BpjsControlLetter::class, $letter->id,
            "Surat Kontrol terbit {$noSuratKontrol} — kunjungan {$visitId}");

        return $letter->fresh();
    }

    // =========================================================================
    // INBOX TTD
    // =========================================================================

    public function getInboxNotifications(): Collection
    {
        $userId = auth('api')->id();

        return Notification::with(['patientDocument.patient', 'patientDocument.documentType'])
            ->where('recipient_id', $userId)
            ->orderByRaw('is_read ASC, created_at DESC')
            ->limit(50)
            ->get();
    }

    public function markNotificationRead(string $id): Notification
    {
        $notif = Notification::where('recipient_id', auth('api')->id())->findOrFail($id);

        if (! $notif->is_read) {
            $notif->update(['is_read' => true, 'read_at' => now()]);
        }

        return $notif->fresh();
    }

    /**
     * Sign document with PIN verification.
     */
    public function signDocument(string $documentId, string $pin): PatientDocument
    {
        $user     = auth('api')->user()->loadMissing('employee');
        $document = PatientDocument::findOrFail($documentId);

        if (! in_array($document->status, ['WAITING_SIGNATURE', 'DRAFT'])) {
            throw new \Exception('Dokumen tidak dalam status menunggu TTD.', 422);
        }

        // Verify PIN. PIN disimpan PLAINTEXT (kolom tanpa cast 'hashed') — konsisten
        // dgn DokterController::verifyPin, SignatureService, AuthService::changePin.
        // Pakai hash_equals (timing-safe), BUKAN Hash::check (bug lama: TTD selalu 401).
        if (empty($user->pin) || ! hash_equals((string) $user->pin, (string) $pin)) {
            throw new \Exception('PIN tidak sesuai.', 401);
        }

        $pendingRoles = $document->pending_signature_roles ?? [];
        $doctorRole   = 'DOCTOR';

        if (! in_array($doctorRole, $pendingRoles)) {
            throw new \Exception('Dokter tidak termasuk dalam daftar penandatangan dokumen ini.', 422);
        }

        return DB::transaction(function () use ($document, $user, $pendingRoles, $doctorRole) {
            // Add signature entry
            $signatures   = $document->signatures ?? [];
            $signatures[] = [
                'role'      => $doctorRole,
                'name'      => $user->employee?->name ?? $user->name,
                'sign_type' => 'PIN',
                'signed_at' => now()->toIso8601String(),
                'status'    => 'SIGNED',
            ];

            // Remove DOCTOR from pending
            $remainingPending = array_values(array_filter($pendingRoles, fn ($r) => $r !== $doctorRole));

            $updateData = [
                'signatures'              => $signatures,
                'pending_signature_roles' => $remainingPending,
                'status'                  => count($remainingPending) === 0 ? 'FINAL' : 'WAITING_SIGNATURE',
            ];

            if (count($remainingPending) === 0) {
                $updateData['finalized_at'] = now();
            }

            $document->update($updateData);

            // Create DocumentVerification QR when FINAL
            if ($document->status === 'FINAL') {
                DocumentVerification::create([
                    'patient_document_id' => $document->id,
                    'verification_token'  => \Illuminate\Support\Str::uuid(),
                    'verification_url'    => url('/api/v1/rekam-medis/verifikasi/' . \Illuminate\Support\Str::uuid()),
                    'document_hash'       => hash('sha256', json_encode($document->toArray())),
                    'is_valid'            => true,
                    'scan_count'          => 0,
                ]);

                // Notify: DOCUMENT_FINAL
                Notification::where('patient_document_id', $document->id)
                    ->update(['is_read' => true, 'read_at' => now()]);
            }

            $this->log(auth('api')->id(), 'SIGN_DOCUMENT', PatientDocument::class, $document->id, "Dokumen ditandatangani oleh dokter");

            return $document->fresh(['patient', 'documentType']);
        });
    }

    /**
     * Reject document with reason.
     */
    public function rejectDocument(string $documentId, string $reason): PatientDocument
    {
        $user     = auth('api')->user()->loadMissing('employee');
        $document = PatientDocument::findOrFail($documentId);

        if (! in_array($document->status, ['WAITING_SIGNATURE', 'DRAFT'])) {
            throw new \Exception('Dokumen tidak dalam status yang dapat ditolak.', 422);
        }

        $signatures   = $document->signatures ?? [];
        $signatures[] = [
            'role'        => 'DOCTOR',
            'name'        => $user->employee?->name ?? $user->name,
            'sign_type'   => 'PIN',
            'signed_at'   => now()->toIso8601String(),
            'status'      => 'REJECTED',
            'reject_note' => $reason,
        ];

        $document->update([
            'status'       => 'REJECTED',
            'reject_reason' => $reason,
            'signatures'   => $signatures,
        ]);

        // Notify staff that document was rejected
        Notification::create([
            'recipient_id'        => null, // Broadcast ke stasiun pembuat (implementasi nanti via Reverb)
            'type'                => 'SIGNATURE_REJECTED',
            'patient_document_id' => $document->id,
            'title'               => 'Dokumen Ditolak',
            'message'             => "Dokter menolak dokumen: {$reason}",
            'is_read'             => false,
            'resend_count'        => 0,
        ]);

        $this->log(auth('api')->id(), 'REJECT_DOCUMENT', PatientDocument::class, $documentId, "Alasan: {$reason}");

        return $document->fresh(['patient', 'documentType']);
    }

    // =========================================================================
    // RUJUKAN INTERNAL ANTAR-POLI (mis. Poli Mata Umum → Poli Retina)
    // =========================================================================

    /**
     * Daftar tujuan rujukan internal: jadwal dokter/poli minggu ini selain dokter
     * pemilik kunjungan saat ini. Tiap baris memberi tahu apakah dokter tujuan
     * praktik HARI INI (bisa langsung di-antrekan) atau praktik berikutnya
     * (pasien daftar ulang di hari itu).
     */
    public function getRujukInternalTargets(string $visitId): array
    {
        $visit = $this->authorizeVisitOwnership($visitId);

        $weekStart = DoctorSchedule::currentWeekStart();
        $todayDow  = (int) now('Asia/Jakarta')->isoWeekday();   // 1=Mon..7=Sun
        $selfSchedId = $visit->doctor_schedule_id;

        $schedules = DoctorSchedule::with('employee')
            ->forWeek($weekStart)
            ->where('is_active', true)
            ->when($selfSchedId, fn ($q) => $q->where('id', '!=', $selfSchedId))
            ->orderBy('poliklinik')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return $schedules->map(function (DoctorSchedule $s) use ($todayDow) {
            $isToday = $s->day_of_week === $todayDow;

            return [
                'schedule_id'  => $s->id,
                'doctor_id'    => $s->employee_id,
                'doctor_name'  => $s->employee?->name,
                'poliklinik'   => $s->poliklinik,
                'poli_code'    => $s->poli_code,
                'service_type' => $s->service_type,
                'room'         => $s->room,
                'day_of_week'  => $s->day_of_week,
                'day_label'    => $this->dayLabel($s->day_of_week),
                'start_time'   => substr((string) $s->start_time, 0, 5),
                'end_time'     => substr((string) $s->end_time, 0, 5),
                'is_today'     => $isToday,
            ];
        })->values()->all();
    }

    /**
     * Ganti dokter pemeriksa pasien yang ada di antrean dokter ini (koreksi
     * salah-pilih saat pendaftaran), SEBELUM dipanggil/diperiksa. Berbeda dari
     * rujukInternal — ini TETAP satu visit, sekadar memindah kepemilikan ke
     * dokter lain. Hanya dokter pemilik antrean (atau superadmin) yang boleh.
     * Guard alur (belum dipanggil/finalisasi/billing) ada di
     * AdmisiService::gantiDokterKunjungan.
     */
    public function gantiDokter(string $visitId, string $doctorScheduleId): Visit
    {
        $this->authorizeVisitOwnership($visitId);

        return app(\App\Services\AdmisiService::class)
            ->gantiDokterKunjungan($visitId, $doctorScheduleId);
    }

    /**
     * Rujuk pasien ke dokter/poli lain (rujukan internal). Membuat VISIT ANAK
     * (1:1 dengan doctor_examination & billing-nya sendiri — sesuai model BPJS
     * poli-berbeda) yang ditautkan ke visit induk via parent_visit_id.
     *
     * - Dokter tujuan praktik HARI INI → visit anak langsung masuk antrean DOKTER.
     * - Tidak praktik hari ini → visit anak dibuat sebagai penanda (current_station
     *   = ADMISI); petugas Admisi memunculkannya ke antrean di hari praktik dokter.
     *
     * @return array { child_visit, enqueued, target }
     */
    public function rujukInternal(string $visitId, string $targetScheduleId, ?string $reason = null): array
    {
        $visit = $this->authorizeVisitOwnership($visitId);
        $user  = auth('api')->user();

        $target = DoctorSchedule::with('employee')->findOrFail($targetScheduleId);

        if ($target->id === $visit->doctor_schedule_id) {
            throw new \Exception('Tidak bisa merujuk ke poli/dokter yang sama.', 422);
        }

        $todayDow = (int) now('Asia/Jakarta')->isoWeekday();
        $isToday  = $target->week_start?->toDateString() === DoctorSchedule::currentWeekStart()
            && $target->day_of_week === $todayDow
            && $target->is_active;

        return DB::transaction(function () use ($visit, $target, $reason, $user, $isToday) {
            $child = Visit::create([
                'parent_visit_id'                    => $visit->id,
                'patient_id'                         => $visit->patient_id,
                'insurer_id'                         => $visit->insurer_id,
                'registered_by_id'                   => $user?->employee_id,
                'doctor_schedule_id'                 => $target->id,
                'internal_referral_from_schedule_id' => $visit->doctor_schedule_id,
                'internal_referral_reason'           => $reason,
                'no_registrasi'                      => $this->generateChildNoRegistrasi(),
                'visit_date'                         => today(),
                'classification'                     => 'Rujukan Internal',
                'visit_type'                         => 'REGULAR',
                // Hari ini → langsung antrean DOKTER. Hari lain → penanda di ADMISI
                // (petugas memunculkan ke antrean saat pasien datang di hari-H).
                'current_station'                    => $isToday ? 'DOKTER' : 'ADMISI',
                'guarantor_type'                     => $visit->guarantor_type,
                'satusehat_sync_status'              => 'PENDING',
                'insurance_verification_status'      => 'NONE',
            ]);

            if ($isToday) {
                $this->queueService->enqueue($child->id, 'DOKTER');
            }

            $this->log(
                $user?->id,
                'RUJUK_INTERNAL',
                Visit::class,
                $child->id,
                "Rujukan internal dari kunjungan {$visit->id} → poli {$target->poliklinik}"
                . ($isToday ? ' (antrean hari ini)' : ' (jadwal ' . $this->dayLabel($target->day_of_week) . ')')
            );

            return [
                'child_visit' => $child->fresh(['patient', 'doctorSchedule.employee', 'internalReferralFromSchedule']),
                'enqueued'    => $isToday,
                'target'      => [
                    'schedule_id' => $target->id,
                    'doctor_name' => $target->employee?->name,
                    'poliklinik'  => $target->poliklinik,
                    'day_label'   => $this->dayLabel($target->day_of_week),
                    'start_time'  => substr((string) $target->start_time, 0, 5),
                ],
            ];
        });
    }

    /**
     * Nomor registrasi untuk visit anak — selaras dengan
     * AdmisiService::generateNoRegistrasi (REG-Ymd-NNN, withTrashed agar nomor
     * tak bentrok dengan visit yang sudah di-soft-delete).
     */
    private function generateChildNoRegistrasi(): string
    {
        $prefix = 'REG-' . today()->format('Ymd') . '-';

        $last = Visit::withTrashed()
            ->where('no_registrasi', 'like', $prefix . '%')
            ->orderByDesc('no_registrasi')
            ->value('no_registrasi');

        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function dayLabel(int $dow): string
    {
        return [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'][$dow] ?? '-';
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
