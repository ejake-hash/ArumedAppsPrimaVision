<?php

namespace App\Services;

use App\Events\AdmisiQueueUpdated;
use App\Events\AntreanTvUpdated;
use App\Models\BpjsControlLetter;
use App\Models\BpjsPoliMapping;
use App\Models\ClinicProfile;
use App\Models\DoctorSchedule;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\IntegrationConfig;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Queue;
use App\Models\SurgerySchedule;
use App\Models\SurgeryScheduleAuditLog;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Models\VisitCob;
use App\Services\FormRegistry\DocumentRenderer;
use App\Services\FormRegistry\SignatureService;
use App\Services\QueueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdmisiService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
        private readonly DocumentRenderer $documentRenderer,
        private readonly SignatureService $signatureService,
        private readonly BpjsVClaimService $vclaim,
        private readonly BpjsAntreanService $antrean,
    ) {}

    /** Code template General Consent default. */
    private const CONSENT_DEFAULT_CODE = 'GENERAL_CONSENT';

    /**
     * Kode template General Consent yang dikenal lintas-seeder. FormTemplateSeeder
     * memakai 'GENERAL_CONSENT', RM11ConsentSeeder memakai 'RM_1_1_GENERAL_CONSENT'.
     * Resolver mencoba kode yang diminta dulu, lalu kandidat ini, terakhir fallback
     * ke template aktif mana pun di bawah DocumentType 'RM-1.1'.
     */
    private const CONSENT_CODE_CANDIDATES = ['GENERAL_CONSENT', 'RM_1_1_GENERAL_CONSENT'];

    /** DocumentType code untuk General Consent (acuan badge + fallback template). */
    private const CONSENT_DOCTYPE_CODE = 'RM-1.1';

    /**
     * Resolusi template General Consent yang aktif & tidak deprecated, tahan
     * terhadap perbedaan kode antar-seeder. Coba kode yang diminta → kandidat
     * yang dikenal → fallback ke template aktif di bawah DocumentType RM-1.1.
     */
    private function resolveConsentTemplate(?string $requestedCode): ?DocumentTemplate
    {
        $codes = array_values(array_unique(array_filter(array_merge(
            [$requestedCode],
            self::CONSENT_CODE_CANDIDATES,
        ))));

        foreach ($codes as $code) {
            $template = DocumentTemplate::query()
                ->where('code', $code)
                ->where('is_active', true)
                ->whereNull('deprecated_at')
                ->first();
            if ($template) {
                return $template;
            }
        }

        // Fallback: template aktif mana pun di bawah DocumentType General Consent.
        return DocumentTemplate::query()
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->whereHas('documentType', fn ($q) => $q->where('code', self::CONSENT_DOCTYPE_CODE))
            ->first();
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    public function getDashboard(): array
    {
        $today = today();

        // Stat cards kunjungan hari ini
        $totalKunjungan = Visit::whereDate('visit_date', $today)->count();

        $perKlasifikasi = Visit::whereDate('visit_date', $today)
            ->selectRaw('classification, COUNT(*) as total')
            ->groupBy('classification')
            ->pluck('total', 'classification');

        $perStation = Visit::whereDate('visit_date', $today)
            ->selectRaw('current_station, COUNT(*) as total')
            ->groupBy('current_station')
            ->pluck('total', 'current_station');

        $perPenjamin = Visit::whereDate('visit_date', $today)
            ->selectRaw('guarantor_type, COUNT(*) as total')
            ->groupBy('guarantor_type')
            ->pluck('total', 'guarantor_type');

        $antrianAktif = Queue::where('station', 'ADMISI')
            ->whereDate('created_at', $today)
            ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
            ->count();

        $selesai = Visit::whereDate('visit_date', $today)
            ->where('current_station', 'SELESAI')
            ->count();

        // Kunjungan yang dibatalkan hari ini (soft-deleted)
        $cancelCount = Visit::onlyTrashed()
            ->whereDate('visit_date', $today)
            ->count();

        // SEP Terbit = visits hari ini yang punya no_sep
        $sepCount = Visit::whereDate('visit_date', $today)
            ->whereNotNull('no_sep')
            ->count();

        // Bedah selesai hari ini = antrian BEDAH yg sudah COMPLETED.
        // Cover semua skenario (preop hari ini, preop walk-in auto-shift, atau
        // bedah by-dokter): patokan = pasien sudah lewat stasiun bedah hari ini.
        $bedahCount = Queue::where('station', Queue::STATION_BEDAH)
            ->whereDate('created_at', $today)
            ->where('status', Queue::STATUS_COMPLETED)
            ->count();

        // Rawat Inap = pasien yang sedang dirawat inap (belum dipulangkan).
        // RANAP long-lived lintas-hari → TIDAK difilter visit_date hari ini.
        // Belum discharge ditandai discharge_at NULL (lih. RanapService::pasienAktif).
        $ranapCount = Visit::where('jenis_pelayanan', 'RANAP')
            ->whereNull('discharge_at')
            ->count();

        // Stat per penjamin dihitung dari pasien yang SUDAH BAYAR di kasir
        // (invoice status PAID) — bukan total kunjungan.
        $paidPerPenjamin = Visit::whereDate('visit_date', $today)
            ->whereHas('billingInvoice', fn ($q) => $q->where('status', 'PAID'))
            ->selectRaw('guarantor_type, COUNT(*) as total')
            ->groupBy('guarantor_type')
            ->pluck('total', 'guarantor_type');

        $bpjsCount     = (int) ($paidPerPenjamin['BPJS'] ?? 0);
        $umumCount     = (int) ($paidPerPenjamin['UMUM'] ?? 0);
        $asuransiCount = (int) (($paidPerPenjamin['ASURANSI'] ?? 0)
                              + ($paidPerPenjamin['PERUSAHAAN'] ?? 0)
                              + ($paidPerPenjamin['SOSIAL'] ?? 0));

        // BPJS system status
        $bpjsSystems = IntegrationConfig::whereIn('system_name', ['VCLAIM', 'ANTREAN', 'ICARE', 'SATUSEHAT'])
            ->get(['system_name', 'is_enabled', 'last_test_status', 'last_tested_at']);

        return [
            'stat_cards' => [
                'total_kunjungan'  => $totalKunjungan,
                'bpjs_count'       => $bpjsCount,
                'umum_count'       => $umumCount,
                'asuransi_count'   => $asuransiCount,
                'bedah_count'      => $bedahCount,
                'ranap_count'      => $ranapCount,
                'sep_count'        => $sepCount,
                'cancel_count'     => $cancelCount,
                'antrian_aktif'    => $antrianAktif,
                'selesai'          => $selesai,
                'per_klasifikasi'  => $perKlasifikasi,
                'per_station'      => $perStation,
                'per_penjamin'     => $perPenjamin,
            ],
            'bpjs_status' => $bpjsSystems->map(fn ($s) => [
                'system'           => $s->system_name,
                'is_enabled'       => $s->is_enabled,
                'last_test_status' => $s->last_test_status,
                'last_tested_at'   => $s->last_tested_at?->toIso8601String(),
            ])->values(),
        ];
    }

    // =========================================================================
    // KUNJUNGAN
    // =========================================================================

    public function getKunjungan(array $filters): LengthAwarePaginator
    {
        $query = Visit::with([
            'patient',
            'insurer',
            'registeredBy',
            'doctorSchedule.employee:id,name',
            // Ambil semua queue hari ini (terbaru dulu) — mapper di bawah pilih
            // yang paling relevan: queue ADMISI kalau ada (walk-in kiosk), atau
            // queue terbaru di station saat ini (mis. TR-NNN untuk direct admisi).
            'queues' => fn ($q) => $q
                ->select(['id', 'visit_id', 'station', 'queue_number', 'status', 'created_at'])
                ->orderBy('created_at'),
        ]);

        // Walk-in kiosk yang BELUM didaftarkan (placeholder patient.name='Belum
        // Terdaftar', masih nyangkut di loket ADMISI) TIDAK ditampilkan di tabel
        // kunjungan — tempatnya khusus di panel "Siap Dipanggil ke Loket Admisi".
        // Begitu didaftarkan, nama placeholder terisi (pasien baru) atau visit
        // di-repoint ke pasien asli (pasien lama) → otomatis muncul di tabel ini.
        $query->whereHas('patient', fn ($q) => $q->where('name', '!=', 'Belum Terdaftar'));

        // Mode "Belum Selesai (semua tanggal)": tampilkan SEMUA kunjungan yang
        // masih berjalan (current_station != SELESAI) lintas-hari — untuk melihat
        // & membereskan ekor visit nyangkut. Selain mode ini, filter per-tanggal.
        $careType = $filters['care_type'] ?? null;
        $tanggal  = $filters['tanggal'] ?? today();
        if (! empty($filters['unfinished'])) {
            $query->where('current_station', '!=', 'SELESAI');
        } elseif ($careType === 'RANAP') {
            // Rawat inap long-lived (bertahan lintas-hari): JANGAN filter per-tanggal
            // karena visit_date dibekukan saat registrasi. Tampilkan semua pasien
            // yang belum dipulangkan (discharge_at NULL) — termasuk yang masuk
            // hari-hari sebelumnya & yang masih "menunggu kamar". Mengikuti semantik
            // RanapService::activeInpatients().
            $query->whereNull('discharge_at');
        } else {
            // RAJAL & default: HANYA kunjungan yang diregistrasi pada tanggal ini.
            // Pasien lintas-hari yang belum selesai dilihat lewat tab "Masih Aktif"
            // (mode unfinished di atas). Klausa OR "current_station != SELESAI"
            // tanpa batas tanggal pernah dipasang di sini (c12a693) tapi begitu
            // aktif malah membanjiri tab Rawat Jalan dengan ratusan visit lama
            // yang nyangkut → dicabut atas permintaan user (12 Jun 2026).
            $query->whereDate('visit_date', $tanggal);
        }

        // Pisah Rawat Jalan vs Rawat Inap. RANAP long-lived (bertahan berhari-hari)
        // sehingga jika dicampur akan terus muncul di list rawat jalan. Default UI
        // mengirim care_type=RAJAL.
        //
        // Identifikasi pakai current_station (sumber kebenaran di papan), BUKAN hanya
        // jenis_pelayanan: pasien "menunggu kamar" punya current_station=MENUNGGU_RANAP
        // tapi jenis_pelayanan masih 'RAJAL'. Stasiun RANAP/MENUNGGU_RANAP = rawat inap.
        $ranapStations = ['RANAP', 'MENUNGGU_RANAP'];
        if (! empty($filters['care_type'])) {
            if ($filters['care_type'] === 'RANAP') {
                $query->where(function ($q) use ($ranapStations) {
                    $q->whereIn('current_station', $ranapStations)
                      ->orWhere('jenis_pelayanan', 'RANAP');
                });
            } elseif ($filters['care_type'] === 'RAJAL') {
                $query->whereNotIn('current_station', $ranapStations)
                      ->where(function ($q) {
                          $q->where('jenis_pelayanan', '!=', 'RANAP')
                            ->orWhereNull('jenis_pelayanan');
                      });
            }
            // care_type lain (mis. 'SEMUA') → tidak memfilter (tampilkan semua).
        }

        if (! empty($filters['station'])) {
            // "TRIASE" di UI mencakup stasiun paralel TRIASE + REFRAKSIONIS.
            if ($filters['station'] === 'TRIASE') {
                $query->whereIn('current_station', ['TRIASE', 'REFRAKSIONIS']);
            } else {
                $query->where('current_station', $filters['station']);
            }
        }

        if (! empty($filters['guarantor_type'])) {
            $query->where('guarantor_type', $filters['guarantor_type']);
        }

        if (! empty($filters['classification'])) {
            $query->where('classification', $filters['classification']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('patient', fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('no_rm', 'ilike', "%{$search}%")
                ->orWhere('nik', 'like', "%{$search}%")
            );
        }

        $result = $query
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);

        // Add general_consent_signed flag per visit
        $patientIds = collect($result->items())->pluck('patient_id')->unique()->values()->toArray();

        if (! empty($patientIds)) {
            $signedPatients = PatientDocument::whereIn('patient_id', $patientIds)
                ->where('status', 'FINALIZED')
                ->whereHas('documentType', fn ($q) => $q
                    ->where('code', 'RM-1.1')
                    ->orWhere('name', 'ilike', '%general consent%')
                )
                ->pluck('patient_id')
                ->unique()
                ->flip();

            foreach ($result->items() as $visit) {
                $visit->no_antrian = $this->pickPrimaryQueueNumber($visit);
                $visit->general_consent_signed = isset($signedPatients[$visit->patient_id]);
            }
        } else {
            foreach ($result->items() as $visit) {
                $visit->no_antrian = $this->pickPrimaryQueueNumber($visit);
                $visit->general_consent_signed = false;
            }
        }

        return $result;
    }

    /**
     * Pilih nomor antrean yang paling relevan untuk ditampilkan di list:
     *  - Kalau pasien masih di stage ADMISI (walk-in kiosk), pakai queue ADMISI.
     *  - Kalau sudah lewat ADMISI (direct daftar atau sudah selesai admisi),
     *    pakai queue TR-NNN (TRIASE/REFRAKSIONIS share prefix).
     *  - Fallback: queue pertama yang tercipta untuk visit ini.
     */
    private function pickPrimaryQueueNumber(Visit $visit): string
    {
        $queues = $visit->queues;
        if ($queues->isEmpty()) return '—';

        // Walk-in: prioritas queue ADMISI yang masih aktif/awal
        $admisi = $queues->firstWhere('station', 'ADMISI');
        if ($admisi && $visit->current_station === 'ADMISI') {
            return $admisi->queue_number;
        }

        // Direct daftar: pakai queue TR (TRIASE/REFRAKSIONIS) yang pasti share nomor
        $tr = $queues->first(fn ($q) => in_array($q->station, ['TRIASE', 'REFRAKSIONIS'], true));
        if ($tr) return $tr->queue_number;

        // Fallback: queue pertama
        return $queues->first()?->queue_number ?? '—';
    }

    public function getKunjunganById(string $id): Visit
    {
        return Visit::with([
            'patient',
            'insurer',
            'registeredBy',
            'queues',
            'visitCob',
            'nurseAssessment',
            'refractionRecord',
            'internalReferralFromSchedule:id,poliklinik',
        ])->findOrFail($id);
    }

    public function cancelKunjungan(string $id): void
    {
        $visit = Visit::findOrFail($id);

        // Tidak boleh cancel kalau sudah SELESAI (audit-trail integrity)
        if ($visit->current_station === 'SELESAI') {
            throw new \Exception('Kunjungan sudah selesai — tidak bisa dibatalkan.', 422);
        }

        // Hanya boleh dibatalkan dari Admisi selama pasien masih di fase
        // resepsi/skrining (ADMISI/TRIASE/REFRAKSIONIS) dan BELUM dilayani
        // (tak ada antrean CALLED/IN_PROGRESS). Begitu pasien diproses di stasiun
        // klinis, pembatalan dikunci untuk cegah penghapusan kunjungan yang
        // sedang berjalan. (Guard sisi server — mengiringi penguncian tombol di UI.)
        $receptionStations = ['ADMISI', 'TRIASE', 'REFRAKSIONIS'];
        $inService = $visit->queues()
            ->where('station', '!=', 'ADMISI')   // "dipanggil ke loket" ≠ pelayanan klinis
            ->whereIn('status', ['CALLED', 'IN_PROGRESS'])
            ->exists();
        if (! in_array($visit->current_station, $receptionStations, true) || $inService) {
            throw new \Exception(
                "Pasien sudah dalam pelayanan (stasiun {$visit->current_station}) — pembatalan dikunci. "
                . 'Batalkan dari stasiun terkait bila benar-benar perlu.',
                422
            );
        }

        DB::transaction(function () use ($visit) {
            // Cancel semua antrean aktif (WAITING/CALLED/IN_PROGRESS) di station mana pun
            $visit->queues()
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                ->update(['status' => 'CANCELLED']);
            $visit->delete(); // soft delete
        });

        $this->log(
            auth('api')->id(),
            'CANCEL_KUNJUNGAN',
            Visit::class,
            $id,
            "Kunjungan dibatalkan (station: {$visit->current_station})"
        );
    }

    /**
     * Ubah penjamin / tipe kunjungan (sekaligus "pola bayar") SETELAH registrasi.
     *
     * Aman dilakukan selama billing belum dikomit: resolusi tarif membaca
     * guarantor_type + insurer_id secara LIVE saat penagihan
     * (KasirService::getPrice), jadi mengganti penjamin sebelum kasir otomatis
     * mengubah pola bayar tanpa perlu menyentuh charge/WIP.
     *
     * Ditolak bila: kunjungan SELESAI, tagihan sudah dikirim ke kasir (antrean
     * KASIR aktif), invoice sudah FINALIZED/PAID, atau SEP BPJS sudah terbit
     * (harus dibatalkan dulu).
     *
     * Pembersihan artefak penjamin lama:
     *  - Pindah KELUAR BPJS → buang no_rujukan/no_surat_kontrol/booking + link rujukan/kontrol.
     *  - Bukan ASURANSI/PERUSAHAAN → status verifikasi NONE + soft-delete verifikasi PENDING basi.
     *  - COB di-update bila dikirim, selain itu COB lama dinonaktifkan.
     */
    public function updateGuarantor(string $visitId, array $data): Visit
    {
        return DB::transaction(function () use ($visitId, $data) {
            $visit = Visit::lockForUpdate()->findOrFail($visitId);

            if ($visit->current_station === 'SELESAI') {
                throw new \Exception('Kunjungan sudah selesai — penjamin tidak bisa diubah.', 422);
            }

            // Guard 1: tagihan masih hidup di kasir (antrean KASIR aktif DAN ada
            // invoice yang belum dibatalkan). Bila invoice sudah dibatalkan
            // (CANCELLED) lewat tombol "Batalkan Tagihan" kasir, penjamin BOLEH
            // diubah lagi untuk disusun ulang — antrean KASIR sengaja tetap aktif
            // pasca-batal, jadi cek antrean saja tak cukup.
            $hasActiveKasir = $visit->queues()
                ->where('station', Queue::STATION_KASIR)
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->exists();
            $hasLiveInvoice = \App\Models\BillingInvoice::where('visit_id', $visit->id)
                ->where('status', '!=', 'CANCELLED')
                ->exists();
            if ($hasActiveKasir && $hasLiveInvoice) {
                throw new \Exception('Tagihan sudah dikirim ke kasir — penjamin tidak bisa diubah. Batalkan dari kasir bila perlu.', 422);
            }

            // Guard 2: invoice sudah final / lunas.
            $hasCommittedInvoice = \App\Models\BillingInvoice::where('visit_id', $visit->id)
                ->whereIn('status', ['FINALIZED', 'PAID'])
                ->exists();
            if ($hasCommittedInvoice) {
                throw new \Exception('Invoice sudah diterbitkan/dibayar — penjamin tidak bisa diubah.', 422);
            }

            $newType = $data['guarantor_type'];

            // Guard 3: SEP BPJS sudah terbit & pindah keluar BPJS → batalkan SEP dulu.
            if ($visit->no_sep && $newType !== 'BPJS') {
                throw new \Exception("SEP BPJS {$visit->no_sep} masih aktif — batalkan SEP dulu sebelum mengubah penjamin.", 422);
            }

            $oldType = $visit->guarantor_type;

            // Penjamin utama + insurer (insurer hanya relevan utk ASURANSI/PERUSAHAAN/SOSIAL).
            $visit->guarantor_type = $newType;
            $visit->insurer_id = in_array($newType, ['ASURANSI', 'PERUSAHAAN', 'SOSIAL'], true)
                ? ($data['insurer_id'] ?? null)
                : null;

            // BPJS: set/clear nomor rujukan/kontrol/booking.
            if ($newType === 'BPJS') {
                $visit->bpjs_booking_code = $data['bpjs_booking_code'] ?? null;
                $visit->no_rujukan        = $data['bpjs_referral_no']  ?? null;
                $visit->no_surat_kontrol  = $data['bpjs_control_no']   ?? null;
            } else {
                $visit->bpjs_booking_code      = null;
                $visit->no_rujukan             = null;
                $visit->no_surat_kontrol       = null;
                $visit->bpjs_referral_in_id    = null;
                $visit->bpjs_control_letter_id = null;
            }

            // Verifikasi asuransi (TPA): ASURANSI/PERUSAHAAN butuh PENDING; lainnya NONE.
            $needsTpa = in_array($newType, ['ASURANSI', 'PERUSAHAAN'], true);
            if ($needsTpa) {
                $visit->insurance_verification_status = 'PENDING';
            } else {
                $visit->insurance_verification_status = 'NONE';
                $visit->insurance_verified_at = null;
            }

            $visit->save();

            // Soft-delete verifikasi PENDING yang tak relevan (tipe non-TPA, atau insurer berbeda).
            $verifQuery = \App\Models\InsuranceVerification::where('visit_id', $visit->id)
                ->where('status', 'PENDING');
            if ($needsTpa && $visit->insurer_id) {
                $verifQuery->where('insurer_id', '!=', $visit->insurer_id);
            }
            $verifQuery->delete();

            // Buat verifikasi PENDING baru bila TPA & belum ada untuk insurer ini.
            if ($needsTpa && $visit->insurer_id) {
                $exists = \App\Models\InsuranceVerification::where('visit_id', $visit->id)
                    ->where('insurer_id', $visit->insurer_id)
                    ->where('status', 'PENDING')
                    ->exists();
                if (! $exists) {
                    \App\Models\InsuranceVerification::create([
                        'visit_id'           => $visit->id,
                        'insurer_id'         => $visit->insurer_id,
                        'verified_by'        => null,
                        'status'             => 'PENDING',
                        'policy_number'      => $data['policy_number']      ?? null,
                        'member_name'        => $data['member_name']        ?? null,
                        'member_card_number' => $data['member_card_number'] ?? null,
                    ]);
                }
            }

            // COB: update bila dikirim & aktif, selain itu nonaktifkan COB lama.
            if (! empty($data['cob']) && ! empty($data['cob']['penjamin2_insurer_id'])) {
                $this->saveCob($visit->id, $data['cob']);
            } else {
                VisitCob::where('visit_id', $visit->id)->update(['is_active' => false]);
            }

            $this->log(
                auth('api')->id(),
                'UPDATE_PENJAMIN',
                Visit::class,
                $visit->id,
                "Penjamin diubah {$oldType} → {$newType}" . ($visit->insurer_id ? " (insurer {$visit->insurer_id})" : '')
            );

            return $visit->fresh(['patient', 'insurer', 'queues']);
        });
    }

    /**
     * Ganti dokter pemeriksa kunjungan (koreksi salah-pilih saat pendaftaran).
     *
     * Aman & ringan: antrean DOKTER TIDAK menyimpan doctor_id — pasien muncul di
     * antrean dokter via relasi visit.doctorSchedule.employee_id. Cukup menukar
     * visits.doctor_schedule_id → pasien otomatis hilang dari antrean dokter lama
     * dan muncul di antrean dokter baru tanpa menyentuh tabel queues.
     *
     * Hanya boleh SEBELUM dokter mulai memeriksa (antrean DOKTER belum
     * dipanggil/diproses & RME belum difinalisasi). Untuk pindah dokter setelah
     * diperiksa, gunakan Rujuk Internal (DokterService::rujukInternal).
     */
    public function gantiDokterKunjungan(string $visitId, string $doctorScheduleId): Visit
    {
        return DB::transaction(function () use ($visitId, $doctorScheduleId) {
            $visit = Visit::lockForUpdate()->findOrFail($visitId);

            if ($visit->current_station === 'SELESAI') {
                throw new \Exception('Kunjungan sudah selesai — dokter tidak bisa diubah.', 422);
            }

            // No-op bila sama → kembalikan apa adanya (idempoten).
            if ($visit->doctor_schedule_id === $doctorScheduleId) {
                return $visit->fresh(['doctorSchedule.employee', 'queues']);
            }

            // Guard 1: dokter sudah memanggil / sedang / selesai memeriksa.
            $dokterStarted = $visit->queues()
                ->where('station', Queue::STATION_DOKTER)
                ->whereIn('status', [Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS, Queue::STATUS_COMPLETED])
                ->exists();
            if ($dokterStarted) {
                throw new \Exception('Dokter sudah memanggil/memeriksa pasien — ganti dokter dikunci. Gunakan Rujuk Internal bila perlu pindah dokter.', 422);
            }

            // Guard 2: pemeriksaan dokter sudah difinalisasi (terkunci).
            $exam = $visit->doctorExamination;
            if ($exam && $exam->is_finalized) {
                throw new \Exception('Pemeriksaan dokter sudah difinalisasi — dokter tidak bisa diubah.', 422);
            }

            // Guard 3: tagihan sudah dikirim ke kasir.
            $hasActiveKasir = $visit->queues()
                ->where('station', Queue::STATION_KASIR)
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->exists();
            if ($hasActiveKasir) {
                throw new \Exception('Tagihan sudah dikirim ke kasir — dokter tidak bisa diubah.', 422);
            }

            // Guard 4: invoice sudah final / lunas.
            $hasCommittedInvoice = \App\Models\BillingInvoice::where('visit_id', $visit->id)
                ->whereIn('status', ['FINALIZED', 'PAID'])
                ->exists();
            if ($hasCommittedInvoice) {
                throw new \Exception('Invoice sudah diterbitkan/dibayar — dokter tidak bisa diubah.', 422);
            }

            // Validasi jadwal tujuan: aktif & praktik pada HARI kunjungan.
            $schedule = DoctorSchedule::with('employee')->findOrFail($doctorScheduleId);
            if (! $schedule->is_active) {
                throw new \Exception('Jadwal dokter tujuan tidak aktif.', 422);
            }
            $visitDow = (int) $visit->visit_date->format('N'); // 1=Sen..7=Min
            if ((int) $schedule->day_of_week !== $visitDow) {
                throw new \Exception('Dokter tujuan tidak praktik pada tanggal kunjungan ini.', 422);
            }

            $oldName = optional(optional($visit->doctorSchedule)->employee)->name ?? '-';
            $newName = optional($schedule->employee)->name ?? '-';

            $visit->doctor_schedule_id = $doctorScheduleId;
            $visit->save();

            // Draf pemeriksaan (belum final, mis. anamnese terlanjur tersimpan)
            // ikut pindah kepemilikan agar tak menggantung di dokter lama.
            if ($exam && ! $exam->is_finalized) {
                $exam->doctor_id = $schedule->employee_id;
                $exam->save();
            }

            $this->log(
                auth('api')->id(),
                'GANTI_DOKTER',
                Visit::class,
                $visit->id,
                "Dokter diubah {$oldName} → {$newName}"
            );

            return $visit->fresh(['doctorSchedule.employee', 'queues']);
        });
    }

    // =========================================================================
    // PASIEN
    // =========================================================================

    /**
     * Search patient by NIK, BPJS number, no_rm, or name.
     */
    public function cariPasien(string $keyword): Collection
    {
        // Deteksi keyword tanggal lahir DD/MM/YYYY (juga DD-MM-YYYY / DD.MM.YYYY).
        // Bila cocok → cari exact pada kolom date_of_birth (disimpan Y-m-d).
        $dob = $this->parseDobKeyword($keyword);

        $patients = Patient::active()
            ->where(function ($q) use ($keyword, $dob) {
                $q->where('nik', 'like', "%{$keyword}%")
                    ->orWhere('bpjs_number', 'like', "%{$keyword}%")
                    ->orWhere('no_rm', 'ilike', "%{$keyword}%")
                    ->orWhere('name', 'ilike', "%{$keyword}%");
                if ($dob !== null) {
                    $q->orWhereDate('date_of_birth', $dob);
                }
            })
            ->limit(15)
            ->get();

        // Sertakan info kunjungan aktif (current_station != SELESAI) per pasien
        // supaya petugas TAHU sebelum daftar — guard registerVisit akan menolak
        // kalau pasien sudah punya visit aktif. Satu query batch (anti N+1).
        $activeVisits = Visit::whereIn('patient_id', $patients->pluck('id'))
            ->where('current_station', '!=', 'SELESAI')
            ->orderByDesc('created_at')
            ->get(['id', 'patient_id', 'no_registrasi', 'visit_date', 'current_station'])
            ->groupBy('patient_id');

        $patients->each(function ($p) use ($activeVisits) {
            $av = $activeVisits->get($p->id)?->first();
            $p->active_visit = $av ? [
                'id'              => $av->id,
                'no_registrasi'   => $av->no_registrasi,
                'visit_date'      => optional($av->visit_date)->toDateString(),
                'current_station' => $av->current_station,
            ] : null;
        });

        return $patients;
    }

    /**
     * Ubah keyword pencarian tanggal lahir menjadi string Y-m-d untuk whereDate.
     * Format yang dikenali:
     *   - DD/MM/YYYY (pemisah / - . atau spasi, mis. "12 05 1990")
     *   - DDMMYYYY tanpa pemisah, tepat 8 digit (mis. "12051990")
     * Mengembalikan null bila keyword bukan tanggal valid (mis. NIK / nama)
     * supaya pencarian lain tetap jalan.
     */
    private function parseDobKeyword(string $keyword): ?string
    {
        $k = trim($keyword);

        // DDMMYYYY tanpa pemisah (tepat 8 digit). NIK=16, BPJS=13 digit → tak bentrok.
        if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $k, $m)) {
            [, $d, $mo, $y] = $m;
            return checkdate((int) $mo, (int) $d, (int) $y)
                ? sprintf('%04d-%02d-%02d', $y, $mo, $d)
                : null;
        }

        if (! preg_match('/^(\d{1,2})[\/\-.\s]+(\d{1,2})[\/\-.\s]+(\d{4})$/', $k, $m)) {
            return null;
        }
        [, $d, $mo, $y] = $m;
        if (! checkdate((int) $mo, (int) $d, (int) $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /**
     * Normalisasi No. Kartu BPJS sebelum simpan ke kolom `patients.bpjs_number`
     * yang ber-UNIQUE constraint. String kosong, '-', atau hanya tanda hubung/
     * spasi → NULL (Postgres izinkan NULL berulang; '-' literal akan tabrakan
     * unique saat >1 pasien non-BPJS/nomor belum diketahui). Mencegah
     * SQLSTATE[23505] patients_bpjs_number_unique.
     */
    private function normalizeBpjsNumber(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return ($v === '' || preg_match('/^[-–—\s]+$/u', $v)) ? null : $v;
    }

    public function storePasien(array $data): Patient
    {
        $noRm = $this->generateNoRM();

        $patient = Patient::create([
            'no_rm'         => $noRm,
            'identity_type' => $data['identity_type'] ?? 'KTP',
            'nik'           => $data['nik'] ?? null,
            'name'          => $data['name'],
            'gender'        => $data['gender'],
            'date_of_birth' => $data['date_of_birth'],
            'phone'         => $data['phone'] ?? null,
            'family_phone'  => $data['family_phone'] ?? null,
            'email'         => $data['email'] ?? null,
            'address'       => $data['address'] ?? null,
            'province'      => $data['province'] ?? null,
            'bpjs_number'   => $this->normalizeBpjsNumber($data['bpjs_number'] ?? null),
            'blood_type'    => $data['blood_type'] ?? null,
            'allergy_notes' => $data['allergy_notes'] ?? null,
            'photo_path'    => $this->savePatientPhoto($data['photo'] ?? null, $data['name']),
            'is_active'     => true,
        ]);

        $this->log(auth('api')->id(), 'CREATE_PASIEN', Patient::class, $patient->id, "Pasien baru: {$patient->name}");

        return $patient;
    }

    /**
     * Simpan foto pasien (data URL base64 dari webcam/upload) ke disk `public`.
     * Nama file: {tanggal}-{nama}-{rand}.{ext} sesuai format yang diminta.
     * Mengembalikan path relatif untuk disimpan di kolom patients.photo_path,
     * atau null jika tidak ada foto / data tidak valid.
     */
    private function savePatientPhoto(?string $dataUrl, ?string $name): ?string
    {
        if (empty($dataUrl)) {
            return null;
        }

        $ext = 'jpg';
        if (preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,/', $dataUrl, $m)) {
            $ext     = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
            $dataUrl = substr($dataUrl, strpos($dataUrl, ',') + 1);
        }

        $binary = base64_decode($dataUrl, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $slug = Str::slug($name ?: 'pasien') ?: 'pasien';
        $path = 'patients/' . today()->format('Y-m-d') . '-' . $slug . '-' . Str::random(6) . '.' . $ext;

        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    public function getPasienById(string $id): Patient
    {
        // Riwayat kunjungan dimuat terpisah & terpaginasi via getKunjunganPasien().
        return Patient::findOrFail($id);
    }

    /**
     * Riwayat kunjungan satu pasien — paginated + filter tanggal (untuk tab Riwayat).
     */
    public function getKunjunganPasien(string $patientId, array $filters): LengthAwarePaginator
    {
        $query = Visit::with(['doctorSchedule.employee', 'insurer'])
            ->where('patient_id', $patientId)
            ->orderByDesc('visit_date')
            ->orderByDesc('created_at');

        if (! empty($filters['tanggal'])) {
            $query->whereDate('visit_date', $filters['tanggal']);
        }

        return $query->paginate(
            $filters['per_page'] ?? 8,
            ['*'],
            'page',
            $filters['page'] ?? 1,
        );
    }

    /**
     * Jadwal bedah aktif pasien (hari ini & masa depan, status SCHEDULED/IN_PROGRESS).
     * Dipakai Admisi utk auto-suggest banner "Preop Bedah" saat pasien dipilih.
     *
     * Link via surgery_requests.visit.patient_id (schedule tidak punya patient_id langsung).
     */
    public function getJadwalBedahAktif(string $patientId): array
    {
        $today = today();

        $schedules = SurgerySchedule::query()
            ->with('surgeryPackage:id,name')
            ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
            ->whereDate('scheduled_date', '>=', $today)
            ->whereHas('surgeryRequests.visit', fn ($q) => $q->where('patient_id', $patientId))
            ->orderByRaw("CASE WHEN scheduled_date = ? THEN 0 ELSE 1 END", [$today->toDateString()])
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->get();

        return $schedules->map(fn ($s) => [
            'id'              => $s->id,
            'scheduled_date'  => $s->scheduled_date->toDateString(),
            'scheduled_time'  => $s->scheduled_time,
            'operation_room'  => $s->operation_room,
            'status'          => $s->status,
            'surgery_package' => $s->surgeryPackage ? [
                'id'   => $s->surgeryPackage->id,
                'name' => $s->surgeryPackage->name,
            ] : null,
            'is_today'        => $s->scheduled_date->isToday(),
            // Fase 8B — pre-op rawat inap: banner Admisi pakai flag ini untuk memilih
            // alur INAP (datang H-1 → Menunggu Kamar) vs bedah rawat jalan (hari-H).
            'requires_inpatient' => (bool) $s->requires_inpatient,
        ])->all();
    }

    /**
     * Hak "konsultasi kontrol gratis pasca-bedah" yang masih aktif untuk pasien —
     * dipakai badge Admisi saat daftar kunjungan Kontrol (manfaat: 1x per operasi,
     * penjamin UMUM). Lihat PackageFollowupService / KasirService::buildFollowupConsultLines.
     */
    public function getFollowupEntitlements(string $patientId): array
    {
        return \App\Models\PackageFollowupEntitlement::redeemableForPatient($patientId)
            ->whereNull('redeemed_visit_id')
            ->with(['procedure:id,name', 'sourcePackage:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($e) => [
                'id'             => $e->id,
                'procedure_name' => $e->procedure?->name ?? 'Konsultasi',
                'package_name'   => $e->sourcePackage?->name,
                'remaining'      => max(0, (int) $e->total_count - (int) $e->used_count),
                'valid_until'    => $e->valid_until?->toDateString(),
            ])
            ->all();
    }

    public function updatePasien(string $id, array $data): Patient
    {
        $patient = Patient::findOrFail($id);

        // Foto baru (re-take) — simpan file baru. JANGAN hapus file lama:
        // file lama masih dirujuk oleh visits.photo_path (riwayat per-kunjungan).
        if (! empty($data['photo'])) {
            $newPath = $this->savePatientPhoto($data['photo'], $data['name'] ?? $patient->name);
            if ($newPath) {
                $data['photo_path'] = $newPath;
            }
        }
        unset($data['photo']); // bukan kolom DB

        // No. Kartu BPJS kosong/'-' → NULL (hindari tabrakan unique constraint).
        if (array_key_exists('bpjs_number', $data)) {
            $data['bpjs_number'] = $this->normalizeBpjsNumber($data['bpjs_number']);
        }

        $patient->update($data);

        $this->log(auth('api')->id(), 'UPDATE_PASIEN', Patient::class, $id);

        return $patient->fresh();
    }

    /**
     * Replace data wilayah pasien lama saat petugas memilih ulang di wizard
     * (data hasil migrasi yang tak cocok master). Hanya menimpa field yang
     * diisi (non-null) supaya tak mengosongkan data yang tak disentuh.
     */
    private function applyWilayahUpdate(Patient $patient, ?array $wilayah): void
    {
        if (empty($wilayah)) {
            return;
        }

        $update = array_filter([
            'province'       => $wilayah['province']       ?? null,
            'nama_kab_kota'  => $wilayah['nama_kab_kota']  ?? null,
            'nama_kecamatan' => $wilayah['nama_kecamatan'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($update) {
            $patient->update($update);
        }
    }

    // =========================================================================
    // DAFTAR KUNJUNGAN
    // =========================================================================

    /**
     * Register new visit (langsung daftar di Admisi, BUKAN dari kiosk).
     *
     * Pasien yang datang langsung ke loket admisi sudah teridentifikasi dan
     * langsung diproses petugas — tidak perlu lewat antrian ADMISI lagi.
     * Begitu didaftarkan, pasien langsung masuk antrian TRIASE + REFRAKSIONIS
     * paralel (skip station ADMISI sepenuhnya).
     *
     * Walk-in dari kiosk tetap melewati antrian ADMISI (lihat daftarkanWalkIn).
     */
    /**
     * Render dokumen consent dari data form (pasien baru, belum ada Visit).
     * Return: ['html' => string, 'signature_fields' => [...], 'template_code' => string].
     */
    public function previewConsent(array $data): array
    {
        $code = $data['template_code'] ?: self::CONSENT_DEFAULT_CODE;

        $template = $this->resolveConsentTemplate($code);

        if (! $template) {
            throw new \Exception("Template consent '{$code}' tidak ditemukan / tidak aktif.", 404);
        }

        // Map kolom form → nilai untuk binding db patient.*/visit.*
        $formValues = [
            'name'          => $data['name']          ?? null,
            'nik'           => $data['nik']           ?? null,
            'no_rm'         => $data['no_rm']          ?? null,
            'gender'        => $data['gender']         ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'address'       => $data['address']       ?? null,
            'phone'         => $data['phone']         ?? null,
            // visit.* bindings
            'visit_date'    => now()->format('d/m/Y'),
        ];

        // TTD preview per signer_type (SVG).
        $sigByType = [];
        foreach (($data['signatures'] ?? []) as $sig) {
            $st  = $sig['signer_type'] ?? null;
            $svg = $sig['signature_svg'] ?? null;
            if ($st && is_string($svg) && $svg !== '') {
                $sigByType[$st] = $svg;
            }
        }

        $html = $this->documentRenderer->renderForPreview(
            $template,
            $formValues,
            $data['static_payload'] ?? [],
            $sigByType,
        );

        // Daftar field signature_canvas (untuk frontend tahu TTD apa yang diminta).
        $signatureFields = [];
        foreach (($template->field_schema['fields'] ?? []) as $f) {
            if (($f['type'] ?? null) === 'signature_canvas') {
                $signatureFields[] = [
                    'key'         => $f['key'] ?? null,
                    'label'       => $f['label'] ?? null,
                    'signer_type' => $f['signer_type'] ?? null,
                    'required'    => (bool) ($f['required'] ?? false),
                ];
            }
        }

        return [
            'html'             => $html,
            'signature_fields' => $signatureFields,
            'template_code'    => $template->code,
        ];
    }

    /**
     * Resolusi pre-op bedah: validasi jadwal, auto-shift tanggal (kecuali pre-op
     * rawat inap), dan tentukan inpatient_reason. Dipakai registerVisit DAN
     * daftarkanWalkIn supaya kedua jalur pendaftaran konsisten (Fase 8A/8B).
     *
     * @return array{visit_type:string, surgery_schedule_id:?string, inpatient_reason:?string}
     */
    private function resolvePreopSchedule(array $data, string $patientId, ?string $employeeId): array
    {
        $visitType         = $data['visit_type'] ?? 'REGULAR';
        $surgeryScheduleId = null;
        $inpatientReason   = null;

        if ($visitType !== 'PREOP_BEDAH') {
            return ['visit_type' => 'REGULAR', 'surgery_schedule_id' => null, 'inpatient_reason' => null];
        }

        if (empty($data['surgery_schedule_id'])) {
            throw new \Exception('surgery_schedule_id wajib untuk visit_type PREOP_BEDAH.', 422);
        }

        $schedule = SurgerySchedule::lockForUpdate()->find($data['surgery_schedule_id']);
        if (! $schedule) {
            throw new \Exception('Jadwal bedah tidak ditemukan.', 404);
        }

        // Schedule harus milik patient yg sama (lewat surgery_requests.visit.patient_id)
        $belongsToPatient = $schedule->surgeryRequests()
            ->whereHas('visit', fn ($q) => $q->where('patient_id', $patientId))
            ->exists();
        if (! $belongsToPatient) {
            throw new \Exception('Jadwal bedah ini bukan milik pasien yang dipilih.', 422);
        }

        if (! in_array($schedule->status, ['SCHEDULED', 'IN_PROGRESS'])) {
            throw new \Exception("Jadwal bedah status {$schedule->status} tidak boleh diproses preop.", 422);
        }

        if ($schedule->scheduled_date->isBefore(today())) {
            throw new \Exception('Jadwal bedah sudah lewat tanggal (di masa lalu).', 422);
        }

        // Auto-shift: kalau jadwal bukan hari ini, geser ke hari ini + audit log.
        // KECUALI pre-op RAWAT INAP (requires_inpatient): pasien sengaja datang
        // H-1 untuk diopname, operasi tetap di tanggal asli (H). Menggeser ke hari
        // ini akan salah — jadwal operasi inap dipertahankan apa adanya (Fase 8B).
        if (! $schedule->scheduled_date->isToday() && ! $schedule->requires_inpatient) {
            $oldDate = $schedule->scheduled_date->copy();
            $schedule->update(['scheduled_date' => today()]);

            SurgeryScheduleAuditLog::create([
                'surgery_schedule_id' => $schedule->id,
                'old_date'   => $oldDate,
                'new_date'   => today(),
                'reason'     => 'PREOP_WALKIN: pasien datang preop di luar jadwal asli',
                'changed_by_id' => $employeeId,
                'changed_at' => now(),
            ]);
        }

        $surgeryScheduleId = $schedule->id;

        // Fase 8B — pre-op RAWAT INAP: jadwal bedah ditandai requires_inpatient
        // oleh dokter (8A). Pasien datang H-1 → setelah TR+REF tidak ke BEDAH,
        // tapi ke MENUNGGU_RANAP (perawat tekan "Kirim ke Rawat Inap"). Penanda
        // PRE_OP dibawa di visit supaya banner/papan tahu konteks & gate tombolnya.
        if ($schedule->requires_inpatient) {
            $inpatientReason = 'PRE_OP';
        }

        return [
            'visit_type'          => 'PREOP_BEDAH',
            'surgery_schedule_id' => $surgeryScheduleId,
            'inpatient_reason'    => $inpatientReason,
        ];
    }

    public function registerVisit(array $data): Visit
    {
        $visit = DB::transaction(function () use ($data) {
            // Resolve patient
            // Foto untuk kunjungan ini. File disimpan sekali, dirujuk oleh
            // visits.photo_path (riwayat per-kunjungan) DAN patients.photo_path (terbaru).
            $photoPath = null;
            if (! empty($data['patient_id'])) {
                $patient = Patient::findOrFail($data['patient_id']);
                if (! empty($data['photo'])) {
                    $photoPath = $this->savePatientPhoto($data['photo'], $patient->name);
                    $patient->update(['photo_path' => $photoPath]); // foto terbaru pasien
                }
                // Petugas memilih ulang wilayah di wizard (data lama tak cocok master).
                $this->applyWilayahUpdate($patient, $data['update_wilayah'] ?? null);
            } else {
                $patient   = $this->storePasien($data);   // storePasien sudah simpan foto
                $photoPath = $patient->photo_path;
            }

            $user = auth('api')->user();

            // ─── Guard: cegah visit aktif ganda ──────────────────────────
            // Pasien tidak boleh punya >1 kunjungan rawat JALAN yang masih
            // berjalan (current_station != SELESAI). Mencegah data visit rangkap
            // saat pasien lama (mis. nyangkut dari hari sebelumnya) didaftar ulang.
            // lockForUpdate utk anti-race dua registrasi bersamaan.
            //
            // RANAP DIKECUALIKAN: visit rawat inap long-lived (bertahan berhari-hari)
            // tidak boleh memblok pendaftaran rawat jalan baru (mis. kontrol poli
            // lain selama pasien masih dirawat). Lihat plan Rawat Inap Fase 2.
            $activeVisit = Visit::where('patient_id', $patient->id)
                ->where('current_station', '!=', 'SELESAI')
                ->where(function ($q) {
                    $q->where('jenis_pelayanan', '!=', 'RANAP')
                      ->orWhereNull('jenis_pelayanan');
                })
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();
            if ($activeVisit) {
                $stationLabel = $activeVisit->current_station ?? '-';
                $regLabel     = $activeVisit->no_registrasi ?? substr($activeVisit->id, 0, 8);
                $tgl          = optional($activeVisit->visit_date)->format('d/m/Y') ?? '-';
                throw new \Exception(
                    "Pasien {$patient->name} masih punya kunjungan aktif (No. {$regLabel}, tgl {$tgl}, di stasiun {$stationLabel}). "
                    . "Selesaikan atau batalkan kunjungan itu dulu sebelum mendaftarkan kunjungan baru.",
                    422
                );
            }

            // ─── Preop Bedah: validasi & auto-shift jadwal ───────────────
            $preop = $this->resolvePreopSchedule($data, $patient->id, $user?->employee_id);
            $visitType         = $preop['visit_type'];
            $surgeryScheduleId = $preop['surgery_schedule_id'];
            $inpatientReason   = $preop['inpatient_reason']; // Fase 8B: PRE_OP bila jadwal butuh rawat inap.

            // Asuransi/Perusahaan non-BPJS → flag PENDING untuk diverifikasi billing
            // ke portal TPA secara paralel. UMUM/BPJS/SOSIAL → NONE (skip alur TPA).
            $needsTpaVerification = in_array(
                $data['guarantor_type'] ?? null,
                ['ASURANSI', 'PERUSAHAAN'],
                true
            );

            $visit = Visit::create([
                'patient_id'         => $patient->id,
                'insurer_id'         => $data['insurer_id'] ?? null,
                'registered_by_id'   => $user->employee_id,
                'doctor_schedule_id' => $data['doctor_schedule_id'],
                'no_registrasi'      => $this->generateNoRegistrasi(),
                'photo_path'         => $photoPath,
                'visit_date'         => today(),
                'classification'     => $data['classification'],
                'visit_type'         => $visitType,
                'surgery_schedule_id' => $surgeryScheduleId,
                'inpatient_reason'   => $inpatientReason, // Fase 8B: PRE_OP utk pre-op inap
                'current_station'    => 'TRIASE',       // skip ADMISI, langsung ke TR
                'guarantor_type'     => $data['guarantor_type'],
                'bpjs_booking_code'  => $data['bpjs_booking_code'] ?? null,
                'no_rujukan'         => $data['bpjs_referral_no'] ?? null,
                'no_surat_kontrol'   => $data['bpjs_control_no'] ?? null,
                'satusehat_sync_status' => 'PENDING',
                'insurance_verification_status' => $needsTpaVerification ? 'PENDING' : 'NONE',
            ]);

            // Buat insurance_verifications awal (status PENDING) supaya billing langsung
            // bisa lihat row-nya di tab "Verifikasi Pending". Data kartu fisik (policy_number,
            // member_name, member_card_number) sudah diinput petugas admisi dari kartu.
            if ($needsTpaVerification && ! empty($data['insurer_id'])) {
                \App\Models\InsuranceVerification::create([
                    'visit_id'           => $visit->id,
                    'insurer_id'         => $data['insurer_id'],
                    'verified_by'        => null,
                    'status'             => 'PENDING',
                    'policy_number'      => $data['policy_number']      ?? null,
                    'member_name'        => $data['member_name']        ?? null,
                    'member_card_number' => $data['member_card_number'] ?? null,
                ]);
            }

            // COB — penjamin kedua (opsional). Simpan ke visit_cob.
            $this->saveCob($visit->id, $data['cob'] ?? null);

            // General Consent (opsional) — diteken pasien/wali di Admisi sebelum
            // konfirmasi. Non-fatal: gagal simpan consent TIDAK menggagalkan
            // registrasi visit (GC bersifat opsional & fleksibel).
            if (! empty($data['consent'])) {
                try {
                    $this->saveConsentDocument($visit, $patient, $data['consent']);
                } catch (\Throwable $e) {
                    \Log::warning('Gagal simpan General Consent saat registrasi visit', [
                        'visit_id' => $visit->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            // Langsung enqueue TRIASE + REFRAKSIONIS paralel dengan nomor antrean TR-NNN
            // yang shared (mengikuti pattern QueueService::advanceFromStation).
            $sharedNumber = $this->queueService->generateQueueNumber('TRIASE');
            $this->queueService->enqueue($visit->id, 'TRIASE', $sharedNumber);
            $this->queueService->enqueue($visit->id, 'REFRAKSIONIS', $sharedNumber);

            $this->log(
                $user->id,
                'DAFTAR_KUNJUNGAN',
                Visit::class,
                $visit->id,
                "Kunjungan baru (direct admisi → TR): {$patient->name} ({$data['guarantor_type']}) — {$sharedNumber['queue_number']}"
            );

            return $visit->load(['patient', 'queues']);
        });

        // Lapor antrean/add ke BPJS SETELAH commit — non-blocking (skip diam-diam bila
        // bukan BPJS / ANTREAN nonaktif / poli belum dipetakan). TIDAK pernah melempar.
        $this->queueService->reportAntreanAdd($visit);

        return $visit;
    }

    /**
     * Simpan General Consent yang sudah diteken pasien/wali di Admisi sebagai
     * PatientDocument FINALIZED + signature. Dipanggil DALAM transaksi
     * registerVisit (visit & patient sudah ada).
     *
     * Alur (mengikuti pola FormRegistryService::finalize + SignatureService):
     *   1. Cari template aktif by code.
     *   2. Buat PatientDocument status DRAFT.
     *   3. Capture tiap signature (SignatureService menolak kalau sudah FINALIZED).
     *   4. Render HTML + embed SVG signature dari record yang baru disimpan.
     *   5. Gzip → simpan, set status FINALIZED + hash.
     *
     * @param array{template_code?:string, signatures?:array, static_payload?:array} $consent
     */
    private function saveConsentDocument(Visit $visit, Patient $patient, array $consent): void
    {
        $code = $consent['template_code'] ?? self::CONSENT_DEFAULT_CODE;

        $template = $this->resolveConsentTemplate($code);
        if (! $template) {
            throw new \RuntimeException("Template consent '{$code}' tidak ditemukan.");
        }

        $user = auth('api')->user();

        // 1. PatientDocument DRAFT.
        $doc = PatientDocument::create([
            'patient_id'         => $patient->id,
            'visit_id'           => $visit->id,
            'document_type_id'   => $template->document_type_id,
            'status'             => 'DRAFT',
            'created_by_station' => 'ADMISI',
            'template_code'      => $template->code,
            'template_version'   => $template->version,
            'signatures'         => ['static_payload' => $consent['static_payload'] ?? []],
        ]);

        // 2. Capture signatures.
        foreach (($consent['signatures'] ?? []) as $sig) {
            $signerType = $sig['signer_type'] ?? null;
            if (! $signerType) continue;

            $capture = [
                'patient_document_id'  => $doc->id,
                'signer_type'          => $signerType,
                'signature_svg'        => $sig['signature_svg'] ?? null,
                'signature_png_base64' => $sig['signature_png_base64'] ?? null,
                'biometric_metadata'   => $sig['biometric_metadata'] ?? null,
                'audit_log'            => $sig['audit_log'] ?? [],
                'captured_by_facilitator_user_id' => $user?->id,
            ];

            // Identity routing — pasien = signer_patient_id; witness/guardian = external.
            if ($signerType === 'patient') {
                $capture['signer_patient_id'] = $patient->id;
            } elseif (in_array($signerType, ['witness', 'guardian'], true)) {
                $ext = $sig['external_identity'] ?? null;
                // Fallback nama supaya lolos validasi SignatureService (butuh 'nama').
                if (! is_array($ext) || empty($ext['nama'])) {
                    $ext = ['nama' => $ext['nama'] ?? 'Saksi'];
                }
                $capture['signer_external_identity'] = $ext;
            }

            $this->signatureService->capture($capture);
        }

        // 3. Render + embed signature dari record yang baru disimpan.
        $payload = $doc->signatures['static_payload'] ?? [];
        $html = $this->documentRenderer->render($template, $visit->id, $payload);

        $schema   = $template->field_schema ?? [];
        $fieldMap = $this->documentRenderer->extractSignatureFieldMap($schema);
        $sigByType = \App\Models\DocumentSignature::query()
            ->where('patient_document_id', $doc->id)
            ->get()
            ->keyBy('signer_type');
        $html = $this->documentRenderer->embedSignatures($html, $fieldMap, $sigByType->all());

        // 4. Finalize. Simpan HTML plain di `rendered_html` (longText) — BUKAN
        //    gzip ke `rendered_html_gz`. Kolom _gz bertipe bytea di Postgres dan
        //    binding string hasil gzcompress() ditolak ('invalid byte sequence
        //    for encoding UTF8'). getSnapshot() sudah punya fallback ke plain
        //    rendered_html, jadi tetap kompatibel. Dokumen GC kecil (~2KB) →
        //    gzip tidak memberi keuntungan berarti.
        $sigIds = $sigByType->pluck('signature_id')->sort()->values()->all();
        $hash   = hash('sha256', $html . '|' . implode(',', $sigIds) . '|' . $doc->id);

        $doc->rendered_html        = $html;
        $doc->rendered_html_gz     = null;
        $doc->status               = 'FINALIZED';
        $doc->finalized_at         = now();
        $doc->final_integrity_hash = $hash;
        $doc->save();
    }

    // =========================================================================
    // DAFTARKAN WALK-IN (dari kiosk anjungan)
    // =========================================================================

    /**
     * Update Visit walk-in (dari kiosk) dengan data registrasi lengkap.
     *
     * Dua skenario:
     *  A. patient_id diisi (pasien lama) → ganti visit.patient_id ke pasien real,
     *     soft-delete placeholder patient
     *  B. patient_id kosong (pasien baru) → update placeholder patient dengan
     *     data baru (NIK, nama, gender, DOB, dll) DAN generate no_rm. Kiosk hanya
     *     menerbitkan nomor antrean — no_rm baru lahir saat pasien resmi terdaftar.
     *
     * Pre-condition:
     *  - Visit harus masih placeholder (patient.name === 'Belum Terdaftar')
     *  - Visit harus masih di station ADMISI
     */
    public function daftarkanWalkIn(string $visitId, array $data): Visit
    {
        $visit = DB::transaction(function () use ($visitId, $data) {
            $visit = Visit::with('patient')->lockForUpdate()->findOrFail($visitId);

            if ($visit->patient->name !== 'Belum Terdaftar') {
                throw new \Exception('Kunjungan ini bukan walk-in dari kiosk (sudah terdaftar).', 422);
            }
            if ($visit->current_station !== 'ADMISI') {
                throw new \Exception('Pasien sudah melewati admisi, tidak bisa diubah.', 422);
            }

            // Workflow gate: pasien harus DIPANGGIL dulu sebelum boleh didaftarkan.
            // Tanpa filter tanggal: status aktif (WAITING/CALLED/IN_PROGRESS) sudah
            // membatasi; visit lintas-hari yang antrian admisinya dibuat kemarin tetap
            // bisa diproses.
            $admisiQueue = Queue::where('visit_id', $visit->id)
                ->where('station', Queue::STATION_ADMISI)
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->orderByDesc('created_at')
                ->first();

            if (! $admisiQueue) {
                throw new \Exception('Antrian admisi tidak ditemukan untuk kunjungan ini.', 422);
            }
            if ($admisiQueue->status === Queue::STATUS_WAITING) {
                throw new \Exception('Pasien harus dipanggil terlebih dahulu sebelum didaftarkan.', 422);
            }

            $placeholder = $visit->patient;
            $user        = auth('api')->user();

            // Foto kunjungan ini — disimpan sekali, dipakai visit + patient (terbaru).
            $photoPath = null;

            // ─── Resolve Patient ─────────────────────────────────────────
            if (! empty($data['patient_id'])) {
                // Skenario A — pasien lama
                $real = Patient::findOrFail($data['patient_id']);

                if (! empty($data['photo'])) {
                    $photoPath = $this->savePatientPhoto($data['photo'], $real->name);
                    $real->update(['photo_path' => $photoPath]);
                }
                // Petugas memilih ulang wilayah di wizard (data lama tak cocok master).
                $this->applyWilayahUpdate($real, $data['update_wilayah'] ?? null);

                $visit->patient_id = $real->id;

                // Placeholder tidak dipakai lagi → soft-delete
                $placeholder->delete();
            } else {
                // Skenario B — pasien baru: cek NIK unique (kecuali placeholder sendiri).
                // Lewati cek jika tanpa NIK (mis. identitas Paspor/SIM/KIA/Tanpa Identitas).
                if (! empty($data['nik'])) {
                    $existing = Patient::where('nik', $data['nik'])
                        ->where('id', '!=', $placeholder->id)
                        ->first();

                    if ($existing) {
                        throw new \Exception("NIK {$data['nik']} sudah terdaftar atas nama {$existing->name}. Gunakan mode 'Pasien Lama'.", 422);
                    }
                }

                $photoPath = $this->savePatientPhoto($data['photo'] ?? null, $data['name']);

                // Generate no_rm SEKARANG — kiosk hanya kasih nomor antrean, RM dibuat
                // begitu pasien resmi terdaftar.
                $placeholder->update([
                    'no_rm'         => $placeholder->no_rm ?? $this->generateNoRM(),
                    'identity_type' => $data['identity_type'] ?? 'KTP',
                    'nik'           => $data['nik']           ?? null,
                    'name'          => $data['name'],
                    'gender'        => $data['gender'],
                    'date_of_birth' => $data['date_of_birth'],
                    'phone'         => $data['phone']         ?? null,
                    'family_phone'  => $data['family_phone']  ?? null,
                    'email'         => $data['email']         ?? null,
                    'address'       => $data['address']       ?? null,
                    'province'      => $data['province']      ?? null,
                    'bpjs_number'   => $this->normalizeBpjsNumber($data['bpjs_number'] ?? null),
                    'blood_type'    => $data['blood_type']    ?? null,
                    'allergy_notes' => $data['allergy_notes'] ?? null,
                    'photo_path'    => $photoPath,
                ]);

                $real = $placeholder->fresh();
            }

            // Asuransi/Perusahaan non-BPJS → flag PENDING (lihat catatan di registerVisit)
            $needsTpaVerificationWalkin = in_array(
                $data['guarantor_type'] ?? null,
                ['ASURANSI', 'PERUSAHAAN'],
                true
            );

            // Pre-op bedah (pasien pre-op via Anjungan/walk-in). Konsisten dgn
            // registerVisit — tanpa ini pasien pre-op kehilangan routing & PRE_OP inap.
            $preop = $this->resolvePreopSchedule($data, $real->id, $user?->employee_id);

            // ─── Update Visit ────────────────────────────────────────────
            $visit->update([
                'patient_id'         => $real->id,
                'insurer_id'         => $data['insurer_id'] ?? null,
                'registered_by_id'   => $user?->employee_id,
                'doctor_schedule_id' => $data['doctor_schedule_id'],
                'no_registrasi'      => $visit->no_registrasi ?? $this->generateNoRegistrasi(),
                'photo_path'         => $photoPath ?? $visit->photo_path,
                'classification'     => $data['classification'],
                'visit_type'          => $preop['visit_type'],
                'surgery_schedule_id' => $preop['surgery_schedule_id'],
                'inpatient_reason'    => $preop['inpatient_reason'],
                'guarantor_type'     => $data['guarantor_type'],
                'bpjs_booking_code'  => $data['bpjs_booking_code'] ?? null,
                'no_rujukan'         => $data['bpjs_referral_no'] ?? null,
                'no_surat_kontrol'   => $data['bpjs_control_no'] ?? null,
                'insurance_verification_status' => $needsTpaVerificationWalkin ? 'PENDING' : 'NONE',
            ]);

            if ($needsTpaVerificationWalkin && ! empty($data['insurer_id'])) {
                \App\Models\InsuranceVerification::create([
                    'visit_id'           => $visit->id,
                    'insurer_id'         => $data['insurer_id'],
                    'verified_by'        => null,
                    'status'             => 'PENDING',
                    'policy_number'      => $data['policy_number']      ?? null,
                    'member_name'        => $data['member_name']        ?? null,
                    'member_card_number' => $data['member_card_number'] ?? null,
                ]);
            }

            // COB — penjamin kedua (opsional). Simpan ke visit_cob.
            $this->saveCob($visit->id, $data['cob'] ?? null);

            // ─── Auto-advance ke TRIASE + REFRAKSIONIS ───────────────────
            // Selesaikan admisi otomatis — pasien sudah teridentifikasi & terdaftar.
            // reportBpjs=false: pelaporan taskid 1→2→3 dilakukan pasca-commit dgn
            // urutan benar via reportAdmisiKioskTasks (lihat di bawah).
            $this->queueService->advanceFromStation($admisiQueue->id, Queue::STATION_ADMISI, reportBpjs: false);

            $this->log(
                $user?->id,
                'DAFTAR_WALKIN',
                Visit::class,
                $visit->id,
                "Walk-in terdaftar: {$real->name} ({$data['guarantor_type']}) — auto-advance ke TRIASE+REFRAKSIONIS"
            );

            return $visit->fresh(['patient', 'queues']);
        });

        // Jalur KIOSK → loket admisi: lapor antrean/add + taskid 1 (pilih ke loket)
        // & 2 (selesai daftar) ke BPJS SETELAH commit — non-blocking, skip diam-diam
        // bila bukan BPJS / ANTREAN nonaktif / poli belum dipetakan. Tak pernah melempar.
        $this->queueService->reportAdmisiKioskTasks($visit);

        return $visit;
    }

    // =========================================================================
    // ANJUNGAN MANDIRI (Kiosk — Public)
    // =========================================================================

    /**
     * Kiosk self-service: ambil tiket antrean UMUM untuk Loket Admisi.
     * Anonymous walk-in — petugas admisi akan lengkapi data pasien saat panggil.
     *
     * Flow:
     *  1. Buat Patient placeholder (NIK unik berbasis microtime, nama "Walk-In Anjungan #A-NNN")
     *  2. Buat Visit (UMUM, classification=Baru, current_station=ADMISI)
     *  3. Buat Queue ADMISI
     *  4. Broadcast AdmisiQueueUpdated (action=added) → AdmisiView auto-append
     */
    public function ambilTiketUmumKiosk(): array
    {
        return DB::transaction(function () {
            // Generate nomor antrean ADMISI (yg jadi suffix nama placeholder)
            $queueData = $this->generateQueueNumber('ADMISI');
            $queueNumber = $queueData['queue_number']; // e.g. A-007

            // 1. Patient placeholder — identitas BELUM ADA (akan diisi saat petugas admisi daftarkan)
            //    no_rm sengaja NULL — baru di-generate saat daftarkanWalkIn sukses.
            $placeholderNik = $this->generateWalkInNik();
            $patient = Patient::create([
                'no_rm'       => null,
                'nik'         => $placeholderNik,
                'name'        => 'Belum Terdaftar',
                'gender'      => null,
                'date_of_birth' => null,
                'is_active'   => true,
            ]);

            // 2. Visit
            $visit = Visit::create([
                'patient_id'        => $patient->id,
                'insurer_id'        => null,
                'registered_by_id'  => null, // kiosk anonymous
                'visit_date'        => today(),
                'classification'    => 'Baru',
                'current_station'   => 'ADMISI',
                'guarantor_type'    => 'UMUM',
                'satusehat_sync_status' => 'PENDING',
            ]);

            // 3. Queue ADMISI (pakai queueData yg sudah di-reserve di awal)
            $queue = Queue::create([
                'visit_id'       => $visit->id,
                'station'        => 'ADMISI',
                'queue_prefix'   => $queueData['queue_prefix'],
                'queue_sequence' => $queueData['queue_sequence'],
                'queue_number'   => $queueData['queue_number'],
                'status'         => 'WAITING',
            ]);

            // 4. Broadcast — dua channel:
            //    a) admisi-queue  → AdmisiView (full payload dengan visit details)
            //    b) antrean-tv    → AntreanTVView (payload lebih ramping)
            $queue->load('visit.patient');
            $admisiPayload = [
                'id'           => $queue->id,
                'visit_id'     => $queue->visit_id,
                'queue_number' => $queue->queue_number,
                'station'      => $queue->station,
                'status'       => $queue->status,
                'created_at'   => $queue->created_at?->toIso8601String(),
                'visit'        => [
                    'id'              => $visit->id,
                    'guarantor_type'  => $visit->guarantor_type,
                    'classification'  => $visit->classification,
                    'current_station' => $visit->current_station,
                    'patient'         => [
                        'id'    => $patient->id,
                        'name'  => $patient->name,
                        'no_rm' => $patient->no_rm,
                    ],
                ],
            ];
            broadcast(new AdmisiQueueUpdated($admisiPayload, 'added'));

            broadcast(new AntreanTvUpdated([
                'id'             => $queue->id,
                'visit_id'       => $queue->visit_id,
                'station'        => $queue->station,
                'queue_number'   => $queue->queue_number,
                'queue_sequence' => $queue->queue_sequence,
                'status'         => $queue->status,
                'called_at'      => null,
                'patient'        => [
                    'no_rm' => $patient->no_rm,
                    'name'  => $patient->name,
                ],
            ], 'added'));

            $this->log(
                null,
                'ANJUNGAN_TIKET_UMUM',
                Visit::class,
                $visit->id,
                "Kiosk: tiket umum {$queueNumber} — patient placeholder {$patient->id}"
            );

            return [
                'queue_number' => $queue->queue_number,
                'queue_id'     => $queue->id,
                'visit_id'     => $visit->id,
                'patient_id'   => $patient->id,
                'no_rm'        => $patient->no_rm,
                'station'      => 'ADMISI',
            ];
        });
    }

    /**
     * Generate NIK placeholder untuk walk-in kiosk (16 digit, unik).
     * Format: '9' + 9 digit unix timestamp + 6 digit random.
     * NIK asli warga selalu diawali kode wilayah 1-3, jadi '9' aman dibedakan.
     */
    private function generateWalkInNik(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $ts     = str_pad((string) time(), 9, '0', STR_PAD_LEFT);            // 9 digit
            $rand   = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); // 6 digit
            $candidate = '9' . substr($ts, -9) . $rand;                          // total 16 digit

            if (! Patient::where('nik', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Gagal generate NIK walk-in unik setelah 10 percobaan.');
    }

    // =========================================================================
    // ANTRIAN ADMISI
    // =========================================================================

    public function getAntrian(): Collection
    {
        $queues = Queue::with([
            'visit.patient',
            'visit.doctorSchedule.employee:id,name',
            'visit.insurer',
            'visit.internalReferralFromSchedule:id,poliklinik',
        ])
            ->where('station', 'ADMISI')
            ->boardVisible()   // hari ini ATAU masih aktif lintas-hari (≤7 hari) — pasien nyangkut tak hilang
            // HANYA yang benar-benar "siap dipanggil ke loket": WAITING / CALLED.
            // Begitu didaftarkan & auto-advance ke TRIASE, queue ADMISI jadi COMPLETED
            // → harus lenyap dari panel "Siap Dipanggil ke Loket Admisi".
            ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED])
            ->whereHas('visit')                       // exclude queue dgn visit soft-deleted (zombie row)
            ->orderBy('queue_sequence')
            ->get();

        // Set flag general_consent_signed per visit (sama seperti getVisits()),
        // supaya frontend mapQueueItem bisa pakai field yang konsisten.
        $patientIds = $queues->pluck('visit.patient_id')->filter()->unique()->values()->toArray();

        $signedPatients = empty($patientIds)
            ? collect()
            : PatientDocument::whereIn('patient_id', $patientIds)
                ->where('status', 'FINALIZED')
                ->whereHas('documentType', fn ($q) => $q
                    ->where('code', 'RM-1.1')
                    ->orWhere('name', 'ilike', '%general consent%')
                )
                ->pluck('patient_id')
                ->unique()
                ->flip();

        foreach ($queues as $queue) {
            if ($queue->visit) {
                $queue->visit->general_consent_signed = isset($signedPatients[$queue->visit->patient_id]);
            }
        }

        return $queues;
    }

    public function createAntrianAdmisi(string $visitId): Queue
    {
        $queueData = $this->generateQueueNumber('ADMISI');

        return Queue::create([
            'visit_id'       => $visitId,
            'station'        => 'ADMISI',
            'queue_prefix'   => $queueData['queue_prefix'],
            'queue_sequence' => $queueData['queue_sequence'],
            'queue_number'   => $queueData['queue_number'],
            'status'         => 'WAITING',
        ]);
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_ADMISI)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Selesai antrian Admisi (Section 11.3 step 2) → enqueue TRIASE & REFRAKSIONIS paralel.
     * Transisi sekarang di-delegate ke QueueService::advanceFromStation,
     * yang baca aturan dari resolveNextStation('ADMISI') => [TRIASE, REFRAKSIONIS].
     */
    public function selesaiAdmisi(string $queueId): Visit
    {
        $queue = Queue::with('visit')->byStation(Queue::STATION_ADMISI)->findOrFail($queueId);

        if ($queue->visit->current_station !== 'ADMISI') {
            throw new \Exception('Kunjungan ini sudah melewati admisi.', 422);
        }

        $result = $this->queueService->advanceFromStation($queue->id, Queue::STATION_ADMISI);

        $this->log(
            auth('api')->id(),
            'SELESAI_ADMISI',
            Visit::class,
            $result['visit']->id,
            "Admisi selesai — antrian TRIASE + REFRAKSIONIS dibuat"
        );

        return $result['visit'];
    }

    // =========================================================================
    // JADWAL DOKTER
    // =========================================================================

    public function getDoctorSchedules(): array
    {
        $todayDow = (int) date('N'); // 1=Mon..7=Sun

        $doctors = Employee::with(['doctorSchedules' => fn ($q) => $q
            ->where('is_active', true)
            ->orderBy('day_of_week'),
        ])
            ->where('is_active', true)
            ->whereHas('user', fn ($q) => $q
                ->whereHas('role', fn ($rq) => $rq->where('name', 'dokter'))
            )
            ->get();

        return $doctors->map(fn ($doc) => [
            'id'        => $doc->id,
            'name'      => $doc->name,
            'sip'       => $doc->sip ?? null,
            'schedules' => $doc->doctorSchedules->map(fn ($s) => [
                'id'          => $s->id,
                'day_of_week' => $s->day_of_week,
                'day_label'   => ['', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'][$s->day_of_week] ?? '',
                'start_time'  => $s->start_time,
                'end_time'    => $s->end_time,
                'room'        => $s->room,
                'is_active'   => $s->is_active,
                'is_today'    => $s->day_of_week === $todayDow,
            ])->values(),
        ])->toArray();
    }

    public function updateDoctorSchedule(string $id, array $data): DoctorSchedule
    {
        $schedule = DoctorSchedule::findOrFail($id);

        $schedule->update(array_filter([
            'start_time' => $data['start_time'] ?? null,
            'end_time'   => $data['end_time'] ?? null,
            'room'       => $data['room'] ?? null,
            'is_active'  => $data['is_active'] ?? null,
        ], fn ($v) => ! is_null($v)));

        return $schedule->fresh(['employee']);
    }

    public function createDoctorSchedule(string $employeeId, array $data): DoctorSchedule
    {
        $schedule = DoctorSchedule::create([
            'employee_id' => $employeeId,
            'day_of_week' => $data['day_of_week'],
            'start_time'  => $data['start_time'],
            'end_time'    => $data['end_time'],
            'room'        => $data['room'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
        ]);

        return $schedule->load('employee');
    }

    // =========================================================================
    // BPJS — live via BpjsVClaimService / BpjsAntreanService
    // Non-blocking: jika integrasi belum aktif → 503 jelas (tidak ganggu UMUM).
    // =========================================================================

    public function bpjsCekPeserta(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        $type       = ! empty($data['bpjs_number']) ? 'nokartu' : 'nik';
        $identifier = $data['bpjs_number'] ?: $data['nik'];

        return $this->vclaim->checkPeserta($identifier, $type);
    }

    /**
     * Terbitkan SEP untuk sebuah kunjungan BPJS. Membentuk t_sep dari data visit:
     * peserta (no kartu), rujukan, poli (mapping dari jadwal), DPJP, diagnosa awal.
     * Sukses → simpan visits.no_sep.
     */
    public function bpjsGenerateSep(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        // Lock baris visit (anti-race): auto-SEP saat registrasi + klik "Terbitkan SEP"
        // manual bisa nyaris bersamaan → tanpa lock keduanya lolos guard no_sep lalu
        // generate SEP DOBEL di BPJS. Serialize per-visit; re-cek no_sep di dalam lock.
        return DB::transaction(fn () => $this->generateSepLocked($data));
    }

    /** Inti penerbitan SEP — WAJIB dijalankan dalam DB::transaction + lockForUpdate. */
    private function generateSepLocked(array $data): array
    {
        $visit = Visit::with(['patient', 'doctorSchedule.employee'])
            ->lockForUpdate()
            ->findOrFail($data['visit_id']);

        if ($visit->no_sep) {
            throw new \Exception("Kunjungan ini sudah punya SEP: {$visit->no_sep}", 422);
        }

        // No. kartu: dari request → data pasien → (fallback) resolve dari NIK via Cek
        // Peserta. Pasien BPJS sering tersimpan TANPA No. Kartu (petugas isi NIK saja,
        // atau kartu di-blank saat BPJS offline → normalizeBpjsNumber jadikan NULL).
        // Daripada menolak SEP, tarik noKartu dari NIK lalu sembuhkan record pasien
        // supaya penerbitan SEP (dan operasi VClaim lain: SKDP/kontrol/SPRI) lancar.
        $data['bpjs_number'] = $data['bpjs_number'] ?? $visit->patient?->bpjs_number;
        if (empty($data['bpjs_number'])) {
            $nik = trim((string) ($visit->patient?->nik ?? ''));
            if ($nik === '') {
                throw new \Exception('No. Kartu BPJS & NIK pasien belum ada — lengkapi data pasien (Edit) dulu.', 422);
            }
            // Cek Peserta (NIK → noKartu + nama). Melempar 422 bila tak ditemukan di BPJS.
            $res     = $this->vclaim->checkPeserta($nik, 'nik');
            $peserta = $res['response']['peserta'] ?? null;
            $noKartu = $peserta['noKartu'] ?? null;
            if (! $noKartu) {
                throw new \Exception($res['metaData']['message'] ?? 'Peserta BPJS tidak ditemukan dari NIK pasien.', 422);
            }
            $data['bpjs_number'] = (string) $noKartu;
            // Heal record pasien — HANYA bila nama peserta BPJS cocok dgn nama pasien
            // (cegah NIK salah-ketik menulis kartu orang lain ke record). Best-effort.
            $this->backfillPatientBpjsNumber($visit->patient, $noKartu, $peserta['nama'] ?? null);
        }

        $jenisPelayanan = $visit->jenis_pelayanan ?? 'RAJAL';
        $isRanap        = $jenisPelayanan === 'RANAP';
        $isIgd          = $jenisPelayanan === 'IGD';

        $schedule   = $visit->doctorSchedule;
        // Poli IGD walk-in tak punya jadwal dokter → pakai poli_code konvensi 'IGD'
        // (sama dgn Queue::STATION_IGD / jenis_pelayanan). Admin memetakannya di
        // Jadwal Dokter → Pemetaan BPJS (poli_code 'IGD'). RAJAL/RANAP via schedule.
        $poliCode   = $isIgd ? 'IGD' : $schedule?->poli_code;
        $kodePoli   = BpjsPoliMapping::bpjsCodeFor($poliCode);
        $kodeDpjp   = $schedule?->employee?->bpjs_dpjp_code;
        $kodeFaskes = (string) (IntegrationConfig::where('system_name', 'VCLAIM')->first()->configuration['kode_faskes'] ?? '');

        if (! $kodePoli) {
            throw new \Exception("Poli '{$poliCode}' belum dipetakan ke kode BPJS. Atur di Jadwal Dokter → Pemetaan BPJS.", 422);
        }

        // Jenis pelayanan SEP conditional: RANAP='1' (rawat inap), RAJAL/IGD='2'.
        // Kelas rawat hak: untuk RANAP ambil dari visit.kelas_rawat_hak (kelas HAK
        // peserta, bukan kelas room titipan); fallback ke request/'3'.
        $klsRawatHak = (string) ($visit->kelas_rawat_hak ?? $data['kls_rawat'] ?? '3');

        // asalRujukan: '1'=FKTP (rujukan), '2'=gawat darurat (IGD tanpa rujukan FKTP).
        // SEP IGD wajib '2' sesuai regulasi BPJS gawat darurat.
        $asalRujukan = $isIgd ? '2' : '1';

        // No. rujukan & surat kontrol: utamakan dari request (SEP manual yg eksplisit),
        // fallback ke nilai yang DISIMPAN saat admisi (hasil "Tarik dari BPJS"/input petugas)
        // supaya tombol "Terbitkan SEP" (cuma kirim visit_id) tetap membawa nomor rujukan.
        $noRujukan      = $data['no_rujukan']       ?? $visit->no_rujukan       ?? '';
        $noSuratKontrol = $data['no_surat_kontrol'] ?? $visit->no_surat_kontrol ?? '';

        // tujuanKunj BPJS VClaim: '0'=Normal, '1'=Prosedur, '2'=Konsul Dokter.
        // Klinik ini hanya menerbitkan SEP Normal (rujukan FKTP) atau Kontrol (surat
        // kontrol) — KEDUANYA tujuanKunj '0' ('0'=Normal mencakup kunjungan kontrol;
        // kontrol ditandai lewat blok `skdp` yang terisi, BUKAN lewat tujuanKunj).
        // Bukti: log VClaim 19 Jun → tujuanKunj '2' utk pasien surat-kontrol ditolak
        // "tujuanKunj tidak sesuai" (code 201). '2' (Konsul) = konsultasi antar-spesialis,
        // beda dari kontrol.
        //
        // Aturan VClaim utk tujuanKunj '0': flagProcedure/kdPenunjang/assesmentPel/dpjpLayan
        // WAJIB kosong (DPJP diturunkan BPJS dari poli+rujukan, atau dari skdp.kodeDPJP saat
        // kontrol). skdp HANYA diisi saat ada surat kontrol — noSurat & kodeDPJP harus
        // konsisten (terisi/kosong bersama; kodeDPJP terisi tanpa noSurat → ditolak).
        // dpjpLayan & skdp harus KONSISTEN dgn jenis kunjungan (matriks dari log VClaim
        // 19 Jun): Normal (tanpa surat kontrol) → keduanya KOSONG (mengisi dpjpLayan saat
        // normal → "assesmentPel tidak sesuai"; DPJP diturunkan BPJS dari poli+rujukan).
        // Kontrol (ada surat kontrol) → skdp.noSurat+skdp.kodeDPJP DAN dpjpLayan terisi
        // (mengosongkan dpjpLayan saat skdp terisi → "tujuanKunj tidak sesuai").
        $adaSuratKontrol = trim((string) $noSuratKontrol) !== '';
        $tujuanKunj      = '0';
        $dpjpLayan       = $adaSuratKontrol ? ($kodeDpjp ?? '') : '';
        $skdp            = $adaSuratKontrol
            ? ['noSurat' => $noSuratKontrol, 'kodeDPJP' => $kodeDpjp ?? '']
            : ['noSurat' => '', 'kodeDPJP' => ''];
        // SEP kontrol berbasis surat kontrol (skdp), BUKAN rujukan FKTP. Mengirim
        // noRujukan + skdp bersamaan membuat "tujuan kunjungan" ambigu → BPJS tolak
        // "tujuanKunj tidak sesuai" (log VClaim NOVIDA: noRujukan & skdp dua-duanya
        // terisi). Untuk kunjungan kontrol, kosongkan noRujukan di payload (data
        // visit tetap utuh; ini hanya nilai yang dikirim ke VClaim).
        $noRujukanSep = $adaSuratKontrol ? '' : $noRujukan;
        // ppkRujukan: utk kunjungan NORMAL, BPJS menurunkannya dari noRujukan (boleh kosong).
        // Utk KONTROL (noRujukan dikosongkan, berbasis surat kontrol), BPJS tak bisa
        // menurunkannya → minta kode PPK eksplisit ("Kode PPK.Rujukan Tidak Sesuai").
        // Surat kontrol diterbitkan RS ini sendiri → ppkRujukan = kode faskes RS.
        $ppkRujukanSep = $adaSuratKontrol ? $kodeFaskes : ($data['ppk_rujukan'] ?? '');

        // noTelp: BPJS wajib ANGKA saja, maksimal 14 digit. Data pasien sering berisi
        // dua nomor ("0813… / 0859…"), spasi, tanda baca, atau >14 digit → BPJS tolak
        // "No.Telepon Diisi Dengan Benar (max 14 digit)". Ambil nomor PERTAMA, buang
        // semua non-digit, potong 14.
        $noTelpRaw = (string) ($visit->patient?->phone ?? '');
        $noTelp    = substr(preg_replace('/\D+/', '', preg_split('/[\/,;]/', $noTelpRaw)[0] ?? ''), 0, 14);

        // Diagnosa awal (kode ICD-10): BPJS menolak SEP bila kosong ("Diagnosa Awal
        // Tidak Boleh Kosong"). Urutan sumber: request eksplisit → yang DISIMPAN di
        // visit (hasil "Tarik dari BPJS"/input petugas) → auto-tarik dari rujukan FKTP
        // (rujukan.diagnosa.kode). Tombol "Terbitkan SEP" cuma kirim visit_id, jadi
        // tanpa fallback ini diagAwal selalu kosong → SEP gagal. Untuk IGD (asalRujukan
        // '2', tanpa rujukan FKTP) diagnosa wajib diisi manual lewat alur IGD.
        $diagAwal = trim((string) ($data['diag_awal'] ?? $visit->diagnosa_awal ?? ''));
        if ($diagAwal === '' && $noRujukan !== '' && ! $isIgd) {
            $resolved = $this->resolveDiagnosaFromRujukan($noRujukan, $visit->id);
            if ($resolved['kode'] !== '') {
                $diagAwal = $resolved['kode'];
                // Persist supaya tampil di Detail Kunjungan & tak perlu tarik ulang.
                $visit->forceFill([
                    'diagnosa_awal'      => $resolved['kode'],
                    'diagnosa_awal_nama' => $resolved['nama'] ?: $visit->diagnosa_awal_nama,
                ])->save();
            }
        }

        $tSep = [
            'noKartu'      => $data['bpjs_number'],
            'tglSep'       => $isRanap && $visit->admission_at
                ? \Illuminate\Support\Carbon::parse($visit->admission_at)->setTimezone('Asia/Jakarta')->toDateString()
                : now('Asia/Jakarta')->toDateString(),
            'ppkPelayanan' => $kodeFaskes,
            'jnsPelayanan' => $isRanap ? '1' : '2', // 1=Rawat Inap, 2=Rawat Jalan/IGD
            'klsRawat'     => ['klsRawatHak' => $klsRawatHak, 'klsRawatNaik' => '', 'pembiayaan' => '', 'penanggungJawab' => ''],
            'noMR'         => $visit->patient?->no_rm ?? '',
            'rujukan'      => [
                'asalRujukan' => $asalRujukan, // 1=FKTP, 2=gawat darurat (IGD)
                'tglRujukan'  => $data['tgl_rujukan'] ?? now('Asia/Jakarta')->toDateString(),
                'noRujukan'   => $noRujukanSep,
                'ppkRujukan'  => $ppkRujukanSep,
            ],
            'catatan'      => 'SEP dari Arumed',
            'diagAwal'     => $diagAwal,
            'poli'         => ['tujuan' => $kodePoli, 'eksekutif' => '0'],
            'cob'          => ['cob' => '0'],
            'katarak'      => ['katarak' => $data['katarak'] ?? '0'],
            'jaminan'      => ['lakaLantas' => '0', 'noLP' => '', 'penjamin' => ['tglKejadian' => '', 'keterangan' => '', 'suplesi' => ['suplesi' => '0', 'noSepSuplesi' => '', 'lokasiLaka' => ['kdPropinsi' => '', 'kdKabupaten' => '', 'kdKecamatan' => '']]]],
            'tujuanKunj'   => $tujuanKunj,
            'flagProcedure' => '',
            'kdPenunjang'  => '',
            'assesmentPel' => '',
            'skdp'         => $skdp,
            'dpjpLayan'    => $dpjpLayan,
            'noTelp'       => $noTelp,
            'user'         => auth('api')->user()?->name ?? 'arumed',
        ];

        $result = $this->vclaim->generateSep($tSep, $visit->id);

        $noSep = $result['response']['sep']['noSep'] ?? null;
        if ($noSep) {
            // Snapshot untuk cetak SEP: gabung response BPJS (kanonik bila ada)
            // dengan field yang kita kirim (jadi diagnosa/poli/rujukan tetap
            // tercatat meski response insert minim). Dipakai pdf.sep.blade.
            $sepSnapshot = array_merge(
                (array) ($result['response']['sep'] ?? []),
                [
                    'noSep'        => $noSep,
                    'tglSep'       => $tSep['tglSep'],
                    'noKartu'      => $tSep['noKartu'],
                    'jnsPelayanan' => $tSep['jnsPelayanan'],
                    'klsRawatHak'  => $klsRawatHak,
                    'diagAwal'     => $tSep['diagAwal'],
                    'poliTujuan'   => $kodePoli,
                    'noRujukan'    => $noRujukan,
                    'catatan'      => $tSep['catatan'],
                    'namaPeserta'  => $visit->patient?->name,
                ]
            );
            $visit->update(['no_sep' => $noSep, 'sep_data' => $sepSnapshot]);
        }

        return $result;
    }

    /**
     * Rakit data untuk cetak lembar SEP (pdf.sep.blade). Sumber utama = snapshot
     * `sep_data` yang disimpan saat penerbitan; field tampil di-fallback ke data
     * lokal (pasien/jadwal) supaya SEP lama (snapshot null) tetap bisa dicetak.
     */
    public function buildSepPrintData(string $visitId): array
    {
        $visit = Visit::with(['patient', 'doctorSchedule.employee'])->findOrFail($visitId);

        if (! $visit->no_sep) {
            throw new \Exception('Kunjungan ini belum punya SEP — terbitkan SEP dulu.', 422);
        }

        $sep      = (array) ($visit->sep_data ?? []);
        $patient  = $visit->patient;
        $schedule = $visit->doctorSchedule;

        $jenis = $visit->jenis_pelayanan ?? 'RAJAL';
        $jenisLabel = ['RANAP' => 'Rawat Inap', 'IGD' => 'Gawat Darurat', 'RAJAL' => 'Rawat Jalan'][$jenis] ?? 'Rawat Jalan';

        $kelasMap = ['1' => 'Kelas 1', '2' => 'Kelas 2', '3' => 'Kelas 3'];
        $klsHak   = (string) ($sep['klsRawatHak'] ?? $visit->kelas_rawat_hak ?? '');

        // Diagnosa awal: kode ICD-10 → nama (Indonesia bila ada).
        $diagCode = trim((string) ($sep['diagAwal'] ?? ''));
        $diagName = null;
        if ($diagCode !== '') {
            $row = \App\Models\Icd10Code::where('code', $diagCode)->first();
            $diagName = $row?->indonesian_description ?: $row?->description;
        }
        $diagnosa = $diagCode !== ''
            ? trim($diagCode . ($diagName ? ' - ' . $diagName : ''))
            : '—';

        // Poli tujuan: nama poli dari pemetaan BPJS (by poli_code); IGD walk-in
        // tak punya jadwal → label IGD. Kode BPJS hanya cadangan terakhir.
        $poliCode = $jenis === 'IGD' ? 'IGD' : $schedule?->poli_code;
        $poliName = $jenis === 'IGD'
            ? 'IGD'
            : (BpjsPoliMapping::where('poli_code', $poliCode)->value('poli_name')
                ?: ($sep['poliTujuan'] ?? '—'));

        $tglSep = $sep['tglSep'] ?? $visit->visit_date?->toDateString();

        $clinic = ClinicProfile::first();
        $print  = $clinic ? $clinic->receiptPrintSettings() : ClinicProfile::RECEIPT_PRINT_DEFAULTS;

        $genderLabel = ['L' => 'Laki-laki', 'P' => 'Perempuan'][$patient?->gender] ?? ($patient?->gender ?? '—');

        return [
            'clinic' => [
                'name'            => $clinic?->clinic_name,
                'letterhead_html' => $clinic ? $clinic->renderLetterheadHtml((bool) $print['show_logo']) : '',
                'watermark_type'  => ($print['show_watermark'] && $clinic?->watermark_enabled) ? $clinic?->watermark_type : null,
            ],
            'sep' => [
                'no_sep'        => $visit->no_sep,
                'tgl_sep'       => $tglSep ? \Illuminate\Support\Carbon::parse($tglSep)->format('d-m-Y') : '—',
                'jenis_rawat'   => $jenisLabel,
                'kelas_rawat'   => $kelasMap[$klsHak] ?? ($klsHak !== '' ? $klsHak : '—'),
                'diagnosa'      => $diagnosa,
                'poli'          => $poliName,
                'no_rujukan'    => $sep['noRujukan'] ?? $visit->no_rujukan ?: '—',
                'catatan'       => $sep['catatan'] ?? '—',
                'dpjp'          => $schedule?->employee?->name ?? '—',
                'cob'           => 'Tidak',
                'penjamin'      => 'BPJS Kesehatan',
            ],
            'patient' => [
                'no_kartu'  => $sep['noKartu'] ?? $patient?->bpjs_number ?? '—',
                'nama'      => $sep['namaPeserta'] ?? $patient?->name ?? '—',
                'no_rm'     => $patient?->no_rm ?? '—',
                'nik'       => $patient?->nik ?? '—',
                'tgl_lahir' => $patient?->date_of_birth ? \Illuminate\Support\Carbon::parse($patient->date_of_birth)->format('d-m-Y') : '—',
                'gender'    => $genderLabel,
                'phone'     => $patient?->phone ?? '—',
            ],
            'printed_by' => auth('api')->user()?->name ?? '—',
            'printed_at' => now('Asia/Jakarta')->format('d-m-Y H:i'),
        ];
    }

    public function bpjsCancelSep(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        $user   = auth('api')->user()?->name ?? 'arumed';
        $result = $this->vclaim->cancelSep($data['no_sep'], $user);

        // Sukses → kosongkan no_sep + snapshot SEP di visit terkait.
        if (($result['is_success'] ?? false)) {
            Visit::where('no_sep', $data['no_sep'])->update(['no_sep' => null, 'sep_data' => null]);
        }

        return $result;
    }

    public function bpjsCekRujukan(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        $sumber = ($data['sumber'] ?? 'fktp') === 'rs' ? 'rs' : 'fktp';

        return $sumber === 'rs'
            ? $this->vclaim->checkRujukan($data['no_rujukan'])
            : $this->vclaim->checkRujukanFktp($data['no_rujukan']);
    }

    public function bpjsCekSuratKontrol(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        return $this->vclaim->getSuratKontrol($data['no_surat_kontrol']);
    }

    /**
     * Pre-flight SEP: cek kesiapan data BPJS SEBELUM pasien didaftarkan, supaya
     * petugas memperbaiki masalah di wizard alih-alih dibanjiri notif "asesmen
     * tidak sesuai"/"Diagnosa Awal Tidak Boleh Kosong" saat auto-SEP berjalan.
     *
     * Tidak melempar (selain BPJS dimatikan) — selalu mengembalikan laporan
     * terstruktur; registrasi tetap boleh lanjut walau belum 100% hijau (BPJS
     * down ≠ blokir pendaftaran). Memeriksa: kepesertaan aktif, validitas rujukan,
     * pemetaan poli ke kode BPJS, kecocokan poli rujukan ↔ poli dokter, & diagnosa.
     *
     * @param  array  $data  doctor_schedule_id, sep_type(rujukan|kontrol|jkn),
     *                       no_rujukan, no_surat_kontrol, bpjs_number, nik
     */
    public function bpjsPreflightSep(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        $issues   = [];   // penghambat: SEP hampir pasti gagal bila tak dibetulkan
        $warnings = [];   // perlu perhatian, tapi tak selalu menggagalkan SEP

        // ── Poli dokter tujuan → kode BPJS ──────────────────────────────────
        $schedule = DoctorSchedule::find($data['doctor_schedule_id'] ?? null);
        $poliNama = $schedule?->poliklinik ?: ($schedule?->poli_code ?? '—');
        $poliKodeBpjs = $schedule ? BpjsPoliMapping::bpjsCodeFor($schedule->poli_code) : null;
        $poliMapped   = ! empty($poliKodeBpjs);
        if ($schedule && ! $poliMapped) {
            $issues[] = "Poli '{$poliNama}' belum dipetakan ke kode BPJS — atur di Jadwal Dokter → Pemetaan BPJS.";
        }

        // ── Kepesertaan (Cek Peserta) ───────────────────────────────────────
        $pesertaOut = null;
        $identifier = trim((string) ($data['bpjs_number'] ?? '')) ?: trim((string) ($data['nik'] ?? ''));
        $idType     = ! empty(trim((string) ($data['bpjs_number'] ?? ''))) ? 'nokartu' : 'nik';
        if ($identifier === '') {
            $issues[] = 'No. Kartu BPJS / NIK belum diisi — tidak bisa cek kepesertaan.';
        } else {
            try {
                $res = $this->vclaim->checkPeserta($identifier, $idType);
                $ps  = $res['response']['peserta'] ?? null;
                if ($ps) {
                    $statusKet = (string) ($ps['statusPeserta']['keterangan'] ?? '');
                    $aktif     = ((string) ($ps['statusPeserta']['kode'] ?? '')) === '0'
                                 || stripos($statusKet, 'aktif') !== false;
                    $pesertaOut = [
                        'nama'     => $ps['nama'] ?? null,
                        'noKartu'  => $ps['noKartu'] ?? null,
                        'status'   => $statusKet ?: ($aktif ? 'AKTIF' : '—'),
                        'hakKelas' => $ps['hakKelas']['keterangan'] ?? null,
                        'aktif'    => $aktif,
                    ];
                    if (! $aktif) {
                        $issues[] = 'Kepesertaan BPJS TIDAK AKTIF' . ($statusKet ? " ({$statusKet})" : '') . ' — SEP akan ditolak.';
                    }
                } else {
                    $issues[] = (string) ($res['metaData']['message'] ?? 'Peserta BPJS tidak ditemukan.');
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Gagal cek peserta ke BPJS (' . $e->getMessage() . ') — coba lagi sebelum daftar.';
            }
        }

        // ── Rujukan + kecocokan poli + diagnosa ─────────────────────────────
        $sepType    = $data['sep_type'] ?? 'rujukan';
        $rujukanOut = null;
        $diagOut    = ['ada' => false, 'kode' => '', 'nama' => ''];
        $noRujukan  = trim((string) ($data['no_rujukan'] ?? ''));

        if ($sepType === 'rujukan') {
            if ($noRujukan === '') {
                $issues[] = 'Nomor rujukan belum diisi.';
            } else {
                try {
                    $res = $this->vclaim->checkRujukanFktp($noRujukan);
                    $rj  = $res['response']['rujukan'] ?? null;
                    if (is_array($rj) && array_is_list($rj)) {
                        $rj = $rj[0] ?? null;
                    }
                    if ($rj) {
                        $rPoliKode = trim((string) ($rj['poliRujukan']['kode'] ?? ''));
                        $rPoliNama = trim((string) ($rj['poliRujukan']['nama'] ?? $rj['poliRujukan']['nmPoli'] ?? ''));
                        $diagKode  = trim((string) ($rj['diagnosa']['kode'] ?? $rj['diagnosa']['kdDiag'] ?? ''));
                        $diagNama  = trim((string) ($rj['diagnosa']['nama'] ?? $rj['diagnosa']['nmDiag'] ?? ''));
                        $rujukanOut = [
                            'no'           => $rj['noRujukan'] ?? $noRujukan,
                            'tglKunjungan' => $rj['tglKunjungan'] ?? null,
                            'poliKode'     => $rPoliKode,
                            'poliNama'     => $rPoliNama ?: '—',
                            'diagKode'     => $diagKode,
                            'diagNama'     => $diagNama ?: '—',
                        ];
                        $diagOut = ['ada' => $diagKode !== '', 'kode' => $diagKode, 'nama' => $diagNama];

                        // Kecocokan poli: bandingkan kode BPJS bila keduanya ada.
                        if ($poliMapped && $rPoliKode !== '' && $rPoliKode !== $poliKodeBpjs) {
                            $issues[] = "Poli rujukan ({$rPoliNama}) berbeda dari poli dokter dipilih ({$poliNama}) — BPJS menolak SEP karena poli tidak sesuai. Pilih dokter di poli yang sama.";
                        }
                        if ($diagKode === '') {
                            $warnings[] = 'Rujukan tidak memuat diagnosa — diagnosa awal perlu diisi manual agar SEP tidak ditolak.';
                        }
                    } else {
                        $issues[] = (string) ($res['metaData']['message'] ?? 'Rujukan tidak ditemukan / kadaluarsa.');
                    }
                } catch (\Throwable $e) {
                    $warnings[] = 'Gagal cek rujukan ke BPJS (' . $e->getMessage() . ') — coba lagi sebelum daftar.';
                }
            }
        } elseif ($sepType === 'kontrol') {
            $warnings[] = 'SEP kontrol — diagnosa & poli mengikuti surat kontrol; pastikan poli dokter sesuai surat kontrol.';
        }

        return [
            'ready'    => empty($issues),
            'issues'   => $issues,
            'warnings' => $warnings,
            'peserta'  => $pesertaOut,
            'rujukan'  => $rujukanOut,
            'diagnosa' => $diagOut,
            'poli'     => [
                'nama'         => $poliNama,
                'bpjsKode'     => $poliKodeBpjs,
                'mapped'       => $poliMapped,
                'cocokRujukan' => $rujukanOut && $rujukanOut['poliKode'] !== ''
                    ? ($poliMapped && $rujukanOut['poliKode'] === $poliKodeBpjs)
                    : null,
            ],
        ];
    }

    /**
     * Ambil kode + nama diagnosa dari rujukan FKTP VClaim (rujukan.diagnosa).
     * Best-effort: kembalikan kode kosong bila rujukan tak ada/BPJS gagal —
     * pemanggil menentukan apakah jadi error keras (Detail Kunjungan) atau diam
     * (auto-resolve saat terbit SEP). Tak melempar.
     */
    private function resolveDiagnosaFromRujukan(string $noRujukan, ?string $visitId = null): array
    {
        $empty = ['kode' => '', 'nama' => ''];
        if (trim($noRujukan) === '') {
            return $empty;
        }
        try {
            $res = $this->vclaim->checkRujukanFktp($noRujukan, $visitId);
            $rj  = $res['response']['rujukan'] ?? null;
            // Sebagian respons membungkus rujukan dalam array — ambil elemen pertama.
            if (is_array($rj) && array_is_list($rj)) {
                $rj = $rj[0] ?? null;
            }
            $diag = $rj['diagnosa'] ?? [];

            return [
                'kode' => trim((string) ($diag['kode'] ?? $diag['kdDiag'] ?? '')),
                'nama' => trim((string) ($diag['nama'] ?? $diag['nmDiag'] ?? '')),
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * Tarik diagnosa awal pasien dari BPJS lalu simpan ke visit. Dipakai tombol
     * "Tarik dari BPJS" di Detail Kunjungan. Resolusi berlapis supaya pasien yang
     * sudah berhasil daftar (pasti punya rujukan/surat kontrol di BPJS) tetap bisa
     * menarik diagnosa walau No. Rujukan tak ikut tersimpan di kunjungan:
     *   1. Pakai No. Rujukan yang tersimpan di visit (jalur normal rujukan FKTP).
     *   2. Bila kosong — tarik daftar rujukan aktif pasien dari BPJS via No. Kartu
     *      (FKTP + FKRTL), ambil yang memuat diagnosa, lalu persist nomornya ke visit.
     * Melempar 422 hanya bila benar-benar tak ada sumber diagnosa (petugas isi manual).
     */
    public function tarikDiagnosaVisit(string $visitId): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        $visit = Visit::with('patient')->findOrFail($visitId);

        // 1) Sumber utama: No. Rujukan tersimpan di kunjungan.
        $noRujukan = trim((string) ($visit->no_rujukan ?? ''));
        if ($noRujukan !== '') {
            $diag = $this->resolveDiagnosaFromRujukan($noRujukan, $visit->id);
            if ($diag['kode'] !== '') {
                return $this->persistDiagnosaAwal($visit, $diag['kode'], $diag['nama'], $noRujukan);
            }
        }

        // 2) Tak ada (atau rujukan tersimpan tanpa diagnosa): tarik rujukan aktif
        //    pasien dari BPJS via No. Kartu — pasien BPJS yang sudah daftar pasti
        //    punya rujukan/kontrol di sistem BPJS meski nomornya tak ikut tersimpan.
        $noKartu = trim((string) ($visit->patient?->bpjs_number ?? ''));
        if ($noKartu !== '') {
            $found = $this->firstRujukanWithDiagnosaByKartu($noKartu);
            if ($found) {
                // Persist nomor rujukan yang ditemukan bila visit belum punya.
                if (trim((string) ($visit->no_rujukan ?? '')) === '' && $found['no_rujukan'] !== '') {
                    $visit->no_rujukan = $found['no_rujukan'];
                }
                return $this->persistDiagnosaAwal($visit, $found['kode'], $found['nama'], $found['no_rujukan']);
            }
        }

        throw new \Exception('BPJS tak memuat diagnosa untuk pasien ini (rujukan tak ditemukan / tanpa diagnosa, atau BPJS sedang tak bisa diakses). Isi diagnosa manual.', 422);
    }

    /** Simpan diagnosa awal hasil resolusi ke visit + kembalikan ringkasannya. */
    private function persistDiagnosaAwal(Visit $visit, string $kode, string $nama, string $noRujukan): array
    {
        $visit->forceFill([
            'diagnosa_awal'      => $kode,
            'diagnosa_awal_nama' => $nama ?: $visit->diagnosa_awal_nama,
        ])->save();

        return [
            'kode'       => $visit->diagnosa_awal,
            'nama'       => $visit->diagnosa_awal_nama,
            'no_rujukan' => $noRujukan ?: ($visit->no_rujukan ?? ''),
        ];
    }

    /**
     * Cari rujukan pertama (FKTP lalu FKRTL) milik pasien yang memuat diagnosa,
     * berdasarkan No. Kartu BPJS. Best-effort — kembalikan null bila BPJS tak bisa
     * diakses / pasien tak punya rujukan aktif.
     *
     * @return array{ no_rujukan: string, kode: string, nama: string }|null
     */
    private function firstRujukanWithDiagnosaByKartu(string $noKartu): ?array
    {
        foreach (['fktp', 'rs'] as $sumber) {
            try {
                $res = $sumber === 'fktp'
                    ? $this->vclaim->listRujukanFktpByKartu($noKartu)
                    : $this->vclaim->listRujukanByKartu($noKartu);
            } catch (\Throwable $e) {
                continue;
            }

            $rj = $res['response']['rujukan'] ?? null;
            if ($rj === null) {
                continue;
            }
            // Respons bisa satu objek atau list — normalkan ke array.
            $items = (is_array($rj) && array_is_list($rj)) ? $rj : [$rj];

            foreach ($items as $r) {
                $diag = $r['diagnosa'] ?? [];
                $kode = trim((string) ($diag['kode'] ?? $diag['kdDiag'] ?? ''));
                if ($kode === '') {
                    continue;
                }
                return [
                    'no_rujukan' => trim((string) ($r['noKunjungan'] ?? $r['noRujukan'] ?? '')),
                    'kode'       => $kode,
                    'nama'       => trim((string) ($diag['nama'] ?? $diag['nmDiag'] ?? '')),
                ];
            }
        }

        return null;
    }

    /**
     * Simpan diagnosa awal manual ke visit (override/isi tanpa rujukan). Dipakai
     * input ICD-10 di Detail Kunjungan supaya SEP bisa terbit walau rujukan tak
     * memuat diagnosa. Kode kosong = bersihkan.
     */
    public function setDiagnosaAwal(string $visitId, array $data): array
    {
        $visit = Visit::findOrFail($visitId);
        $kode  = strtoupper(trim((string) ($data['diag_awal'] ?? '')));
        $nama  = trim((string) ($data['diag_nama'] ?? ''));

        $visit->forceFill([
            'diagnosa_awal'      => $kode !== '' ? $kode : null,
            'diagnosa_awal_nama' => $kode !== '' ? ($nama ?: null) : null,
        ])->save();

        return [
            'kode' => $visit->diagnosa_awal,
            'nama' => $visit->diagnosa_awal_nama,
        ];
    }

    /**
     * Resolve No. Kartu BPJS dari payload: pakai bpjs_number bila ada; kalau hanya
     * ada NIK, tarik dulu via Cek Peserta (NIK → noKartu). Untuk fitur "cukup NIK".
     */
    private function resolveNoKartuBpjs(array $data): string
    {
        $noKartu = trim((string) ($data['bpjs_number'] ?? ''));
        if ($noKartu !== '') {
            return $noKartu;
        }

        $nik = trim((string) ($data['nik'] ?? ''));
        if ($nik === '') {
            throw new \Exception('Isi No. Kartu BPJS atau NIK pasien dulu.', 422);
        }

        $res     = $this->vclaim->checkPeserta($nik, 'nik');
        $noKartu = $res['response']['peserta']['noKartu'] ?? null;
        if (! $noKartu) {
            throw new \Exception($res['metaData']['message'] ?? 'Peserta BPJS tidak ditemukan dari NIK.', 422);
        }

        return (string) $noKartu;
    }

    /**
     * Simpan No. Kartu BPJS hasil resolve (NIK→noKartu) ke record pasien bila kolomnya
     * masih kosong — supaya operasi VClaim berikutnya tak perlu resolve ulang. Best-effort:
     * lewati bila nomor sudah dipakai pasien lain (data ganda) agar tak melanggar UNIQUE
     * constraint patients_bpjs_number_unique (lihat normalizeBpjsNumber).
     */
    private function backfillPatientBpjsNumber(?Patient $patient, ?string $noKartu, ?string $pesertaNama = null): void
    {
        if (! $patient) {
            return;
        }
        $normalized = $this->normalizeBpjsNumber($noKartu);
        if (! $normalized || ! empty($patient->bpjs_number)) {
            return;
        }
        // Identitas: jangan tulis kartu bila nama peserta BPJS tak cocok dgn nama pasien
        // (NIK salah-ketik bisa resolve ke kartu orang LAIN). Bila ragu → JANGAN simpan;
        // SEP transaksi ini tetap pakai kartu, hanya record pasien yang tak ikut di-heal.
        if (! $this->bpjsNameMatches($patient->name, $pesertaNama)) {
            return;
        }
        $taken = Patient::where('bpjs_number', $normalized)
            ->where('id', '!=', $patient->id)
            ->exists();
        if ($taken) {
            return;
        }
        try {
            // Savepoint (nested transaction): bila race UNIQUE (23505) terjadi antara cek &
            // update, rollback HANYA bagian ini — transaksi SEP induk tetap sehat (tanpa
            // savepoint, error Postgres membatalkan SELURUH transaksi → SEP gagal).
            DB::transaction(fn () => $patient->update(['bpjs_number' => $normalized]));
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }
        }
    }

    /**
     * Cocokkan nama pasien vs nama peserta BPJS (ternormalisasi: uppercase + rapikan
     * spasi). Konservatif — exact match; bila salah satu kosong → dianggap TIDAK cocok
     * (lebih aman tak meng-heal daripada menulis identitas yang salah).
     */
    private function bpjsNameMatches(?string $patientName, ?string $pesertaName): bool
    {
        $norm = fn ($s) => preg_replace('/\s+/', ' ', trim(mb_strtoupper((string) ($s ?? ''))));
        $a = $norm($patientName);
        $b = $norm($pesertaName);

        return $a !== '' && $b !== '' && $a === $b;
    }

    /**
     * Tarik daftar rujukan aktif pasien dari BPJS berdasarkan No. Kartu (FKTP + FKRTL),
     * sehingga pasien kontrol tak perlu membawa nomor rujukan fisik. Cukup NIK/No. Kartu.
     *
     * @return array{ no_kartu: string, fktp: array, rs: array }
     */
    public function bpjsListRujukanByKartu(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        $noKartu = $this->resolveNoKartuBpjs($data);

        $fktp = $this->vclaim->listRujukanFktpByKartu($noKartu);
        $rs   = $this->vclaim->listRujukanByKartu($noKartu);

        return [
            'no_kartu' => $noKartu,
            'fktp'     => $fktp,
            'rs'       => $rs,
        ];
    }

    /**
     * Tarik daftar Surat/Rencana Kontrol pasien dari BPJS berdasarkan No. Kartu.
     * Default bulan/tahun = sekarang, filter=2 (by tanggal rencana kontrol) agar
     * memunculkan kontrol yang dijadwalkan pada periode berjalan.
     *
     * @return array{ no_kartu: string, bulan: string, tahun: string, result: array }
     */
    public function bpjsListSuratKontrolByKartu(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        $noKartu = $this->resolveNoKartuBpjs($data);
        $filter  = (string) ($data['filter'] ?? '2'); // 1=tgl entri, 2=tgl rencana kontrol

        // Bila petugas tentukan bulan/tahun → query persis itu. Default: bulan berjalan
        // + bulan depan, sebab kontrol pasca-bedah ("kontrol kembali tgl X") sering jatuh
        // di bulan berikutnya — kalau cuma bulan ini, surat kontrolnya tak akan muncul.
        if (! empty($data['bulan'])) {
            $periods = [[
                str_pad((string) $data['bulan'], 2, '0', STR_PAD_LEFT),
                (string) ($data['tahun'] ?? now('Asia/Jakarta')->format('Y')),
            ]];
        } else {
            $now  = now('Asia/Jakarta');
            $next = $now->copy()->addMonth();
            $periods = [
                [$now->format('m'), $now->format('Y')],
                [$next->format('m'), $next->format('Y')],
            ];
        }

        $merged  = [];
        $seen    = [];
        $lastRaw = null;
        foreach ($periods as [$bulan, $tahun]) {
            $res     = $this->vclaim->listRencanaKontrolByKartu($bulan, $tahun, $noKartu, $filter);
            $lastRaw = $res;
            $list    = $res['response']['list'] ?? (is_array($res['response'] ?? null) ? $res['response'] : []);
            foreach (($list ?: []) as $item) {
                $key = $item['noSuratKontrol'] ?? json_encode($item);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[]   = $item;
            }
        }

        return [
            'no_kartu' => $noKartu,
            'periods'  => $periods,
            // Bentuk ulang jadi 1 envelope (response.list gabungan) agar FE memproses seragam.
            'result'   => [
                'metaData'   => $lastRaw['metaData'] ?? ['code' => '200', 'message' => 'OK'],
                'response'   => ['list' => $merged],
                'is_success' => $lastRaw['is_success'] ?? true,
            ],
        ];
    }

    /**
     * Surat Kontrol BPJS milik kunjungan ini (untuk prefill form edit di Admisi).
     * Mengembalikan letter terbaru: { id, no_surat_kontrol, tanggal_rencana_kontrol,
     * status, poli_kontrol(dari mapping), kode_dokter }.
     */
    public function bpjsGetSuratKontrol(string $visitId): ?array
    {
        $letter = BpjsControlLetter::where('visit_id', $visitId)
            ->orderByDesc('created_at')
            ->first();

        if (! $letter) {
            return null;
        }

        $visit    = Visit::with('doctorSchedule.employee')->find($visitId);
        $schedule = $visit?->doctorSchedule;

        return [
            'id'                      => $letter->id,
            'no_surat_kontrol'        => $letter->no_surat_kontrol,
            'tanggal_rencana_kontrol' => $letter->tanggal_rencana_kontrol?->format('Y-m-d'),
            'status'                  => $letter->status,
            'poli_kontrol'            => BpjsPoliMapping::bpjsCodeFor($schedule?->poli_code),
            'kode_dokter'             => $schedule?->employee?->bpjs_dpjp_code,
            'no_sep'                  => $visit?->no_sep,
        ];
    }

    /**
     * Edit Surat Kontrol yang SUDAH terbit di BPJS (PUT /RencanaKontrol/v2/Update).
     * Hanya berlaku untuk letter status SUCCESS (punya no_surat_kontrol). Yang masih
     * DRAFT belum ada di BPJS → terbitkan dulu (bukan edit).
     */
    public function bpjsEditSuratKontrol(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        $letter = BpjsControlLetter::with('visit.doctorSchedule.employee')->findOrFail($data['id']);

        if ($letter->status !== 'SUCCESS' || ! $letter->no_surat_kontrol) {
            throw new \Exception('Hanya Surat Kontrol yang sudah terbit yang bisa diedit. Yang masih draft → terbitkan dulu.', 422);
        }

        $visit    = $letter->visit;
        $schedule = $visit?->doctorSchedule;
        $kodePoli = BpjsPoliMapping::bpjsCodeFor($schedule?->poli_code);

        $result = $this->vclaim->updateRencanaKontrol([
            'noSuratKontrol'    => $letter->no_surat_kontrol,
            'noSEP'             => $visit?->no_sep,
            'kodeDokter'        => $schedule?->employee?->bpjs_dpjp_code,
            'poliKontrol'       => $kodePoli,
            'tglRencanaKontrol' => $data['tgl_rencana_kontrol'],
            'user'              => auth('api')->user()?->name ?? 'arumed',
        ], $visit?->id);

        $code = (string) ($result['metaData']['code'] ?? '');
        if ($code !== '200') {
            throw new \Exception(
                'Gagal update Surat Kontrol: ' . ($result['metaData']['message'] ?? 'respons tidak dikenal'),
                422
            );
        }

        // Sukses → sinkronkan tanggal di letter lokal.
        $letter->update([
            'tanggal_rencana_kontrol' => $data['tgl_rencana_kontrol'],
            'vclaim_response'         => $result,
        ]);

        return $result;
    }

    /**
     * Edit/Update SEP yang sudah terbit (PUT /SEP/2.0/update). Satu operasi VClaim
     * (tidak ada "edit" terpisah dari "update"). Field editable ringkas (klinik mata
     * RJ): kelas rawat, diagnosa awal, catatan, no.telp, flag katarak. Poli & DPJP
     * tetap dari pemetaan Jadwal Dokter agar konsisten (tidak diubah manual).
     */
    public function bpjsUpdateSep(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');

        $visit = Visit::with(['patient', 'doctorSchedule.employee'])->findOrFail($data['visit_id']);

        if (! $visit->no_sep) {
            throw new \Exception('Kunjungan ini belum punya SEP untuk diubah.', 422);
        }

        $schedule = $visit->doctorSchedule;
        $kodePoli = BpjsPoliMapping::bpjsCodeFor($schedule?->poli_code);
        $kodeDpjp = $schedule?->employee?->bpjs_dpjp_code;

        $tSep = [
            'noSep'     => $visit->no_sep,
            'klsRawat'  => [
                'klsRawatHak'     => (string) ($data['kls_rawat'] ?? '3'),
                'klsRawatNaik'    => '',
                'pembiayaan'      => '',
                'penanggungJawab' => '',
            ],
            'noMR'      => $visit->patient?->no_rm ?? '',
            'catatan'   => $data['catatan'] ?? '',
            'diagAwal'  => $data['diag_awal'] ?? '',
            'poli'      => ['tujuan' => $kodePoli, 'eksekutif' => '0'],
            'cob'       => ['cob' => '0'],
            'katarak'   => ['katarak' => (string) ($data['katarak'] ?? '0')],
            'jaminan'   => ['lakaLantas' => '0', 'penjamin' => ['tglKejadian' => '', 'keterangan' => '', 'suplesi' => ['suplesi' => '0', 'noSepSuplesi' => '', 'lokasiLaka' => ['kdPropinsi' => '', 'kdKabupaten' => '', 'kdKecamatan' => '']]]],
            'dpjpLayan' => $kodeDpjp ?? '',
            'noTelp'    => $data['no_telp'] ?? ($visit->patient?->phone ?? ''),
            'user'      => auth('api')->user()?->name ?? 'arumed',
        ];

        $result = $this->vclaim->updateSep($tSep, $visit->id);

        $code = (string) ($result['metaData']['code'] ?? '');
        if ($code !== '200') {
            throw new \Exception(
                'Gagal update SEP: ' . ($result['metaData']['message'] ?? 'respons tidak dikenal'),
                422
            );
        }

        // Sinkronkan snapshot cetak agar reprint memakai nilai terbaru (kelas/
        // diagnosa/catatan yang baru diubah), bukan data saat penerbitan.
        $visit->update(['sep_data' => array_merge((array) ($visit->sep_data ?? []), [
            'klsRawatHak' => $tSep['klsRawat']['klsRawatHak'],
            'diagAwal'    => $tSep['diagAwal'],
            'catatan'     => $tSep['catatan'] !== '' ? $tSep['catatan'] : ($visit->sep_data['catatan'] ?? ''),
        ])]);

        return $result;
    }

    public function bpjsValidasiBooking(array $data): array
    {
        $this->assertBpjsEnabled('ANTREAN');

        return $this->antrean->validateBookingCode($data['booking_code'], $data['tgl_periksa'] ?? '');
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Generate no_rm based on ClinicProfile sequence (thread-safe).
     * Format: {YYYYMM}{SEQ padded to rm_seq_length}
     *
     * Resilient: kalau counter clinic.rm_last_seq drift (mis. seeder insert pasien
     * langsung tanpa update sequence), retry-loop skip nomor yg sudah dipakai.
     */
    /**
     * Nomor registrasi resmi per kunjungan: REG-YYYYMMDD-NNN (sequence harian).
     * Berbasis nomor terakhir hari ini (termasuk yang trashed) agar tidak
     * mengulang nomor kunjungan yang sudah dibatalkan. Dipanggil di dalam
     * transaksi registerVisit/daftarkanWalkIn.
     */
    private function generateNoRegistrasi(): string
    {
        $prefix = 'REG-' . today()->format('Ymd') . '-';

        $last = Visit::withTrashed()
            ->where('no_registrasi', 'like', $prefix . '%')
            ->orderByDesc('no_registrasi')
            ->value('no_registrasi');

        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function generateNoRM(): string
    {
        $noRm = '';

        DB::transaction(function () use (&$noRm) {
            $clinic = ClinicProfile::lockForUpdate()->firstOrFail();
            $pad    = $clinic->rm_seq_length ?? 4;
            $prefix = now()->format('Ym');
            $seq    = $clinic->rm_last_seq;

            for ($i = 0; $i < 100; $i++) {
                $seq++;
                $candidate = $prefix . str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);

                if (! Patient::withTrashed()->where('no_rm', $candidate)->exists()) {
                    $noRm = $candidate;
                    $clinic->update(['rm_last_seq' => $seq]);
                    return;
                }
            }

            throw new \RuntimeException('Gagal generate no_rm unik setelah 100 percobaan.');
        });

        return $noRm;
    }

    /**
     * Generate queue number — delegate ke QueueService supaya prefix map konsisten
     * (mis. TRIASE/REFRAKSIONIS share prefix "TR" via Queue::SHARED_PREFIX_GROUPS).
     */
    public function generateQueueNumber(string $station): array
    {
        return $this->queueService->generateQueueNumber($station);
    }

    private function assertBpjsEnabled(string $systemName): void
    {
        $config = IntegrationConfig::where('system_name', $systemName)->first();

        if (! $config || ! $config->is_enabled) {
            throw new \Exception("Integrasi {$systemName} belum diaktifkan. Konfigurasi credentials terlebih dahulu.", 503);
        }
    }

    /**
     * Simpan penjamin kedua (COB) untuk visit. No-op kalau $cob null/kosong.
     * Penjamin-1 boleh BPJS (menanggung INA-CBG) atau Asuransi/Perusahaan; penjamin-2
     * Asuransi/Perusahaan (menanggung selisih). Idempoten via updateOrCreate (visit_id unique).
     */
    private function saveCob(string $visitId, ?array $cob): void
    {
        if (empty($cob) || empty($cob['penjamin2_insurer_id'])) {
            return;
        }

        // Penjamin-1 BPJS biasanya tak menyertakan insurer_id eksplisit → pakai insurer
        // sistem BPJS agar resolusi tarif/klaim downstream konsisten (pola systemInsurerId).
        $penjamin1InsurerId = $cob['penjamin1_insurer_id'] ?? null;
        if (! $penjamin1InsurerId && ($cob['penjamin1_type'] ?? null) === 'BPJS') {
            $penjamin1InsurerId = \App\Models\Insurer::where('is_system', true)
                ->where('type', 'BPJS')
                ->value('id');
        }

        VisitCob::updateOrCreate(
            ['visit_id' => $visitId],
            [
                'penjamin1_type'       => $cob['penjamin1_type'],
                'penjamin1_insurer_id' => $penjamin1InsurerId,
                'penjamin2_type'       => $cob['penjamin2_type'] ?? 'ASURANSI',
                'penjamin2_insurer_id' => $cob['penjamin2_insurer_id'],
                'is_active'            => true,
                'notes'                => $cob['notes'] ?? null,
            ]
        );
    }

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
