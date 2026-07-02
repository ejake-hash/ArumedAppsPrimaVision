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
    // BPJS — WS REKAM MEDIS (push RM → i-Care)
    // =========================================================================

    /** GET /integrasi/bpjs/rm-dashboard?from=&to= — dashboard pengiriman rekam medis. */
    public function rekamMedisDashboard(Request $request): JsonResponse
    {
        return $this->ok($this->service->rekamMedisDashboard(
            $request->query('from'),
            $request->query('to'),
        ));
    }

    /**
     * GET /integrasi/bpjs/rm-log
     * Query: status (SUCCESS|FAILED), tanggal, q (cari SEP/nama/RM), per_page
     */
    public function rekamMedisLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getRekamMedisLog(
            $request->only(['status', 'tanggal', 'q', 'per_page'])
        ));
    }

    /**
     * POST /integrasi/bpjs/rm-send-batch  Body: { mode: AUTO|BACKLOG, limit }
     * Trigger batch kirim RM ke BPJS dari UI (= scheduler 23:59).
     */
    public function rekamMedisSendBatch(Request $request): JsonResponse
    {
        try {
            $result = $this->service->rmSendBatch(
                (string) $request->input('mode', 'AUTO'),
                $request->filled('limit') ? (int) $request->input('limit') : null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($result, "Batch RM selesai: terkirim {$result['sent']}, gagal {$result['failed']}");
    }

    /** POST /integrasi/bpjs/rm-resend/{visitId} — kirim ulang RM 1 kunjungan (force). */
    public function rekamMedisResend(string $visitId): JsonResponse
    {
        try {
            $result = $this->service->rmResend($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Rekam medis terkirim ke BPJS');
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

    /** PUT /integrasi/vclaim/rujukan  Body: { t_rujukan: {...}, visit_id? } — edit rujukan keluar [UAT #13.2]. */
    public function vclaimUpdateRujukan(Request $request): JsonResponse
    {
        $v = $request->validate(['t_rujukan' => 'required|array', 'visit_id' => 'nullable|uuid']);

        return $this->call(fn () => $this->service->vclaimUpdateRujukan($v['t_rujukan'], $v['visit_id'] ?? null));
    }

    /** GET /integrasi/vclaim/rujukan/spesialistik  Query: ppk, tglRujukan (Y-m-d) [UAT #13.3]. */
    public function vclaimListSpesialistikRujukan(Request $request): JsonResponse
    {
        $v = $request->validate(['ppk' => 'required|string', 'tglRujukan' => 'required|date_format:Y-m-d']);

        return $this->call(fn () => $this->service->vclaimListSpesialistikRujukan($v['ppk'], $v['tglRujukan']));
    }

    /** GET /integrasi/vclaim/rujukan/sarana  Query: ppk [UAT #13.4]. */
    public function vclaimListSaranaRujukan(Request $request): JsonResponse
    {
        $v = $request->validate(['ppk' => 'required|string']);

        return $this->call(fn () => $this->service->vclaimListSaranaRujukan($v['ppk']));
    }

    /** GET /integrasi/vclaim/sep-internal/{noSep} — cari SEP rujukan internal antar poli [UAT #14.2]. */
    public function vclaimGetSepInternal(string $noSep): JsonResponse
    {
        return $this->call(fn () => $this->service->vclaimGetSepInternal($noSep));
    }

    /** DELETE /integrasi/vclaim/sep-internal  Body: { t_sep: {...}, visit_id? } [UAT #10]. */
    public function vclaimDeleteSepInternal(Request $request): JsonResponse
    {
        $v = $request->validate(['t_sep' => 'required|array', 'visit_id' => 'nullable|uuid']);

        return $this->call(fn () => $this->service->vclaimDeleteSepInternal($v['t_sep'], $v['visit_id'] ?? null));
    }

    /** DELETE /integrasi/vclaim/rencana-kontrol  Body: { no_surat_kontrol, user, visit_id? } [UAT #17.3]. */
    public function vclaimDeleteRencanaKontrol(Request $request): JsonResponse
    {
        $v = $request->validate([
            'no_surat_kontrol' => 'required|string',
            'user'             => 'required|string',
            'visit_id'         => 'nullable|uuid',
        ]);

        return $this->call(fn () => $this->service->vclaimDeleteRencanaKontrol($v['no_surat_kontrol'], $v['user'], $v['visit_id'] ?? null));
    }

    /** GET /integrasi/vclaim/rencana-kontrol/spesialistik  Query: jnsKontrol(1|2), nomor, tgl (Y-m-d) [UAT #17.4]. */
    public function vclaimListSpesialistikKontrol(Request $request): JsonResponse
    {
        $v = $request->validate([
            'jnsKontrol' => 'required|in:1,2',
            'nomor'      => 'required|string',
            'tgl'        => 'required|date_format:Y-m-d',
        ]);

        return $this->call(fn () => $this->service->vclaimListSpesialistikKontrol($v['jnsKontrol'], $v['nomor'], $v['tgl']));
    }

    /** GET /integrasi/vclaim/rencana-kontrol/jadwal-dokter  Query: jnsKontrol(1|2), kdPoli, tgl (Y-m-d) [UAT #17.5]. */
    public function vclaimJadwalDokterKontrol(Request $request): JsonResponse
    {
        $v = $request->validate([
            'jnsKontrol' => 'required|in:1,2',
            'kdPoli'     => 'required|string',
            'tgl'        => 'required|date_format:Y-m-d',
        ]);

        return $this->call(fn () => $this->service->vclaimJadwalDokterKontrol($v['jnsKontrol'], $v['kdPoli'], $v['tgl']));
    }

    /** GET /integrasi/vclaim/rencana-kontrol  Query: tglAwal, tglAkhir (Y-m-d), filter(1 tgl kontrol|2 tgl entri) [UAT #17.6]. */
    public function vclaimListRencanaKontrol(Request $request): JsonResponse
    {
        $v = $request->validate([
            'tglAwal'  => 'required|date_format:Y-m-d',
            'tglAkhir' => 'required|date_format:Y-m-d',
            'filter'   => 'nullable|in:1,2',
        ]);

        return $this->call(fn () => $this->service->vclaimListRencanaKontrol($v['tglAwal'], $v['tglAkhir'], $v['filter'] ?? '1'));
    }

    /** GET /integrasi/vclaim/sep-suplesi  Query: noKartu, tglPelayanan (Y-m-d) — SEP induk KLL utk suplesi [UAT #6.2]. */
    public function vclaimGetSepSuplesi(Request $request): JsonResponse
    {
        $v = $request->validate(['noKartu' => 'required|string', 'tglPelayanan' => 'required|date_format:Y-m-d']);

        return $this->call(fn () => $this->service->vclaimGetSepSuplesi($v['noKartu'], $v['tglPelayanan']));
    }

    /** POST /integrasi/vclaim/pengajuan-sep  Body: { request: {...}, visit_id? } — pengajuan penjaminan backdate/finger [UAT #11.1]. */
    public function vclaimPengajuanSep(Request $request): JsonResponse
    {
        $v = $request->validate(['request' => 'required|array', 'visit_id' => 'nullable|uuid']);

        return $this->call(fn () => $this->service->vclaimPengajuanSep($v['request'], $v['visit_id'] ?? null));
    }

    /** POST /integrasi/vclaim/aproval-sep  Body: { request: {...}, visit_id? } — approval penjaminan finger [UAT #11.2]. */
    public function vclaimAprovalSep(Request $request): JsonResponse
    {
        $v = $request->validate(['request' => 'required|array', 'visit_id' => 'nullable|uuid']);

        return $this->call(fn () => $this->service->vclaimAprovalSep($v['request'], $v['visit_id'] ?? null));
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
        abort_unless(in_array($jenis, ['tanggal', 'bulan'], true), 422, 'jenis harus tanggal atau bulan');

        $rules = $jenis === 'bulan'
            ? ['bulan' => 'required|integer|min:1|max:12', 'tahun' => 'required|integer|min:2020|max:2100', 'waktu' => 'nullable|in:rs,server']
            : ['tanggal' => 'required|date_format:Y-m-d', 'waktu' => 'nullable|in:rs,server'];
        $v = $request->validate($rules);

        return $this->call(fn () => $this->service->antreanDashboard($jenis, $v));
    }

    /** POST /integrasi/antrean/validate-booking  Body: { booking_code, tgl_periksa? } */
    public function antreanValidateBooking(Request $request): JsonResponse
    {
        $v = $request->validate(['booking_code' => 'required|string', 'tgl_periksa' => 'nullable|date_format:Y-m-d']);

        return $this->call(fn () => $this->service->antreanValidateBooking($v['booking_code'], $v['tgl_periksa'] ?? ''));
    }

    /** GET /integrasi/antrean/ref-pasien-fp/{jenis}/{noidentitas}  jenis: nik|noka */
    public function antreanRefPasienFp(Request $request, string $jenis, string $noidentitas): JsonResponse
    {
        abort_unless(in_array($jenis, ['nik', 'noka'], true), 422, 'jenis harus nik atau noka');

        return $this->call(fn () => $this->service->antreanRefPasienFingerprint($jenis, $noidentitas));
    }

    /** GET /integrasi/antrean/ref-poli-fp — daftar poli wajib fingerprint (HFIS) [UAT Antrol Ref Poli FP]. */
    public function antreanRefPoliFp(): JsonResponse
    {
        return $this->call(fn () => $this->service->antreanRefPoliFingerprint());
    }

    /** POST /integrasi/antrean/list  Body: { kodepoli, kodedokter, hari, jampraktek, tanggalperiksa } — antrean per tgl/belum dilayani. */
    public function antreanList(Request $request): JsonResponse
    {
        return $this->call(fn () => $this->service->antreanList($request->except('visit_id')));
    }

    /** POST /integrasi/antrean/sisa  Body: params sisa antrean (WSBPJS). */
    public function antreanSisa(Request $request): JsonResponse
    {
        return $this->call(fn () => $this->service->antreanSisa($request->except('visit_id')));
    }

    /** GET /integrasi/antrean/ref-poli — daftar poli HFIS-Antrean (untuk picker pemetaan). */
    public function antreanRefPoli(): JsonResponse
    {
        return $this->call(fn () => $this->service->antreanRefPoliHfis());
    }

    /** GET /integrasi/antrean/ref-dokter — daftar dokter HFIS-Antrean faskes ini (kodedokter). */
    public function antreanRefDokter(): JsonResponse
    {
        return $this->call(fn () => $this->service->antreanRefDokterHfis());
    }

    /** GET /integrasi/antrean/jadwal-hfis/{kodepoli}/{tanggal} — jadwal terdaftar di HFIS. */
    public function antreanJadwalHfis(Request $request, string $kodepoli, string $tanggal): JsonResponse
    {
        return $this->call(fn () => $this->service->antreanJadwalHfis($kodepoli, $tanggal));
    }

    /** POST /integrasi/antrean/by-booking  Body: { booking_code } */
    public function antreanByBooking(Request $request): JsonResponse
    {
        $v = $request->validate(['booking_code' => 'required|string']);

        return $this->call(fn () => $this->service->antreanByKodebooking($v['booking_code']));
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

    /**
     * POST /integrasi/bpjs/sync-icd
     * Sinkron master ICD-10/ICD-9 dari referensi VClaim (cakupan oftalmologi).
     * Body: { type: 'icd10'|'icd9', keywords?: string[] }.
     */
    public function syncIcdFromVclaim(Request $request): JsonResponse
    {
        $v = $request->validate([
            'type'       => 'required|in:icd10,icd9',
            'keywords'   => 'nullable|array|max:200',
            'keywords.*' => 'string|max:20',
        ]);

        try {
            $result = $this->service->syncIcdFromVclaim($v['type'], $v['keywords'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(
            $result,
            "Sinkron {$result['type']} selesai: {$result['inserted']} baru, {$result['updated']} diperbarui."
        );
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

    /**
     * POST /integrasi/satusehat/resolve-ihs  Body: { limit }
     * Resolve IHS massal pasien ber-NIK yang belum punya IHS (batch 1–1000).
     */
    public function satusehatResolveIhs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            $result = $this->service->satusehatResolveIhsBatch((int) $data['limit']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($result, 'Resolve IHS selesai');
    }

    /**
     * GET /integrasi/satusehat/backfill/preview?from=&to=
     * Cek jumlah kunjungan HISTORIS yang layak di-backfill (sebelum eksekusi).
     */
    public function satusehatBackfillPreview(Request $request): JsonResponse
    {
        return $this->ok($this->service->satusehatBackfillPreview(
            $request->query('from'),
            $request->query('to'),
        ));
    }

    /**
     * POST /integrasi/satusehat/backfill  Body: { limit, from?, to? }
     * Jalankan backfill N kunjungan historis eligible (terlama dulu).
     */
    public function satusehatBackfill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['required', 'integer', 'min:1', 'max:5000'],
            'from'  => ['nullable', 'date'],
            'to'    => ['nullable', 'date'],
        ]);

        try {
            $syncLog = $this->service->satusehatBackfill(
                (int) $data['limit'],
                $data['from'] ?? null,
                $data['to'] ?? null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($syncLog, 'Backfill Satu Sehat selesai');
    }

    // =========================================================================
    // APLICARE — ketersediaan tempat tidur
    // =========================================================================

    /** GET /integrasi/bpjs/aplicare/ref-kelas — daftar kode kelas BPJS (picker master kamar). */
    public function aplicareRefKelas(): JsonResponse
    {
        return $this->call(fn () => $this->service->aplicareRefKelas());
    }

    /** POST /integrasi/bpjs/aplicare/sync  Body: { room_id? } — sinkron semua / satu ruang. */
    public function aplicareSync(Request $request): JsonResponse
    {
        $roomId = $request->input('room_id');

        return $this->call(
            fn () => $roomId
                ? $this->service->aplicarePushRoom($roomId)
                : $this->service->aplicareSyncAll(),
            'Sinkron ketersediaan tempat tidur ke Aplicare selesai',
        );
    }

    /** GET /integrasi/bpjs/aplicare/read  Query: start, limit — data ketersediaan dari BPJS. */
    public function aplicareRead(Request $request): JsonResponse
    {
        return $this->call(fn () => $this->service->aplicareRead(
            (int) $request->query('start', 1),
            (int) $request->query('limit', 100),
        ));
    }

    /** GET /integrasi/bpjs/aplicare-log  Query: action, is_success, per_page. */
    public function aplicareLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getAplicareLog(
            $request->only(['action', 'is_success', 'per_page'])
        ));
    }

    // =========================================================================
    // APOTEK ONLINE (fase 0)
    // =========================================================================

    /** GET /integrasi/bpjs/apotek/ref-dpho — referensi DPHO (master obat BPJS Apotek). */
    public function apotekRefDpho(): JsonResponse
    {
        return $this->call(fn () => $this->service->apotekRefDpho());
    }

    /**
     * POST /integrasi/bpjs/apotek/daftar-resep
     * Body: { kdppk, KdJnsObat, JnsTgl, TglMulai, TglAkhir } — monitoring resep apotek.
     */
    public function apotekDaftarResep(Request $request): JsonResponse
    {
        $v = $request->validate([
            'kdppk'     => 'required|string',
            'KdJnsObat' => 'required|string',
            'JnsTgl'    => 'required|string',
            'TglMulai'  => 'required|string',
            'TglAkhir'  => 'required|string',
        ]);

        return $this->call(fn () => $this->service->apotekDaftarResep($v));
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

    private function error(string $message, int|string $status = 500): JsonResponse
    {
        // Coerce non-int status (e.g. PDO SQLSTATE string from QueryException) to a valid HTTP code.
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 500;
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
