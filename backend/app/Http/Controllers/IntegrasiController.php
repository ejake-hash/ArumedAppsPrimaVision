<?php

namespace App\Http\Controllers;

use App\Services\IntegrasiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrasiController extends Controller
{
    public function __construct(private readonly IntegrasiService $service) {}

    // =========================================================================
    // STATUS & CONFIG
    // =========================================================================

    /**
     * GET /integrasi/status
     * Status semua 6 sistem integrasi: enabled, last_test, has_credentials.
     */
    public function statusSemua(): JsonResponse
    {
        return $this->ok($this->service->getStatusSemua());
    }

    /**
     * POST /integrasi/test/{system}
     * system: VCLAIM | ANTREAN | ICARE | LUPIS | INACBGS | SATUSEHAT
     * Test koneksi + update last_test_status di integration_configs.
     */
    public function testKoneksi(string $system): JsonResponse
    {
        try {
            $result = $this->service->testKoneksi($system);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, "Test koneksi {$system} selesai");
    }

    /** GET /integrasi/config */
    public function indexConfig(): JsonResponse
    {
        return $this->ok($this->service->indexConfig());
    }

    /**
     * PUT /integrasi/config/{id}
     * Update credentials, base_url, is_enabled.
     * Body: { is_enabled, base_url, credentials: {...}, configuration: {...}, notes }
     */
    public function updateConfig(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled'    => 'nullable|boolean',
            'base_url'      => 'nullable|string|max:500',
            'credentials'   => 'nullable|array',
            'configuration' => 'nullable|array',
            'notes'         => 'nullable|string|max:500',
        ]);

        return $this->ok($this->service->updateConfig($id, $validated), 'Konfigurasi integrasi diperbarui');
    }

    // =========================================================================
    // BPJS — VCLAIM LOGS
    // =========================================================================

    /**
     * GET /integrasi/bpjs/vclaim-log
     * Query: action, is_success, tanggal, per_page
     */
    public function vclaimpLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getVclaimLog(
            $request->only(['action', 'is_success', 'tanggal', 'per_page'])
        ));
    }

    /** GET /integrasi/bpjs/vclaim-log/{id} */
    public function showVclaimpLog(string $id): JsonResponse
    {
        return $this->ok($this->service->showVclaimLog($id));
    }

    // =========================================================================
    // BPJS — ANTREAN LOGS
    // =========================================================================

    /**
     * GET /integrasi/bpjs/antrean-log
     * Query: action, per_page
     */
    public function antreanLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getAntreanLog(
            $request->only(['action', 'per_page'])
        ));
    }

    // =========================================================================
    // BPJS — ICARE LOGS
    // =========================================================================

    /**
     * GET /integrasi/bpjs/icare-log
     * Query: action, per_page
     */
    public function icareLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getIcareLog(
            $request->only(['action', 'per_page'])
        ));
    }

    // =========================================================================
    // INA-CBGs LOGS
    // =========================================================================

    /**
     * GET /integrasi/bpjs/inacbgs-log
     * Query: status, per_page
     */
    public function inacbgsLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getInacbgsLog(
            $request->only(['status', 'per_page'])
        ));
    }

    // =========================================================================
    // RUJUKAN BPJS
    // =========================================================================

    /** GET /integrasi/bpjs/rujukan-masuk */
    public function indexRujukanMasuk(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexRujukanMasuk(
            $request->only(['status', 'per_page'])
        ));
    }

    /** GET /integrasi/bpjs/rujukan-masuk/{id} */
    public function showRujukanMasuk(string $id): JsonResponse
    {
        return $this->ok($this->service->showRujukanMasuk($id));
    }

    /** GET /integrasi/bpjs/rujukan-keluar */
    public function indexRujukanKeluar(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexRujukanKeluar(
            $request->only(['status', 'per_page'])
        ));
    }

    /** GET /integrasi/bpjs/rujukan-keluar/{id} */
    public function showRujukanKeluar(string $id): JsonResponse
    {
        return $this->ok($this->service->showRujukanKeluar($id));
    }

    // =========================================================================
    // SURAT KONTROL BPJS
    // =========================================================================

    /** GET /integrasi/bpjs/surat-kontrol */
    public function indexSuratKontrol(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexSuratKontrol(
            $request->only(['status', 'per_page'])
        ));
    }

    /** GET /integrasi/bpjs/surat-kontrol/{id} */
    public function showSuratKontrol(string $id): JsonResponse
    {
        return $this->ok($this->service->showSuratKontrol($id));
    }

    /**
     * POST /integrasi/bpjs/surat-kontrol/{id}/submit
     * Submit Surat Kontrol ke VClaim → dapat nomor resmi dari BPJS.
     */
    public function submitSuratKontrol(string $id): JsonResponse
    {
        try {
            $letter = $this->service->submitSuratKontrol($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($letter, 'Surat Kontrol disubmit ke VClaim');
    }

    // =========================================================================
    // VCLAIM — LIVE CALLS (peserta / rujukan / SEP / LPK / monitoring / referensi)
    // =========================================================================

    /** POST /integrasi/vclaim/cek-peserta  Body: { identifier, type(nik|nokartu), tglSep?, visit_id? } */
    public function vclaimCekPeserta(Request $request): JsonResponse
    {
        $v = $request->validate([
            'identifier' => 'required|string',
            'type'       => 'nullable|in:nik,nokartu',
            'tglSep'     => 'nullable|date_format:Y-m-d',
            'visit_id'   => 'nullable|uuid',
        ]);

        return $this->call(fn () => $this->service->vclaimCekPeserta(
            $v['identifier'], $v['type'] ?? 'nik', $v['tglSep'] ?? '', $v['visit_id'] ?? null
        ));
    }

    /** POST /integrasi/vclaim/cek-rujukan  Body: { no_rujukan, sumber(rs|fktp)?, visit_id? } */
    public function vclaimCekRujukan(Request $request): JsonResponse
    {
        $v = $request->validate([
            'no_rujukan' => 'required|string',
            'sumber'     => 'nullable|in:rs,fktp',
            'visit_id'   => 'nullable|uuid',
        ]);

        return $this->call(fn () => $this->service->vclaimCekRujukan(
            $v['no_rujukan'], $v['sumber'] ?? 'rs', $v['visit_id'] ?? null
        ));
    }

    /** GET /integrasi/vclaim/rujukan-peserta/{noKartu}?list=1 */
    public function vclaimRujukanByKartu(Request $request, string $noKartu): JsonResponse
    {
        return $this->call(fn () => $this->service->vclaimRujukanByKartu($noKartu, $request->boolean('list')));
    }

    /** POST /integrasi/vclaim/sep  Body: { t_sep: {...}, visit_id? } */
    public function vclaimGenerateSep(Request $request): JsonResponse
    {
        $v = $request->validate(['t_sep' => 'required|array', 'visit_id' => 'nullable|uuid']);

        return $this->call(fn () => $this->service->vclaimGenerateSep($v['t_sep'], $v['visit_id'] ?? null));
    }

    /** PUT /integrasi/vclaim/sep  Body: { t_sep: {...}, visit_id? } */
    public function vclaimUpdateSep(Request $request): JsonResponse
    {
        $v = $request->validate(['t_sep' => 'required|array', 'visit_id' => 'nullable|uuid']);

        return $this->call(fn () => $this->service->vclaimUpdateSep($v['t_sep'], $v['visit_id'] ?? null));
    }

    /** DELETE /integrasi/vclaim/sep  Body: { no_sep, user, visit_id? } */
    public function vclaimCancelSep(Request $request): JsonResponse
    {
        $v = $request->validate(['no_sep' => 'required|string', 'user' => 'required|string', 'visit_id' => 'nullable|uuid']);

        return $this->call(fn () => $this->service->vclaimCancelSep($v['no_sep'], $v['user'], $v['visit_id'] ?? null));
    }

    /** POST /integrasi/vclaim/lpk  Body: { t_lpk: {...}, visit_id? } */
    public function vclaimInsertLpk(Request $request): JsonResponse
    {
        $v = $request->validate(['t_lpk' => 'required|array', 'visit_id' => 'nullable|uuid']);

        return $this->call(fn () => $this->service->vclaimInsertLpk($v['t_lpk'], $v['visit_id'] ?? null));
    }

    /** GET /integrasi/vclaim/monitoring/{jenis}  Query: tgl, jns, status, noKartu, tglMulai, tglAkhir */
    public function vclaimMonitoring(Request $request, string $jenis): JsonResponse
    {
        return $this->call(fn () => $this->service->vclaimMonitoring(
            $jenis, $request->only(['tgl', 'jns', 'status', 'noKartu', 'tglMulai', 'tglAkhir'])
        ));
    }

    /** GET /integrasi/vclaim/referensi/{jenis}  Query: q, jns, kode, spesialis, jnsPelayanan, tglPelayanan */
    public function vclaimReferensi(Request $request, string $jenis): JsonResponse
    {
        return $this->call(fn () => $this->service->vclaimReferensi(
            $jenis, $request->only(['q', 'jns', 'kode', 'spesialis', 'jnsPelayanan', 'tglPelayanan'])
        ));
    }

    // =========================================================================
    // ANTREAN — LIVE CALLS
    // =========================================================================

    /** POST /integrasi/antrean/add  Body: payload antrean + visit_id? */
    public function antreanAdd(Request $request): JsonResponse
    {
        $visitId = $request->input('visit_id');

        return $this->call(fn () => $this->service->antreanAdd($request->except('visit_id'), $visitId));
    }

    /** POST /integrasi/antrean/updatewaktu  Body: { kodebooking, taskid, waktu, visit_id? } */
    public function antreanUpdateWaktu(Request $request): JsonResponse
    {
        $v = $request->validate([
            'kodebooking' => 'required|string',
            'taskid'      => 'required|integer',
            'waktu'       => 'required|integer',
            'visit_id'    => 'nullable|uuid',
        ]);

        return $this->call(fn () => $this->service->antreanUpdateWaktu(
            ['kodebooking' => $v['kodebooking'], 'taskid' => $v['taskid'], 'waktu' => $v['waktu']],
            $v['visit_id'] ?? null
        ));
    }

    /** POST /integrasi/antrean/batal  Body: { kodebooking, keterangan, visit_id? } */
    public function antreanBatal(Request $request): JsonResponse
    {
        $v = $request->validate(['kodebooking' => 'required|string', 'keterangan' => 'required|string', 'visit_id' => 'nullable|uuid']);

        return $this->call(fn () => $this->service->antreanBatal($v['kodebooking'], $v['keterangan'], $v['visit_id'] ?? null));
    }

    /** GET /integrasi/antrean/dashboard/{jenis}  Query: tanggal | bulan,tahun ; waktu(rs|server) */
    public function antreanDashboard(Request $request, string $jenis): JsonResponse
    {
        return $this->call(fn () => $this->service->antreanDashboard(
            $jenis, $request->only(['tanggal', 'bulan', 'tahun', 'waktu'])
        ));
    }

    /** POST /integrasi/antrean/validate-booking  Body: { booking_code, tgl_periksa? } */
    public function antreanValidateBooking(Request $request): JsonResponse
    {
        $v = $request->validate(['booking_code' => 'required|string', 'tgl_periksa' => 'nullable|date_format:Y-m-d']);

        return $this->call(fn () => $this->service->antreanValidateBooking($v['booking_code'], $v['tgl_periksa'] ?? ''));
    }

    // =========================================================================
    // MAPPING POLI/DPJP BPJS  (sinkron menu Jadwal Dokter)
    // =========================================================================

    /** GET /integrasi/bpjs/poli-mapping */
    public function indexPoliMapping(): JsonResponse
    {
        return $this->ok($this->service->indexPoliMapping());
    }

    /** GET /integrasi/bpjs/poli-mapping/status — poli lokal + status pemetaan */
    public function poliMappingStatus(): JsonResponse
    {
        return $this->ok($this->service->poliMappingStatus());
    }

    /** POST /integrasi/bpjs/poli-mapping */
    public function upsertPoliMapping(Request $request): JsonResponse
    {
        $v = $request->validate([
            'poli_code'      => 'required|string|max:10',
            'poli_name'      => 'nullable|string|max:100',
            'bpjs_poli_code' => 'required|string|max:10',
            'bpjs_poli_name' => 'nullable|string|max:150',
            'is_active'      => 'nullable|boolean',
        ]);

        return $this->ok($this->service->upsertPoliMapping($v), 'Pemetaan poli BPJS disimpan');
    }

    /** DELETE /integrasi/bpjs/poli-mapping/{id} */
    public function deletePoliMapping(string $id): JsonResponse
    {
        $this->service->deletePoliMapping($id);

        return $this->ok(null, 'Pemetaan poli BPJS dihapus');
    }

    /** PUT /integrasi/bpjs/dokter/{employeeId}/dpjp  Body: { bpjs_dpjp_code } */
    public function setDpjpCode(Request $request, string $employeeId): JsonResponse
    {
        $v = $request->validate(['bpjs_dpjp_code' => 'nullable|string|max:20']);

        return $this->ok($this->service->setDpjpCode($employeeId, $v['bpjs_dpjp_code'] ?? null), 'Kode DPJP BPJS disimpan');
    }

    /** PUT /integrasi/satusehat/dokter/{employeeId}/nik  Body: { nik }  (Practitioner IHS) */
    public function setEmployeeNik(Request $request, string $employeeId): JsonResponse
    {
        $v = $request->validate(['nik' => 'nullable|string|max:32']);

        return $this->ok($this->service->setEmployeeNik($employeeId, $v['nik'] ?? null), 'NIK dokter disimpan');
    }

    /** POST /integrasi/bpjs/sync-jadwal-dokter  Body: { week_start: Y-m-d } */
    public function syncJadwalDokter(Request $request): JsonResponse
    {
        $v = $request->validate(['week_start' => 'required|date_format:Y-m-d']);

        return $this->call(fn () => $this->service->syncJadwalDokter($v['week_start']), 'Sinkron jadwal dokter ke BPJS selesai');
    }

    // =========================================================================
    // SATU SEHAT
    // =========================================================================

    /**
     * GET /integrasi/satusehat/dashboard
     * Statistik monitoring Satu Sehat (4 resource + kunjungan + kesiapan data + tren).
     * Query: from, to (YYYY-MM-DD, default hari ini).
     */
    public function satusehatDashboard(Request $request): JsonResponse
    {
        return $this->ok($this->service->satusehatDashboard(
            $request->input('from'),
            $request->input('to')
        ));
    }

    /**
     * GET /integrasi/satusehat/kfa-search?keyword=
     * Cari kode KFA obat (untuk tombol "Cari KFA" di master Obat).
     */
    public function satusehatKfaSearch(Request $request): JsonResponse
    {
        return $this->ok($this->service->satusehatSearchKfa(
            (string) $request->input('keyword', '')
        ));
    }

    // ---- Satu Sehat Location (daftar/registrasi/edit/nonaktif) ----

    /** GET /integrasi/satusehat/location — daftar Location + mana yang aktif. */
    public function satusehatListLocations(): JsonResponse
    {
        return $this->ok($this->service->satusehatListLocations());
    }

    /** POST /integrasi/satusehat/location  Body: { name, physical_type?, set_active? } */
    public function satusehatRegisterLocation(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'          => 'required|string|max:120',
            'physical_type' => 'nullable|string|max:5',
            'set_active'    => 'nullable|boolean',
        ]);

        return $this->ok($this->service->satusehatRegisterLocation(
            $v['name'],
            $v['physical_type'] ?? 'ro',
            $v['set_active'] ?? true,
        ), 'Lokasi didaftarkan ke Satu Sehat');
    }

    /** PUT /integrasi/satusehat/location/{id}  Body: { name, status?, physical_type? } */
    public function satusehatUpdateLocation(Request $request, string $id): JsonResponse
    {
        $v = $request->validate([
            'name'          => 'required|string|max:120',
            'status'        => 'nullable|in:active,inactive',
            'physical_type' => 'nullable|string|max:5',
        ]);

        return $this->ok($this->service->satusehatUpdateLocation(
            $id, $v['name'], $v['status'] ?? 'active', $v['physical_type'] ?? 'ro',
        ), 'Lokasi diperbarui');
    }

    /** DELETE /integrasi/satusehat/location/{id}?name= — set status inactive. */
    public function satusehatDeactivateLocation(Request $request, string $id): JsonResponse
    {
        return $this->ok($this->service->satusehatDeactivateLocation(
            $id, (string) $request->input('name', ''), (string) $request->input('physical_type', 'ro'),
        ), 'Lokasi dinonaktifkan');
    }

    /** PUT /integrasi/satusehat/location/{id}/active — jadikan default Encounter. */
    public function satusehatSetActiveLocation(string $id): JsonResponse
    {
        $this->service->satusehatSetActiveLocation($id);

        return $this->ok(['active_id' => $id], 'Lokasi aktif diperbarui');
    }

    /**
     * GET /integrasi/satusehat/sync-log
     * Query: status (SUCCESS|PARTIAL|FAILED|RUNNING), per_page
     */
    public function satusehatSyncLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getSatusehatSyncLog(
            $request->only(['status', 'per_page'])
        ));
    }

    /** GET /integrasi/satusehat/sync-log/{id} */
    public function showSatusehatSyncLog(string $id): JsonResponse
    {
        return $this->ok($this->service->showSatusehatSyncLog($id));
    }

    /**
     * GET /integrasi/satusehat/resource-log
     * Query: status, resource_type (Encounter|Condition|...), per_page
     */
    public function satusehatResourceLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getSatusehatResourceLog(
            $request->only(['status', 'resource_type', 'per_page'])
        ));
    }

    /**
     * POST /integrasi/satusehat/sync-manual
     * Trigger manual batch sync Satu Sehat (semua kunjungan SELESAI hari ini).
     */
    public function satusehatSyncManual(): JsonResponse
    {
        try {
            $syncLog = $this->service->satusehatSyncManual();
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($syncLog, 'Manual sync Satu Sehat dimulai');
    }

    /**
     * POST /integrasi/satusehat/retry/{logId}
     * Retry failed sync log.
     */
    public function satusehatRetry(string $logId): JsonResponse
    {
        try {
            $syncLog = $this->service->satusehatRetry($logId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($syncLog, 'Retry sync Satu Sehat selesai');
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    /**
     * Bungkus call ke service BPJS: tangani RuntimeException(503) "belum aktif"
     * dan Exception lain → response error rapi, tidak crash.
     */
    private function call(callable $fn, string $message = 'Berhasil'): JsonResponse
    {
        try {
            return $this->ok($fn(), $message);
        } catch (\RuntimeException $e) {
            // BpjsClient melempar 503 jika integrasi belum aktif / credential kosong.
            return $this->error($e->getMessage(), $e->getCode() === 503 ? 503 : 422);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
        }
    }

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
