<?php

namespace App\Services;

use App\Models\BpjsControlLetter;
use App\Models\BpjsPoliMapping;
use App\Models\BpjsReferralIn;
use App\Models\BpjsReferralOut;
use App\Models\BpjsRmLog;
use App\Models\BpjsVClaimLog;
use App\Models\Visit;
use App\Models\DoctorSchedule;
use App\Models\Icd10Code;
use App\Models\Icd9Code;
use App\Models\Employee;
use App\Models\InacbgsGroupingLog;
use App\Models\IntegrationConfig;
use App\Models\SatusehatResourceLog;
use App\Models\SatusehatSyncLog;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class IntegrasiService
{
    public function __construct(
        private readonly Request             $request,
        private readonly BpjsVClaimService  $vclaim,
        private readonly BpjsAntreanService $antrean,
        private readonly SatusehatService   $satusehat,
        private readonly InaCbgsService     $inacbgs,
    ) {}

    // =========================================================================
    // STATUS ALL SYSTEMS
    // =========================================================================

    public function getStatusSemua(): array
    {
        $configs = IntegrationConfig::orderBy('system_name')->get();

        return $configs->map(fn ($c) => [
            'id'               => $c->id,
            'system_name'      => $c->system_name,
            'is_enabled'       => $c->is_enabled,
            'last_test_status' => $c->last_test_status,
            'last_tested_at'   => $c->last_tested_at?->toIso8601String(),
            'has_credentials'  => ! empty($c->credentials),
            'base_url'         => $c->base_url,
        ])->toArray();
    }

    // =========================================================================
    // TEST CONNECTION
    // =========================================================================

    public function testKoneksi(string $system): array
    {
        $config = IntegrationConfig::where('system_name', strtoupper($system))->firstOrFail();

        try {
            $result = match (strtoupper($system)) {
                'VCLAIM'     => $this->vclaim->testConnection(),
                'ANTREAN'    => $this->antrean->testConnection(),
                'SATUSEHAT'  => $this->satusehat->testConnection(),
                'INACBGS'    => $this->inacbgs->testConnection(),
                'ICARE'      => ['success' => false, 'message' => 'iCare test — placeholder.', 'system' => 'ICARE'],
                'LUPIS'      => ['success' => false, 'message' => 'LUPIS test — placeholder.', 'system' => 'LUPIS'],
                default      => throw new \Exception("Sistem tidak dikenal: {$system}", 422),
            };

            $status = $result['success'] ? 'SUCCESS' : 'FAILED';
        } catch (\Exception $e) {
            $status = 'FAILED';
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        $config->update([
            'last_test_status' => $status,
            'last_tested_at'   => now(),
        ]);

        $this->log(auth('api')->id(), "TEST_KONEKSI_{$system}", null, null, $result['message'] ?? '');

        return array_merge($result, ['status' => $status, 'tested_at' => now()->toIso8601String()]);
    }

    // =========================================================================
    // CONFIG MANAGEMENT
    // =========================================================================

    public function indexConfig(): \Illuminate\Database\Eloquent\Collection
    {
        // 'configuration' WAJIB dikembalikan: form Integrasi membaca service_name &
        // kode_faskes dari sini. Tanpa ini field tampil kosong (placeholder
        // "vclaim-rest-dev") → tampak seperti reset, & setiap Simpan menimpa nilai
        // asli jadi null. 'credentials' SENGAJA tidak diselect (rahasia, write-only).
        return IntegrationConfig::orderBy('system_name')->get([
            'id', 'system_name', 'is_enabled', 'base_url', 'configuration',
            'last_test_status', 'last_tested_at', 'notes',
        ]);
    }

    public function updateConfig(string $id, array $data): IntegrationConfig
    {
        $config = IntegrationConfig::findOrFail($id);

        // MERGE configuration (bukan replace): kunci yang tak dikirim / dikirim
        // kosong TIDAK menimpa nilai tersimpan. Insiden: Simpan form Konfigurasi
        // dgn field Location kosong menghapus configuration.location_id (di-set
        // via tab Satu Sehat → Location) → SEMUA Bundle ditolak 400 RuleNumber
        // 10120 "Element not found: Encounter.location". Konsekuensi: kunci tak
        // bisa dihapus via endpoint ini — kelola location di tab Location.
        if (! is_null($data['configuration'] ?? null)) {
            $incoming = array_filter($data['configuration'], fn ($v) => $v !== '' && $v !== null);
            $data['configuration'] = array_merge($config->configuration ?? [], $incoming);
        }

        $config->update(array_filter([
            'is_enabled'    => $data['is_enabled'] ?? null,
            'base_url'      => $data['base_url'] ?? null,
            'credentials'   => $data['credentials'] ?? null,
            'configuration' => $data['configuration'] ?? null,
            'notes'         => $data['notes'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_INTEGRASI_CONFIG', IntegrationConfig::class, $id, $config->system_name);

        return $config->fresh();
    }

    // =========================================================================
    // VCLAIM LOGS
    // =========================================================================

    public function getVclaimLog(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsVClaimLog::with('visit.patient')
            ->orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['is_success'])) {
            $query->where('is_success', (bool) $filters['is_success']);
        }

        if (! empty($filters['tanggal'])) {
            $query->whereDate('created_at', $filters['tanggal']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showVclaimLog(string $id): BpjsVClaimLog
    {
        return BpjsVClaimLog::with('visit.patient')->findOrFail($id);
    }

    // =========================================================================
    // ANTREAN LOGS
    // =========================================================================

    public function getAntreanLog(array $filters = []): LengthAwarePaginator
    {
        $query = DB::table('bpjs_antrean_logs')
            ->orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    // =========================================================================
    // ICARE LOGS
    // =========================================================================

    public function getIcareLog(array $filters = []): LengthAwarePaginator
    {
        $query = DB::table('bpjs_icare_logs')->orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    // =========================================================================
    // WS REKAM MEDIS LOGS + DASHBOARD
    // =========================================================================

    public function getRekamMedisLog(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsRmLog::with('visit.patient')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['tanggal'])) {
            $query->whereDate('created_at', $filters['tanggal']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Statistik dashboard WS Rekam Medis BPJS:
     *  - pengiriman sukses/gagal (total + hari ini)
     *  - kunjungan BPJS terkirim vs belum (punya SEP, status RM null/FAILED).
     */
    public function rekamMedisDashboard(): array
    {
        $today = now()->toDateString();

        return [
            'sent_total'    => BpjsRmLog::where('status', 'SUCCESS')->count(),
            'failed_total'  => BpjsRmLog::where('status', 'FAILED')->count(),
            'today_sent'    => BpjsRmLog::where('status', 'SUCCESS')->whereDate('created_at', $today)->count(),
            'today_failed'  => BpjsRmLog::where('status', 'FAILED')->whereDate('created_at', $today)->count(),
            'visits_sent'   => Visit::where('bpjs_rm_status', 'SENT')->count(),
            'visits_unsent' => Visit::whereNotNull('no_sep')
                ->where(fn ($q) => $q->whereNull('bpjs_rm_status')->orWhere('bpjs_rm_status', 'FAILED'))
                ->count(),
        ];
    }

    // =========================================================================
    // INA-CBGs GROUPING LOGS
    // =========================================================================

    public function getInacbgsLog(array $filters = []): LengthAwarePaginator
    {
        $query = InacbgsGroupingLog::with(['visit.patient', 'bpjsClaim'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    // =========================================================================
    // RUJUKAN BPJS
    // =========================================================================

    public function indexRujukanMasuk(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsReferralIn::with('visit.patient')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showRujukanMasuk(string $id): BpjsReferralIn
    {
        return BpjsReferralIn::with('visit.patient')->findOrFail($id);
    }

    public function indexRujukanKeluar(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsReferralOut::with('visit.patient')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showRujukanKeluar(string $id): BpjsReferralOut
    {
        return BpjsReferralOut::with('visit.patient')->findOrFail($id);
    }

    // =========================================================================
    // SURAT KONTROL BPJS
    // =========================================================================

    public function indexSuratKontrol(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsControlLetter::with('visit.patient')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showSuratKontrol(string $id): BpjsControlLetter
    {
        return BpjsControlLetter::with('visit.patient')->findOrFail($id);
    }

    /**
     * Submit Surat Kontrol ke VClaim → dapat nomor resmi.
     */
    public function submitSuratKontrol(string $id): BpjsControlLetter
    {
        $letter = BpjsControlLetter::with('visit.patient')->findOrFail($id);

        if ($letter->status !== 'DRAFT') {
            throw new \Exception('Surat Kontrol hanya bisa disubmit jika masih DRAFT.', 422);
        }

        // Mapping dokter & poli kunjungan asal → kode BPJS.
        $schedule = $letter->visit?->doctorSchedule;
        $kodeDokter = $schedule?->employee?->bpjs_dpjp_code;
        $kodePoli   = BpjsPoliMapping::bpjsCodeFor($schedule?->poli_code);

        // Guard sama dgn DokterService::submitSuratKontrol — cegah kirim poli null ke BPJS
        // (→ error mentah membingungkan). Beri 422 jelas agar petugas memetakan poli dulu.
        if (! $kodePoli) {
            throw new \Exception("Poli '{$schedule?->poli_code}' belum dipetakan ke kode BPJS. Atur di Jadwal Dokter → Pemetaan BPJS.", 422);
        }

        $result = $this->vclaim->postSuratKontrol([
            'noSEP'             => $letter->visit->no_sep,
            'kodeDokter'        => $kodeDokter,
            'poliKontrol'       => $kodePoli,
            'tglRencanaKontrol' => $letter->tanggal_rencana_kontrol?->format('Y-m-d'),
            'user'              => auth('api')->user()?->name ?? 'arumed',
        ], $letter->visit_id);

        // response sudah didekripsi BpjsClient → ada di $result['response'].
        $noSuratKontrol = $result['response']['noSuratKontrol'] ?? null;

        $letter->update([
            'status'           => $noSuratKontrol ? 'SUCCESS' : 'FAILED',
            'no_surat_kontrol' => $noSuratKontrol,
            'vclaim_response'  => $result,
        ]);

        $this->log(auth('api')->id(), 'SUBMIT_SURAT_KONTROL', BpjsControlLetter::class, $id);

        return $letter->fresh();
    }

    // =========================================================================
    // SATU SEHAT
    // =========================================================================

    public function getSatusehatSyncLog(array $filters = []): LengthAwarePaginator
    {
        // created_at sekunder: beberapa batch bisa se-tanggal (AUTO+retry+manual)
        // → urutan stabil terbaru-dulu utk paging Riwayat Batch Sync.
        $query = SatusehatSyncLog::orderByDesc('sync_date')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showSatusehatSyncLog(string $id): SatusehatSyncLog
    {
        return SatusehatSyncLog::findOrFail($id);
    }

    public function getSatusehatResourceLog(array $filters = []): LengthAwarePaginator
    {
        $query = SatusehatResourceLog::with('visit.patient')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['resource_type'])) {
            $query->where('resource_type', $filters['resource_type']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function satusehatSyncManual(): SatusehatSyncLog
    {
        return $this->satusehat->batchSync('MANUAL');
    }

    public function satusehatRetry(string $logId): SatusehatSyncLog
    {
        return $this->satusehat->retry($logId);
    }

    /** Backfill: cek jumlah kunjungan historis yang layak (sebelum eksekusi). */
    public function satusehatBackfillPreview(?string $from = null, ?string $to = null): array
    {
        $this->satusehat->boot();
        return $this->satusehat->countBackfillEligible($from, $to);
    }

    /** Backfill: jalankan sync N kunjungan historis eligible (terlama dulu). */
    public function satusehatBackfill(int $limit, ?string $from = null, ?string $to = null): SatusehatSyncLog
    {
        return $this->satusehat->backfillSync($limit, $from, $to);
    }

    public function satusehatDashboard(?string $from = null, ?string $to = null): array
    {
        return $this->satusehat->dashboardStats($from, $to);
    }

    public function satusehatSearchKfa(string $keyword): array
    {
        return $this->satusehat->searchKfa($keyword);
    }

    // ---- Satu Sehat Location ----
    public function satusehatListLocations(): array
    {
        return $this->satusehat->listLocations();
    }

    public function satusehatRegisterLocation(string $name, string $physicalType = 'ro', bool $setActive = true): array
    {
        return $this->satusehat->registerLocation($name, $physicalType, $setActive);
    }

    public function satusehatUpdateLocation(string $id, string $name, string $status, string $physicalType = 'ro'): array
    {
        return $this->satusehat->updateLocation($id, $name, $status, $physicalType);
    }

    public function satusehatDeactivateLocation(string $id, string $name = '', string $physicalType = 'ro'): array
    {
        return $this->satusehat->deactivateLocation($id, $name, $physicalType);
    }

    public function satusehatSetActiveLocation(string $id): void
    {
        $this->satusehat->setActiveLocation($id);
    }

    /** Resolve IHS massal pasien ber-NIK (tombol "Resolve IHS" Kesiapan Data). */
    public function satusehatResolveIhsBatch(int $limit): array
    {
        return $this->satusehat->resolveIhsBatch($limit);
    }

    // =========================================================================
    // VCLAIM — PASSTHROUGH (dipanggil controller/UI; melempar 503 jika belum aktif)
    // =========================================================================

    public function vclaimCekPeserta(string $identifier, string $type, string $tglSep, ?string $visitId = null): array
    {
        return $this->vclaim->checkPeserta($identifier, $type, $tglSep, $visitId);
    }

    public function vclaimCekRujukan(string $noRujukan, string $sumber = 'rs', ?string $visitId = null): array
    {
        return $sumber === 'fktp'
            ? $this->vclaim->checkRujukanFktp($noRujukan, $visitId)
            : $this->vclaim->checkRujukan($noRujukan, $visitId);
    }

    public function vclaimRujukanByKartu(string $noKartu, bool $list = false): array
    {
        return $list ? $this->vclaim->listRujukanByKartu($noKartu) : $this->vclaim->getRujukanByKartu($noKartu);
    }

    public function vclaimGenerateSep(array $tSep, ?string $visitId = null): array
    {
        return $this->vclaim->generateSep($tSep, $visitId);
    }

    public function vclaimUpdateSep(array $tSep, ?string $visitId = null): array
    {
        return $this->vclaim->updateSep($tSep, $visitId);
    }

    public function vclaimCancelSep(string $noSep, string $user, ?string $visitId = null): array
    {
        return $this->vclaim->cancelSep($noSep, $user, $visitId);
    }

    public function vclaimInsertLpk(array $tLpk, ?string $visitId = null): array
    {
        return $this->vclaim->insertLpk($tLpk, $visitId);
    }

    public function vclaimMonitoring(string $jenis, array $p): array
    {
        return match ($jenis) {
            'kunjungan' => $this->vclaim->monitoringKunjungan($p['tgl'], $p['jns'] ?? '2'),
            'klaim'     => $this->vclaim->monitoringKlaim($p['tgl'], $p['jns'] ?? '2', $p['status'] ?? '1'),
            'histori'   => $this->vclaim->historiPelayanan($p['noKartu'], $p['tglMulai'], $p['tglAkhir']),
            default     => throw new \Exception("Jenis monitoring tidak dikenal: {$jenis}", 422),
        };
    }

    /** Referensi VClaim — dispatch by jenis. */
    public function vclaimReferensi(string $jenis, array $p = []): array
    {
        return match ($jenis) {
            'diagnosa'   => $this->vclaim->refDiagnosa($p['q'] ?? ''),
            'poli'       => $this->vclaim->refPoli($p['q'] ?? ''),
            'faskes'     => $this->vclaim->refFaskes($p['q'] ?? '', $p['jns'] ?? '2'),
            'dokter'     => $this->vclaim->refDokter($p['q'] ?? ''),
            'dpjp'       => $this->vclaim->refDpjp($p['jnsPelayanan'] ?? '2', $p['tglPelayanan'] ?? now()->toDateString(), $p['spesialis'] ?? ''),
            'procedure'  => $this->vclaim->refProcedure($p['q'] ?? ''),
            'propinsi'   => $this->vclaim->refPropinsi(),
            'kabupaten'  => $this->vclaim->refKabupaten($p['kode'] ?? ''),
            'kecamatan'  => $this->vclaim->refKecamatan($p['kode'] ?? ''),
            'kelasrawat' => $this->vclaim->refKelasRawat(),
            'spesialistik' => $this->vclaim->refSpesialistik(),
            'ruangrawat' => $this->vclaim->refRuangRawat(),
            'carakeluar' => $this->vclaim->refCaraKeluar(),
            'pascapulang' => $this->vclaim->refPascaPulang(),
            'diagnosaprb' => $this->vclaim->refDiagnosaPrb(),
            'obatprb'    => $this->vclaim->refObatPrb($p['q'] ?? ''),
            default      => throw new \Exception("Referensi tidak dikenal: {$jenis}", 422),
        };
    }

    // =========================================================================
    // SINKRON MASTER ICD DARI REFERENSI VCLAIM (cakupan oftalmologi)
    // =========================================================================

    /**
     * Keyword pencarian default untuk menyaring kode mata (Bab VII ICD-10
     * H00–H59 + prosedur mata ICD-9-CM). Endpoint /referensi/diagnosa/{q} dan
     * /referensi/procedure/{q} adalah SEARCH-by-keyword, jadi kita panggil
     * dengan tiap keyword lalu kumpulkan hasilnya — cakupan tetap oftalmologi.
     *
     * Referensi VClaim = master nasional BPJS yang SAMA dengan yang dipakai
     * grouper INA-CBG (ICD-10 + ICD-9-CM), jadi kode hasil sinkron dijamin
     * diterima saat set_claim_data/grouper.
     */
    public const ICD10_EYE_KEYWORDS = [
        'H00', 'H01', 'H02', 'H03', 'H04', 'H05', 'H06', 'H10', 'H11',
        'H13', 'H15', 'H16', 'H17', 'H18', 'H19', 'H20', 'H21', 'H22',
        'H25', 'H26', 'H27', 'H28', 'H30', 'H31', 'H33', 'H34', 'H35',
        'H36', 'H40', 'H42', 'H43', 'H44', 'H46', 'H47', 'H49', 'H50',
        'H51', 'H52', 'H53', 'H54', 'H55', 'H57', 'H59',
    ];

    public const ICD9_EYE_KEYWORDS = [
        '08', '09', '10', '11', '12', '13', '14', '15', '16', '95.0', '95.1', '95.2',
    ];

    /**
     * Sinkron kode ICD-10 (diagnosa) atau ICD-9 (procedure) dari VClaim ke master.
     *
     * @param string $type  'icd10' | 'icd9'
     * @param array|null $keywords  override daftar keyword (default = blok mata)
     * @return array {type, inserted, updated, total, per_keyword, failed_keywords}
     */
    public function syncIcdFromVclaim(string $type, ?array $keywords = null): array
    {
        if (! in_array($type, ['icd10', 'icd9'], true)) {
            throw new \Exception("Tipe sinkron tidak dikenal: {$type}. Pakai 'icd10' atau 'icd9'.", 422);
        }

        // Lempar 503 lebih awal bila VClaim mati (di dev sandbox off) — pesan jelas.
        if (! $this->vclaim->isEnabled()) {
            throw new \Exception('VClaim belum aktif/terkonfigurasi. Sinkron ICD hanya bisa di lingkungan dgn VClaim aktif (produksi).', 503);
        }

        $keywords = $keywords ?: ($type === 'icd10' ? self::ICD10_EYE_KEYWORDS : self::ICD9_EYE_KEYWORDS);
        $model    = $type === 'icd10' ? Icd10Code::class : Icd9Code::class;

        $inserted = 0;
        $updated  = 0;
        $perKeyword = [];
        $failed     = [];
        $seen       = []; // dedup antar keyword (kode bisa muncul di >1 query)

        foreach ($keywords as $kw) {
            try {
                $rows = $this->fetchVclaimIcdRows($type, (string) $kw);
            } catch (\Throwable $e) {
                $failed[] = ['keyword' => $kw, 'error' => $e->getMessage()];
                continue;
            }

            $count = 0;
            foreach ($rows as $row) {
                $code = trim((string) ($row['kode'] ?? $row['kodeDiagnosa'] ?? ''));
                $name = trim((string) ($row['nama'] ?? $row['namaDiagnosa'] ?? ''));
                if ($code === '') {
                    continue;
                }
                if (isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $count++;

                $res = $this->upsertIcdCode($model, $code, $name);
                $res === 'inserted' ? $inserted++ : $updated++;
            }
            $perKeyword[$kw] = $count;
        }

        $this->log(
            auth('api')->id(),
            'SYNC_ICD_VCLAIM',
            $model,
            null,
            "type:{$type} inserted:{$inserted} updated:{$updated} keywords:" . count($keywords)
        );

        return [
            'type'            => $type,
            'inserted'        => $inserted,
            'updated'         => $updated,
            'total'           => $inserted + $updated,
            'per_keyword'     => $perKeyword,
            'failed_keywords' => $failed,
        ];
    }

    /**
     * Ambil baris referensi ICD dari VClaim + ratakan envelope.
     * Envelope VClaim: response bisa array langsung ATAU {diagnosa|procedure|list:[...]}.
     */
    private function fetchVclaimIcdRows(string $type, string $keyword): array
    {
        $res = $type === 'icd10'
            ? $this->vclaim->refDiagnosa($keyword)
            : $this->vclaim->refProcedure($keyword);

        if (! ($res['is_success'] ?? false)) {
            throw new \Exception($res['metaData']['message'] ?? 'Referensi VClaim gagal', 502);
        }

        $d = $res['response'] ?? [];
        if (is_array($d) && array_is_list($d)) {
            return $d;
        }

        return $d['diagnosa'] ?? $d['procedure'] ?? $d['list'] ?? [];
    }

    /**
     * Upsert satu kode ICD AMAN soft-delete (pola sama MasterDataService::upsertIcdRow).
     * Tandai is_eye_related=true (cakupan sinkron memang oftalmologi). Hanya isi
     * description bila kosong di master / dari BPJS — JANGAN timpa deskripsi ID
     * yang mungkin sudah dikurasi manual.
     */
    private function upsertIcdCode(string $model, string $code, string $name): string
    {
        $existing = $model::withTrashed()->where('code', $code)->first();

        if ($existing) {
            $payload = ['is_eye_related' => true];
            if ($name !== '' && empty($existing->description)) {
                $payload['description'] = $name;
            }
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($payload);
            return 'updated';
        }

        $model::create([
            'code'           => $code,
            'description'    => $name !== '' ? $name : $code,
            'is_eye_related' => true,
            'is_favorite'    => false,
        ]);
        return 'inserted';
    }

    // =========================================================================
    // ANTREAN — PASSTHROUGH
    // =========================================================================

    public function antreanAdd(array $data, ?string $visitId = null): array
    {
        return $this->antrean->addAntrean($data, $visitId);
    }

    public function antreanUpdateWaktu(array $data, ?string $visitId = null): array
    {
        return $this->antrean->updateWaktuAntrean($data, $visitId);
    }

    public function antreanBatal(string $kodeBooking, string $keterangan, ?string $visitId = null): array
    {
        return $this->antrean->batalAntrean($kodeBooking, $keterangan, $visitId);
    }

    public function antreanDashboard(string $jenis, array $p): array
    {
        return $jenis === 'bulan'
            ? $this->antrean->dashboardWaktuTungguBulan($p['bulan'], $p['tahun'], $p['waktu'] ?? 'rs')
            : $this->antrean->dashboardWaktuTunggu($p['tanggal'], $p['waktu'] ?? 'rs');
    }

    public function antreanValidateBooking(string $bookingCode, string $tglPeriksa = ''): array
    {
        return $this->antrean->validateBookingCode($bookingCode, $tglPeriksa);
    }

    /** Referensi Pasien Fingerprint — apakah peserta wajib sidik jari. $jenis: nik|noka. */
    public function antreanRefPasienFingerprint(string $jenis, string $noIdentitas): array
    {
        return $this->antrean->refPasienFingerprint($jenis, $noIdentitas);
    }

    /**
     * Daftar poli HFIS-Antrean (ternormalisasi {kode,nama,...}) untuk picker Pemetaan.
     * Sumber resmi yang dipakai antrean/add & jadwaldokter (per kdsubspesialis).
     */
    public function antreanRefPoliHfis(): array
    {
        $list = $this->extractAntreanList($this->antrean->refPoli());

        return collect($list)->map(fn ($p) => [
            'kode'           => (string) ($p['kdsubspesialis'] ?? $p['kdpoli'] ?? ''),
            'nama'           => trim(($p['nmpoli'] ?? '') . (! empty($p['nmsubspesialis']) ? ' — ' . $p['nmsubspesialis'] : '')),
            'kdpoli'         => (string) ($p['kdpoli'] ?? ''),
            'kdsubspesialis' => (string) ($p['kdsubspesialis'] ?? ''),
        ])->filter(fn ($r) => $r['kode'] !== '')->values()->all();
    }

    /**
     * Jadwal dokter terdaftar di HFIS untuk satu kodepoli + tanggal (ternormalisasi).
     * Dipakai admin untuk MEMBANDINGKAN jadwal SIMRS vs HFIS — jam praktek HARUS sama
     * persis agar antrean/add tidak ditolak ("API Versi 2" = jadwal tak cocok HFIS).
     */
    public function antreanJadwalHfis(string $kodepoli, string $tanggal): array
    {
        $list = $this->extractAntreanList($this->antrean->refJadwalDokter($kodepoli, $tanggal));

        return collect($list)->map(fn ($j) => [
            'kodesubspesialis' => (string) ($j['kodesubspesialis'] ?? ''),
            'namasubspesialis' => (string) ($j['namasubspesialis'] ?? ''),
            'kodedokter'       => (string) ($j['kodedokter'] ?? ''),
            'namadokter'       => (string) ($j['namadokter'] ?? ''),
            'hari'             => $j['hari'] ?? null,
            'namahari'         => (string) ($j['namahari'] ?? ''),
            'jam'              => (string) ($j['jadwal'] ?? $j['jampraktek'] ?? ''),
            'kapasitas'        => $j['kapasitaspasien'] ?? $j['kapasitas'] ?? null,
            'libur'            => (int) ($j['libur'] ?? 0),
        ])->values()->all();
    }

    /** Daftar dokter HFIS-Antrean faskes ini {kode=kodedokter, nama=namadokter}. */
    public function antreanRefDokterHfis(): array
    {
        $list = $this->extractAntreanList($this->antrean->refDokter());

        return collect($list)->map(fn ($d) => [
            'kode' => (string) ($d['kodedokter'] ?? ''),
            'nama' => (string) ($d['namadokter'] ?? ''),
        ])->filter(fn ($r) => $r['kode'] !== '')->values()->all();
    }

    /** Ambil array list dari response Antrean (langsung list / {list:[]} / cari array pertama). */
    private function extractAntreanList(array $result): array
    {
        $resp = $result['response'] ?? [];
        if (! is_array($resp)) {
            return [];
        }
        if (array_is_list($resp)) {
            return $resp;
        }
        if (isset($resp['list']) && is_array($resp['list'])) {
            return $resp['list'];
        }
        foreach ($resp as $v) {
            if (is_array($v) && array_is_list($v)) {
                return $v;
            }
        }

        return [];
    }

    /** Antrean Per Kode Booking (monitoring) — detail antrean & task satu booking. */
    public function antreanByKodebooking(string $bookingCode): array
    {
        return $this->antrean->getAntreanByKodebooking($bookingCode);
    }

    // =========================================================================
    // MAPPING POLI BPJS  (sinkron menu Jadwal Dokter)
    // =========================================================================

    public function indexPoliMapping(): \Illuminate\Database\Eloquent\Collection
    {
        return BpjsPoliMapping::orderBy('poli_code')->get();
    }

    /**
     * Daftar poli_code lokal (dari doctor_schedules) + status pemetaannya.
     * Dipakai UI Jadwal Dokter untuk menampilkan poli mana yang belum dipetakan.
     */
    public function poliMappingStatus(): array
    {
        $localCodes = DoctorSchedule::query()
            ->whereNotNull('poli_code')
            ->select('poli_code', 'poliklinik')
            ->distinct()
            ->get()
            ->keyBy('poli_code');

        $mappings = BpjsPoliMapping::all()->keyBy('poli_code');

        $rows = $localCodes->map(fn ($s) => [
            'poli_code'      => $s->poli_code,
            'poli_name'      => $s->poliklinik,
            'bpjs_poli_code' => $mappings[$s->poli_code]->bpjs_poli_code ?? null,
            'bpjs_poli_name' => $mappings[$s->poli_code]->bpjs_poli_name ?? null,
            'mapped'         => isset($mappings[$s->poli_code]),
        ])->values()->toArray();

        // Baris IGD selalu hadir walau tak ada jadwal dokter (pasien IGD walk-in).
        // SEP IGD me-resolve kode poli via poli_code 'IGD' → admin wajib bisa
        // memetakannya dari UI. (Konvensi sama Queue::STATION_IGD / jenis_pelayanan.)
        array_unshift($rows, [
            'poli_code'      => 'IGD',
            'poli_name'      => 'Instalasi Gawat Darurat',
            'bpjs_poli_code' => $mappings['IGD']->bpjs_poli_code ?? null,
            'bpjs_poli_name' => $mappings['IGD']->bpjs_poli_name ?? null,
            'mapped'         => isset($mappings['IGD']),
            'is_system'      => true,
        ]);

        return $rows;
    }

    public function upsertPoliMapping(array $data): BpjsPoliMapping
    {
        $mapping = BpjsPoliMapping::updateOrCreate(
            ['poli_code' => $data['poli_code']],
            [
                'poli_name'      => $data['poli_name'] ?? null,
                'bpjs_poli_code' => $data['bpjs_poli_code'],
                'bpjs_poli_name' => $data['bpjs_poli_name'] ?? null,
                'is_active'      => $data['is_active'] ?? true,
            ]
        );

        $this->log(auth('api')->id(), 'UPSERT_BPJS_POLI_MAPPING', BpjsPoliMapping::class, $mapping->id, $mapping->poli_code);

        return $mapping;
    }

    public function deletePoliMapping(string $id): void
    {
        $mapping = BpjsPoliMapping::findOrFail($id);
        $mapping->delete();
        $this->log(auth('api')->id(), 'DELETE_BPJS_POLI_MAPPING', BpjsPoliMapping::class, $id);
    }

    /** Set kode DPJP BPJS pada employee (dokter). */
    public function setDpjpCode(string $employeeId, ?string $dpjpCode): Employee
    {
        $emp = Employee::findOrFail($employeeId);
        $emp->update(['bpjs_dpjp_code' => $dpjpCode]);
        $this->log(auth('api')->id(), 'SET_BPJS_DPJP_CODE', Employee::class, $employeeId, $dpjpCode);

        return $emp->fresh();
    }

    /**
     * Set NIK employee (untuk Satu Sehat Practitioner IHS). Mengosongkan
     * satusehat_ihs bila NIK berubah agar di-resolve ulang saat sync.
     */
    public function setEmployeeNik(string $employeeId, ?string $nik): Employee
    {
        $emp = Employee::findOrFail($employeeId);
        $nik = $nik !== null ? trim($nik) : null;

        $payload = ['nik' => $nik ?: null];
        if ($emp->nik !== $payload['nik']) {
            $payload['satusehat_ihs'] = null; // NIK berubah → cache IHS tidak valid lagi.
        }
        $emp->update($payload);
        $this->log(auth('api')->id(), 'SET_EMPLOYEE_NIK', Employee::class, $employeeId, $nik);

        return $emp->fresh();
    }

    // =========================================================================
    // SINKRON JADWAL DOKTER → BPJS ANTREAN  (per minggu)
    // =========================================================================

    /**
     * Kirim jadwal dokter minggu tertentu ke BPJS Antrean. Loop per (dokter+poli),
     * skip yang belum punya mapping DPJP/poli (kumpulkan sebagai warning).
     */
    public function syncJadwalDokter(string $weekStart): array
    {
        $schedules = DoctorSchedule::with('employee')
            ->forWeek($weekStart)
            ->where('is_active', true)
            ->get();

        $sent = [];
        $skipped = [];

        // Group jadwal per (dokter, poli) → BPJS minta array jadwal per dokter+poli.
        $grouped = $schedules->groupBy(fn ($s) => $s->employee_id . '|' . $s->poli_code);

        foreach ($grouped as $rows) {
            $first    = $rows->first();
            $dpjp     = $first->employee?->bpjs_dpjp_code;
            $bpjsPoli = BpjsPoliMapping::bpjsCodeFor($first->poli_code);

            if (! $dpjp || ! $bpjsPoli) {
                $skipped[] = [
                    'dokter'    => $first->employee?->name,
                    'poli_code' => $first->poli_code,
                    'alasan'    => ! $dpjp ? 'Dokter belum punya kode DPJP BPJS' : "Poli {$first->poli_code} belum dipetakan",
                ];
                continue;
            }

            $jadwal = $rows->map(fn ($s) => [
                'hari'      => $s->day_of_week % 7, // BPJS: 0=Minggu..6=Sabtu; lokal 1=Senin..7=Minggu
                'buka'      => substr($s->start_time, 0, 5),
                'tutup'     => substr($s->end_time, 0, 5),
            ])->values()->toArray();

            $result = $this->antrean->updateJadwalDokter([
                'kodepoli'         => $bpjsPoli,
                'kodesubspesialis' => $bpjsPoli,
                'kodedokter'       => $dpjp,
                'jadwal'           => $jadwal,
            ]);

            $sent[] = [
                'dokter'     => $first->employee?->name,
                'poli'       => $bpjsPoli,
                'is_success' => $result['is_success'] ?? false,
                'message'    => $result['metaData']['message'] ?? null,
            ];
        }

        $this->log(auth('api')->id(), 'SYNC_JADWAL_DOKTER_BPJS', null, null, "week:{$weekStart} sent:" . count($sent) . " skipped:" . count($skipped));

        return ['week_start' => $weekStart, 'sent' => $sent, 'skipped' => $skipped];
    }

    // =========================================================================
    // PRIVATE
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
