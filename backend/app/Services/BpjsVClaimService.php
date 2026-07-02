<?php

namespace App\Services;

use App\Models\BpjsVClaimLog;
use App\Services\Bpjs\BpjsClient;

/**
 * BPJS VClaim — REST API VClaim (vclaim-rest-dev / vclaim-rest).
 *
 * Semua call dilakukan lewat {@see BpjsClient} (auth HMAC + decrypt AES/LZString).
 * Response VClaim terenkripsi → BpjsClient::request($m,$p,$body, encrypted: true).
 *
 * Cakupan (Docs/BRIDGING VCLAIM.md):
 *   Peserta, Rujukan (cari/insert/update/delete + 2.0 + list keluar + spesialistik/sarana),
 *   SEP 2.0 (insert/update/delete/updtglplg + internal + fingerprint + cbg),
 *   Surat Kontrol / Rencana Kontrol v2 / SPRI, LPK, Monitoring, Referensi.
 *
 * Konvensi return: array hasil BpjsClient → { metaData, response, http_status, is_success, raw }.
 * Bila integrasi belum aktif → BpjsClient melempar RuntimeException(503) — caller menangani.
 */
class BpjsVClaimService
{
    private BpjsClient $client;

    public function __construct(?BpjsClient $client = null)
    {
        $this->client = $client ?? BpjsClient::for('VCLAIM');
    }

    public function boot(): void
    {
        // Kompat lama: re-resolve client (membaca ulang config dari DB).
        $this->client = BpjsClient::for('VCLAIM');
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    // =========================================================================
    // PESERTA
    // =========================================================================

    /**
     * GET /Peserta/nokartu/{no}/tglSEP/{tgl}  atau  /Peserta/nik/{nik}/tglSEP/{tgl}
     *
     * @param  string  $type  'nokartu' | 'nik'
     */
    public function checkPeserta(string $identifier, string $type = 'nik', string $tglSep = '', ?string $visitId = null): array
    {
        $type   = strtolower($type) === 'nokartu' ? 'nokartu' : 'nik';
        $tglSep = $tglSep ?: now('Asia/Jakarta')->toDateString();

        $result = $this->client->request('GET', "/Peserta/{$type}/{$identifier}/tglSEP/{$tglSep}");

        $this->log($visitId, 'CHECK_PESERTA', compact('identifier', 'type', 'tglSep'), $result);

        return $result;
    }

    // =========================================================================
    // RUJUKAN
    // =========================================================================

    /** GET /Rujukan/RS/{noRujukan} — cari rujukan masuk (FKRTL) by nomor. */
    public function checkRujukan(string $noRujukan, ?string $visitId = null): array
    {
        $result = $this->client->request('GET', "/Rujukan/RS/{$noRujukan}");
        $this->log($visitId, 'CHECK_RUJUKAN', compact('noRujukan'), $result);

        return $result;
    }

    /** GET /Rujukan/{noRujukan} — cari rujukan FKTP (faskes 1) by nomor. */
    public function checkRujukanFktp(string $noRujukan, ?string $visitId = null): array
    {
        $result = $this->client->request('GET', "/Rujukan/{$noRujukan}");
        $this->log($visitId, 'CHECK_RUJUKAN_FKTP', compact('noRujukan'), $result);

        return $result;
    }

    /** GET /Rujukan/RS/Peserta/{noKartu} — rujukan FKRTL terakhir (1 record). */
    public function getRujukanByKartu(string $noKartu): array
    {
        return $this->client->request('GET', "/Rujukan/RS/Peserta/{$noKartu}");
    }

    /** GET /Rujukan/RS/List/Peserta/{noKartu} — semua rujukan FKRTL (multi record). */
    public function listRujukanByKartu(string $noKartu): array
    {
        return $this->client->request('GET', "/Rujukan/RS/List/Peserta/{$noKartu}");
    }

    /** GET /Rujukan/Peserta/{noKartu} — rujukan FKTP (faskes 1) terakhir by kartu. */
    public function getRujukanFktpByKartu(string $noKartu): array
    {
        return $this->client->request('GET', "/Rujukan/Peserta/{$noKartu}");
    }

    /** GET /Rujukan/List/Peserta/{noKartu} — semua rujukan FKTP (faskes 1) by kartu. */
    public function listRujukanFktpByKartu(string $noKartu): array
    {
        return $this->client->request('GET', "/Rujukan/List/Peserta/{$noKartu}");
    }

    /** POST /Rujukan/2.0/insert — buat rujukan keluar. */
    public function insertRujukanKeluar(array $tRujukan, ?string $visitId = null): array
    {
        $result = $this->client->request('POST', '/Rujukan/2.0/insert', ['request' => ['t_rujukan' => $tRujukan]]);
        $this->log($visitId, 'INSERT_RUJUKAN', $tRujukan, $result);

        return $result;
    }

    /** PUT /Rujukan/2.0/Update — ubah rujukan keluar. */
    public function updateRujukanKeluar(array $tRujukan, ?string $visitId = null): array
    {
        $result = $this->client->request('PUT', '/Rujukan/2.0/Update', ['request' => ['t_rujukan' => $tRujukan]]);
        $this->log($visitId, 'UPDATE_RUJUKAN', $tRujukan, $result);

        return $result;
    }

    /** DELETE /Rujukan/delete — hapus rujukan keluar. */
    public function deleteRujukanKeluar(string $noRujukan, string $user, ?string $visitId = null): array
    {
        $body   = ['request' => ['t_rujukan' => ['noRujukan' => $noRujukan, 'user' => $user]]];
        $result = $this->client->request('DELETE', '/Rujukan/delete', $body);
        $this->log($visitId, 'DELETE_RUJUKAN', compact('noRujukan'), $result);

        return $result;
    }

    /** GET /Rujukan/Keluar/List/tglMulai/{a}/tglAkhir/{b} */
    public function listRujukanKeluar(string $tglMulai, string $tglAkhir): array
    {
        return $this->client->request('GET', "/Rujukan/Keluar/List/tglMulai/{$tglMulai}/tglAkhir/{$tglAkhir}");
    }

    /** GET /Rujukan/Keluar/{noRujukan} */
    public function showRujukanKeluar(string $noRujukan): array
    {
        return $this->client->request('GET', "/Rujukan/Keluar/{$noRujukan}");
    }

    /** GET /Rujukan/JumlahSEP/{jnsRujukan}/{noRujukan}  (jns: 1 fktp, 2 fkrtl) */
    public function jumlahSepRujukan(string $jnsRujukan, string $noRujukan): array
    {
        return $this->client->request('GET', "/Rujukan/JumlahSEP/{$jnsRujukan}/{$noRujukan}");
    }

    /** GET /Rujukan/ListSpesialistik/PPKRujukan/{ppk}/TglRujukan/{tgl} */
    public function listSpesialistikRujukan(string $ppk, string $tglRujukan): array
    {
        return $this->client->request('GET', "/Rujukan/ListSpesialistik/PPKRujukan/{$ppk}/TglRujukan/{$tglRujukan}");
    }

    /** GET /Rujukan/ListSarana/PPKRujukan/{ppk} */
    public function listSaranaRujukan(string $ppk): array
    {
        return $this->client->request('GET', "/Rujukan/ListSarana/PPKRujukan/{$ppk}");
    }

    // =========================================================================
    // SEP 2.0  (RJ + RANAP — jnsPelayanan: 1 ranap, 2 rajal)
    // =========================================================================

    /** POST /SEP/2.0/insert — terbitkan SEP. $tSep = isi node t_sep lengkap. */
    public function generateSep(array $tSep, ?string $visitId = null): array
    {
        $result = $this->client->request('POST', '/SEP/2.0/insert', ['request' => ['t_sep' => $tSep]]);
        $this->log($visitId, 'GENERATE_SEP', $tSep, $result);

        return $result;
    }

    /** PUT /SEP/2.0/update */
    public function updateSep(array $tSep, ?string $visitId = null): array
    {
        $result = $this->client->request('PUT', '/SEP/2.0/update', ['request' => ['t_sep' => $tSep]]);
        $this->log($visitId, 'UPDATE_SEP', $tSep, $result);

        return $result;
    }

    /** GET /SEP/{noSep} — detail SEP yang sudah terbit (mis. dibuat via portal VClaim). */
    public function getSep(string $noSep, ?string $visitId = null): array
    {
        $result = $this->client->request('GET', "/SEP/{$noSep}");
        $this->log($visitId, 'GET_SEP', compact('noSep'), $result);

        return $result;
    }

    /** DELETE /SEP/2.0/delete */
    public function cancelSep(string $noSep, string $user, ?string $visitId = null): array
    {
        $body   = ['request' => ['t_sep' => ['noSep' => $noSep, 'user' => $user]]];
        $result = $this->client->request('DELETE', '/SEP/2.0/delete', $body);
        $this->log($visitId, 'CANCEL_SEP', compact('noSep'), $result);

        return $result;
    }

    /** PUT /SEP/2.0/updtglplg — update tanggal pulang (RANAP). */
    public function updateTglPulang(array $tSep, ?string $visitId = null): array
    {
        $result = $this->client->request('PUT', '/SEP/2.0/updtglplg', ['request' => ['t_sep' => $tSep]]);
        $this->log($visitId, 'UPDATE_TGL_PULANG', $tSep, $result);

        return $result;
    }

    /** GET /Sep/updtglplg/list/bulan/{b}/tahun/{t}/{filter} */
    public function listUpdateTglPulang(string $bulan, string $tahun, string $filter = ''): array
    {
        return $this->client->request('GET', "/Sep/updtglplg/list/bulan/{$bulan}/tahun/{$tahun}/{$filter}");
    }

    /** GET /SEP/Internal/{noSep} — SEP rujukan internal antar poli. */
    public function getSepInternal(string $noSep): array
    {
        return $this->client->request('GET', "/SEP/Internal/{$noSep}");
    }

    /** DELETE /SEP/Internal/delete */
    public function deleteSepInternal(array $tSep, ?string $visitId = null): array
    {
        $result = $this->client->request('DELETE', '/SEP/Internal/delete', ['request' => ['t_sep' => $tSep]]);
        $this->log($visitId, 'DELETE_SEP_INTERNAL', $tSep, $result);

        return $result;
    }

    /** GET /sep/cbg/{noSep} — SEP untuk aplikasi INA-CBGs 4.1 (format XML). */
    public function getSepForInacbg(string $noSep): array
    {
        return $this->client->request('GET', "/sep/cbg/{$noSep}");
    }

    // ---- Finger Print ----

    /** GET /SEP/FingerPrint/Peserta/{noKartu}/TglPelayanan/{tgl} */
    public function getFingerprint(string $noKartu, string $tglPelayanan): array
    {
        return $this->client->request('GET', "/SEP/FingerPrint/Peserta/{$noKartu}/TglPelayanan/{$tglPelayanan}");
    }

    /** GET /SEP/FingerPrint/List/Peserta/TglPelayanan/{tgl} */
    public function listFingerprint(string $tglPelayanan): array
    {
        return $this->client->request('GET', "/SEP/FingerPrint/List/Peserta/TglPelayanan/{$tglPelayanan}");
    }

    /** GET /SEP/FingerPrint/randomquestion/faskesterdaftar/nokapst/{no}/tglsep/{tgl} */
    public function fingerprintRandomQuestion(string $noKartu, string $tglSep): array
    {
        return $this->client->request('GET', "/SEP/FingerPrint/randomquestion/faskesterdaftar/nokapst/{$noKartu}/tglsep/{$tglSep}");
    }

    /** POST /SEP/FingerPrint/randomanswer */
    public function fingerprintRandomAnswer(array $tSep, ?string $visitId = null): array
    {
        $result = $this->client->request('POST', '/SEP/FingerPrint/randomanswer', ['request' => ['t_sep' => $tSep]]);
        $this->log($visitId, 'FINGERPRINT_ANSWER', $tSep, $result);

        return $result;
    }

    // ---- SEP KLL / Jasa Raharja — Suplesi ----

    /** GET /sep/JasaRaharja/Suplesi/{noKartu}/tglPelayanan/{tgl} — daftar SEP induk KLL utk suplesi (UAT #6.2). */
    public function getSepSuplesi(string $noKartu, string $tglPelayanan): array
    {
        return $this->client->request('GET', "/sep/JasaRaharja/Suplesi/{$noKartu}/tglPelayanan/{$tglPelayanan}");
    }

    // =========================================================================
    // PENGAJUAN PENJAMINAN (backdate / finger) — UAT #11
    // =========================================================================

    /** POST /SEP/pengajuanSEP — ajukan penjaminan (backdate RITL/RJTL / tanpa finger). $data = node "request". */
    public function pengajuanSep(array $data, ?string $visitId = null): array
    {
        $result = $this->client->request('POST', '/SEP/pengajuanSEP', ['request' => $data]);
        $this->log($visitId, 'PENGAJUAN_SEP', $data, $result);

        return $result;
    }

    /** POST /Sep/aprovalSEP — approval penjaminan (finger). $data = node "request". */
    public function aprovalSep(array $data, ?string $visitId = null): array
    {
        $result = $this->client->request('POST', '/Sep/aprovalSEP', ['request' => $data]);
        $this->log($visitId, 'APROVAL_SEP', $data, $result);

        return $result;
    }

    // =========================================================================
    // SURAT KONTROL / RENCANA KONTROL v2 / SPRI
    // =========================================================================

    /**
     * POST /RencanaKontrol/v2/Insert — buat Surat Kontrol (jnsKontrol 2).
     * Dipanggil IntegrasiService::submitSuratKontrol. $data = node "request".
     */
    public function postSuratKontrol(array $data, ?string $visitId = null): array
    {
        $result = $this->client->request('POST', '/RencanaKontrol/v2/Insert', ['request' => $data]);
        $this->log($visitId, 'INSERT_RENCANA_KONTROL', $data, $result);

        return $result;
    }

    /** Alias eksplisit. */
    public function insertRencanaKontrol(array $data, ?string $visitId = null): array
    {
        return $this->postSuratKontrol($data, $visitId);
    }

    /** PUT /RencanaKontrol/v2/Update */
    public function updateRencanaKontrol(array $data, ?string $visitId = null): array
    {
        $result = $this->client->request('PUT', '/RencanaKontrol/v2/Update', ['request' => $data]);
        $this->log($visitId, 'UPDATE_RENCANA_KONTROL', $data, $result);

        return $result;
    }

    /** DELETE /RencanaKontrol/Delete */
    public function deleteRencanaKontrol(string $noSuratKontrol, string $user, ?string $visitId = null): array
    {
        $body   = ['request' => ['t_suratkontrol' => ['noSuratKontrol' => $noSuratKontrol, 'user' => $user]]];
        $result = $this->client->request('DELETE', '/RencanaKontrol/Delete', $body);
        $this->log($visitId, 'DELETE_RENCANA_KONTROL', compact('noSuratKontrol'), $result);

        return $result;
    }

    /** POST /RencanaKontrol/InsertSPRI */
    public function insertSpri(array $data, ?string $visitId = null): array
    {
        $result = $this->client->request('POST', '/RencanaKontrol/InsertSPRI', ['request' => $data]);
        $this->log($visitId, 'INSERT_SPRI', $data, $result);

        return $result;
    }

    /** PUT /RencanaKontrol/UpdateSPRI */
    public function updateSpri(array $data, ?string $visitId = null): array
    {
        $result = $this->client->request('PUT', '/RencanaKontrol/UpdateSPRI', ['request' => $data]);
        $this->log($visitId, 'UPDATE_SPRI', $data, $result);

        return $result;
    }

    /** GET /RencanaKontrol/noSuratKontrol/{noSuratKontrol} — detail surat kontrol by nomor. */
    public function getSuratKontrol(string $noSuratKontrol, ?string $visitId = null): array
    {
        return $this->client->request('GET', "/RencanaKontrol/noSuratKontrol/{$noSuratKontrol}");
    }

    /** GET /RencanaKontrol/nosep/{noSep} */
    public function cariSepKontrol(string $noSep): array
    {
        return $this->client->request('GET', "/RencanaKontrol/nosep/{$noSep}");
    }

    /** GET /RencanaKontrol/ListRencanaKontrol/Bulan/{b}/Tahun/{t}/Nokartu/{no}/filter/{f} */
    public function listRencanaKontrolByKartu(string $bulan, string $tahun, string $noKartu, string $filter = '1'): array
    {
        return $this->client->request('GET', "/RencanaKontrol/ListRencanaKontrol/Bulan/{$bulan}/Tahun/{$tahun}/Nokartu/{$noKartu}/filter/{$filter}");
    }

    /** GET /RencanaKontrol/ListRencanaKontrol/tglAwal/{a}/tglAkhir/{b}/filter/{f} */
    public function listRencanaKontrolByTgl(string $tglAwal, string $tglAkhir, string $filter = '1'): array
    {
        return $this->client->request('GET', "/RencanaKontrol/ListRencanaKontrol/tglAwal/{$tglAwal}/tglAkhir/{$tglAkhir}/filter/{$filter}");
    }

    /** GET /RencanaKontrol/ListSpesialistik/JnsKontrol/{j}/nomor/{n}/TglRencanaKontrol/{t} */
    public function listSpesialistikKontrol(string $jnsKontrol, string $nomor, string $tgl): array
    {
        return $this->client->request('GET', "/RencanaKontrol/ListSpesialistik/JnsKontrol/{$jnsKontrol}/nomor/{$nomor}/TglRencanaKontrol/{$tgl}");
    }

    /** GET /RencanaKontrol/JadwalPraktekDokter/JnsKontrol/{j}/KdPoli/{p}/TglRencanaKontrol/{t} */
    public function jadwalDokterKontrol(string $jnsKontrol, string $kdPoli, string $tgl): array
    {
        return $this->client->request('GET', "/RencanaKontrol/JadwalPraktekDokter/JnsKontrol/{$jnsKontrol}/KdPoli/{$kdPoli}/TglRencanaKontrol/{$tgl}");
    }

    // =========================================================================
    // LPK — Lembar Pengajuan Klaim
    // =========================================================================

    /** POST /LPK/insert */
    public function insertLpk(array $tLpk, ?string $visitId = null): array
    {
        $result = $this->client->request('POST', '/LPK/insert', ['request' => ['t_lpk' => $tLpk]]);
        $this->log($visitId, 'INSERT_LPK', $tLpk, $result);

        return $result;
    }

    /** PUT /LPK/update */
    public function updateLpk(array $tLpk, ?string $visitId = null): array
    {
        $result = $this->client->request('PUT', '/LPK/update', ['request' => ['t_lpk' => $tLpk]]);
        $this->log($visitId, 'UPDATE_LPK', $tLpk, $result);

        return $result;
    }

    /** DELETE /LPK/delete */
    public function deleteLpk(string $noSep, ?string $visitId = null): array
    {
        $body   = ['request' => ['t_lpk' => ['noSep' => $noSep]]];
        $result = $this->client->request('DELETE', '/LPK/delete', $body);
        $this->log($visitId, 'DELETE_LPK', compact('noSep'), $result);

        return $result;
    }

    /** GET /LPK/TglMasuk/{tgl}/JnsPelayanan/{jns} */
    public function listLpk(string $tglMasuk, string $jnsPelayanan = '2'): array
    {
        return $this->client->request('GET', "/LPK/TglMasuk/{$tglMasuk}/JnsPelayanan/{$jnsPelayanan}");
    }

    // =========================================================================
    // KLAIM (alias backward-compat IntegrasiService)
    // =========================================================================

    /** Backward-compat: submit klaim = insert LPK. */
    public function submitKlaim(array $claimData, ?string $visitId = null): array
    {
        return $this->insertLpk($claimData, $visitId);
    }

    /** Cek status klaim via Monitoring (per SEP butuh tgl pulang; fallback monitoring kunjungan). */
    public function checkStatusKlaim(string $noSep, ?string $visitId = null): array
    {
        // Tidak ada endpoint cek-status-by-SEP langsung; gunakan monitoring klaim.
        return ['metaData' => ['code' => '404', 'message' => 'Gunakan monitoringKlaim(tgl, jns, status).'], 'response' => null, 'is_success' => false];
    }

    // =========================================================================
    // MONITORING
    // =========================================================================

    /** GET /Monitoring/Kunjungan/Tanggal/{tgl}/JnsPelayanan/{jns} */
    public function monitoringKunjungan(string $tgl, string $jnsPelayanan = '2'): array
    {
        $result = $this->client->request('GET', "/Monitoring/Kunjungan/Tanggal/{$tgl}/JnsPelayanan/{$jnsPelayanan}");
        // Dicatat agar shape respons (nama field SEP) bisa diverifikasi saat sinkron SEP.
        $this->log(null, 'MONITORING_KUNJUNGAN', compact('tgl', 'jnsPelayanan'), $result);

        return $result;
    }

    /** GET /Monitoring/Klaim/Tanggal/{tgl}/JnsPelayanan/{jns}/Status/{status} */
    public function monitoringKlaim(string $tgl, string $jnsPelayanan = '2', string $status = '1'): array
    {
        return $this->client->request('GET', "/Monitoring/Klaim/Tanggal/{$tgl}/JnsPelayanan/{$jnsPelayanan}/Status/{$status}");
    }

    /** GET /monitoring/HistoriPelayanan/NoKartu/{no}/tglMulai/{a}/tglAkhir/{b} */
    public function historiPelayanan(string $noKartu, string $tglMulai, string $tglAkhir): array
    {
        return $this->client->request('GET', "/monitoring/HistoriPelayanan/NoKartu/{$noKartu}/tglMulai/{$tglMulai}/tglAkhir/{$tglAkhir}");
    }

    // =========================================================================
    // REFERENSI  (cache ringan agar tidak hit BPJS berulang)
    // =========================================================================

    public function refDiagnosa(string $q): array        { return $this->refCached("/referensi/diagnosa/" . rawurlencode($q), "diag:{$q}"); }
    public function refPoli(string $q): array            { return $this->refCached("/referensi/poli/" . rawurlencode($q), "poli:{$q}"); }
    public function refFaskes(string $q, string $jns): array { return $this->refCached("/referensi/faskes/" . rawurlencode($q) . "/{$jns}", "faskes:{$q}:{$jns}"); }
    public function refDokter(string $q): array          { return $this->refCached("/referensi/dokter/" . rawurlencode($q), "dokter:{$q}"); }
    public function refDpjp(string $jnsPelayanan, string $tglPelayanan, string $spesialis): array { return $this->client->request('GET', "/referensi/dokter/pelayanan/{$jnsPelayanan}/tglPelayanan/{$tglPelayanan}/Spesialis/{$spesialis}"); }
    public function refPropinsi(): array                 { return $this->refCached('/referensi/propinsi', 'propinsi', 86400); }
    public function refKabupaten(string $kodeProp): array { return $this->refCached("/referensi/kabupaten/propinsi/{$kodeProp}", "kab:{$kodeProp}", 86400); }
    public function refKecamatan(string $kodeKab): array  { return $this->refCached("/referensi/kecamatan/kabupaten/{$kodeKab}", "kec:{$kodeKab}", 86400); }
    public function refDiagnosaPrb(): array              { return $this->refCached('/referensi/diagnosaprb', 'diagprb', 86400); }
    public function refObatPrb(string $q): array         { return $this->refCached("/referensi/obatprb/" . rawurlencode($q), "obatprb:{$q}"); }
    public function refProcedure(string $q): array       { return $this->refCached("/referensi/procedure/" . rawurlencode($q), "proc:{$q}"); }
    public function refKelasRawat(): array               { return $this->refCached('/referensi/kelasrawat', 'kelasrawat', 86400); }
    public function refSpesialistik(): array             { return $this->refCached('/referensi/spesialistik', 'spesialistik', 86400); }
    public function refRuangRawat(): array               { return $this->refCached('/referensi/ruangrawat', 'ruangrawat', 86400); }
    public function refCaraKeluar(): array               { return $this->refCached('/referensi/carakeluar', 'carakeluar', 86400); }
    public function refPascaPulang(): array              { return $this->refCached('/referensi/pascapulang', 'pascapulang', 86400); }

    private function refCached(string $path, string $key, int $ttl = 1800): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "bpjs:vclaim:ref:{$key}",
            $ttl,
            fn () => $this->client->request('GET', $path)
        );
    }

    // =========================================================================
    // TEST CONNECTION
    // =========================================================================

    public function testConnection(): array
    {
        try {
            // Endpoint ringan & idempoten: referensi propinsi.
            $result = $this->client->request('GET', '/referensi/propinsi');
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'system' => 'VCLAIM'];
        }

        return [
            'success'  => $result['is_success'] ?? false,
            'message'  => $result['metaData']['message'] ?? 'Tidak ada respon',
            'system'   => 'VCLAIM',
            'code'     => $result['metaData']['code'] ?? null,
        ];
    }

    // =========================================================================
    // LOG
    // =========================================================================

    private function log(?string $visitId, string $action, array $request, array $result): void
    {
        // response BPJS bisa scalar (mis. nomor SEP) — bungkus agar cast 'array' aman.
        $response = $result['response'] ?? null;
        if (! is_array($response)) {
            $response = ['metaData' => $result['metaData'] ?? null, 'response' => $response];
        }

        BpjsVClaimLog::create([
            'visit_id'         => $visitId,
            'action'           => $action,
            'request_payload'  => $request,
            'response_payload' => $response,
            'http_status'      => $result['http_status'] ?? 0,
            'is_success'       => $result['is_success'] ?? false,
            'error_message'    => ($result['is_success'] ?? false) ? null : ($result['metaData']['message'] ?? null),
        ]);
    }
}
