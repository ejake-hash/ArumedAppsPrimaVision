<?php

namespace App\Services;

use App\Models\BpjsClaim;
use App\Models\ClaimAuditLog;
use App\Models\InacbgsGroupingLog;
use App\Models\IntegrationConfig;
use App\Models\SystemLog;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KlaimService
{
    public function __construct(
        private readonly Request $request,
        private readonly InaCbgsService $eklaim,
    ) {}

    // =========================================================================
    // LIST & DETAIL
    // =========================================================================

    public function getClaimList(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsClaim::with(['visit.patient', 'visit.insurer', 'assignedTo']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter Rawat Jalan (RAJAL) / Rawat Inap (RANAP) via visit.
        if (! empty($filters['jenis_pelayanan'])) {
            $jp = $filters['jenis_pelayanan'];
            $query->whereHas('visit', fn ($v) => $v->where('jenis_pelayanan', $jp));
        }

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('no_sep', 'like', "%{$keyword}%")
                ->orWhere('patient_nik', 'like', "%{$keyword}%")
                ->orWhereHas('visit.patient', fn ($p) => $p->where('name', 'ilike', "%{$keyword}%"))
            );
        }

        if (! empty($filters['tanggal_from'])) {
            $query->whereDate('created_at', '>=', $filters['tanggal_from']);
        }

        if (! empty($filters['tanggal_to'])) {
            $query->whereDate('created_at', '<=', $filters['tanggal_to']);
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 20);
    }

    public function getClaimById(string $id): BpjsClaim
    {
        return BpjsClaim::with([
            'visit.patient',
            'visit.doctorExamination.doctor',
            'visit.billingInvoice',
            'assignedTo',
            'auditLogs.performedBy',
        ])->findOrFail($id);
    }

    // =========================================================================
    // PREPARE — build klaim dari data kunjungan
    // =========================================================================

    /**
     * Create or update BpjsClaim from visit doctor examination data.
     * Source: visit.no_sep, doctorExamination.{diagnosis_utama, diagnosis_sekunder, tindakan_codes}
     */
    public function prepareClaimData(string $visitId): BpjsClaim
    {
        $visit = Visit::with([
            'patient',
            'doctorExamination',
            'billingInvoice',
        ])->findOrFail($visitId);

        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }

        if (empty($visit->no_sep)) {
            throw new \Exception('Nomor SEP belum ada — generate SEP terlebih dahulu di Admisi.', 422);
        }

        $exam = $visit->doctorExamination;

        if (! $exam?->diagnosis_utama) {
            throw new \Exception('Diagnosis utama belum diisi oleh Dokter.', 422);
        }

        $user = auth('api')->user();

        $claim = DB::transaction(function () use ($visit, $exam, $user) {
            $existing = BpjsClaim::where('visit_id', $visit->id)->first();
            $oldStatus = $existing?->status;

            $claim = BpjsClaim::updateOrCreate(
                ['visit_id' => $visit->id],
                [
                    'no_sep'             => $visit->no_sep,
                    'patient_nik'        => $visit->patient->nik,
                    'diagnosis_utama'    => $exam->diagnosis_utama,
                    'diagnosis_sekunder' => $exam->diagnosis_sekunder ?? [],
                    'procedure_codes'    => $exam->tindakan_codes ?? [],
                    'status'             => $existing?->status ?? 'DRAFT',
                ]
            );

            $this->addAuditLog(
                $claim->id,
                $user?->employee_id,
                'PREPARE',
                $oldStatus,
                $claim->status,
                'Data klaim disiapkan dari data kunjungan'
            );

            return $claim;
        });

        $this->log($user?->id, 'PREPARE_CLAIM', BpjsClaim::class, $claim->id, "SEP {$visit->no_sep}");

        return $claim->fresh(['visit.patient', 'auditLogs.performedBy']);
    }

    // =========================================================================
    // ASSIGNMENT — tandai "dikerjakan oleh siapa" (soft, anti double-work)
    // =========================================================================

    /**
     * Tandai/lepas penanggung jawab klaim.
     * $userId = null → lepaskan. Soft (tidak mengunci).
     */
    public function assignClaim(string $claimId, ?string $assignToId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);
        $actor = auth('api')->user();

        $claim->update([
            'assigned_to_id' => $assignToId,
            'assigned_at'    => $assignToId ? now() : null,
        ]);

        $note = $assignToId
            ? 'Klaim ditandai dikerjakan oleh ' . (\App\Models\User::find($assignToId)?->name ?? $assignToId)
            : 'Penanda pengerjaan dilepas';
        $this->addAuditLog($claim->id, $actor?->employee_id, 'ASSIGN', $claim->status, $claim->status, $note);

        return $claim->fresh(['assignedTo', 'auditLogs.performedBy']);
    }

    // =========================================================================
    // KODING — edit diagnosis/tindakan klaim (oleh verifikator/koder)
    // =========================================================================

    /**
     * Perbarui koding klaim (diagnosis utama/sekunder + tindakan ICD-9).
     * Tidak menyentuh doctorExamination (rekam medis). Karena koding berubah,
     * hasil grouping & LUPIS direset — wajib grouping ulang.
     */
    public function updateClaimCoding(string $claimId, array $data): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim ke BPJS, koding tidak bisa diubah.', 422);
        }

        // Validasi kode ICD ada di master (koding BPJS wajib kode valid).
        $icd10Codes = array_filter(array_merge([$data['diagnosis_utama']], $data['diagnosis_sekunder'] ?? []));
        $icd9Codes  = array_filter($data['procedure_codes'] ?? []);

        $known10 = \App\Models\Icd10Code::whereIn('code', $icd10Codes)->pluck('code')->all();
        $unknown10 = array_diff($icd10Codes, $known10);
        if ($unknown10) {
            throw new \Exception('Kode ICD-10 tidak ditemukan di master: ' . implode(', ', $unknown10), 422);
        }

        $known9 = \App\Models\Icd9Code::whereIn('code', $icd9Codes)->pluck('code')->all();
        $unknown9 = array_diff($icd9Codes, $known9);
        if ($unknown9) {
            throw new \Exception('Kode ICD-9 tidak ditemukan di master: ' . implode(', ', $unknown9), 422);
        }

        $user      = auth('api')->user();
        $oldUtama  = $claim->diagnosis_utama;

        $claim->update([
            'diagnosis_utama'    => $data['diagnosis_utama'],
            'diagnosis_sekunder' => array_values($data['diagnosis_sekunder'] ?? []),
            'procedure_codes'    => array_values($data['procedure_codes'] ?? []),
            // Koding berubah → grouping & LUPIS tidak valid lagi.
            'inacbgs_kode'       => null,
            'inacbgs_tarif'      => null,
            'lupis_data'         => null,
        ]);

        $this->addAuditLog(
            $claim->id,
            $user?->employee_id,
            'EDIT_CODING',
            $claim->status,
            $claim->status,
            "Koding klaim diperbarui (Dx utama: {$oldUtama} → {$data['diagnosis_utama']}). Grouping direset."
        );
        $this->log($user?->id, 'EDIT_CLAIM_CODING', BpjsClaim::class, $claimId);

        return $claim->fresh(['auditLogs.performedBy']);
    }

    // =========================================================================
    // INA-CBGs GROUPING
    // =========================================================================

    /**
     * Grouping INA-CBGs via WS E-Klaim resmi (BUKAN grouper mock).
     *
     * Satu tombol "Jalankan Grouping" di UI = rangkaian WS lengkap:
     *   new_claim → set_claim_data → grouper
     * sehingga kode CBG + tarif yang tersimpan adalah hasil aplikasi E-Klaim,
     * bukan angka placeholder. set_claim_data idempoten di sisi E-Klaim
     * (mengirim ulang payload terkini), jadi aman dipanggil saat grouping ulang.
     */
    public function runInaCbgsGrouping(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'])) {
            throw new \Exception('Klaim sudah disubmit, tidak bisa grouping ulang.', 422);
        }

        if (! $claim->diagnosis_utama) {
            throw new \Exception('Diagnosis utama belum ada.', 422);
        }

        // 1) Registrasi klaim ke E-Klaim (idempoten: klaim yang sudah ada akan
        //    dijawab "sudah terdaftar" oleh ws.php — tetap lanjut).
        $this->eklaimNewClaim($claimId);

        // 2) Kirim/refresh data klaim (diagnosa, prosedur, tarif RS, dll).
        $this->eklaimSetData($claimId);

        // 3) Jalankan grouper resmi → simpan CBG + tarif balik ke klaim.
        return $this->eklaimGrouper($claimId);
    }

    // =========================================================================
    // E-KLAIM INA-CBG (Web Service ws.php)
    // =========================================================================
    //
    // Alur: eklaimNewClaim -> eklaimSetData -> eklaimGrouper -> eklaimFinal,
    // dengan eklaimStatus / eklaimReedit untuk sinkron & koreksi. Semua call
    // didelegasikan ke InaCbgsService (WS client) dan tercatat di
    // inacbgs_grouping_logs. Hasil grouping disimpan balik ke kolom klaim.

    /** new_claim — registrasi klaim (SEP/RM) ke E-Klaim. */
    public function eklaimNewClaim(string $claimId): array
    {
        $claim = $this->guardEklaimReady($claimId);

        $res = $this->eklaim->newClaim([
            'nomor_sep' => $claim->no_sep,
            'nomor_rm'  => $claim->visit?->patient?->no_rm ?? $claim->patient_nik,
            'nomor_kartu' => $claim->visit?->patient?->bpjs_number,
            'nama_pasien' => $claim->visit?->patient?->name,
        ], $claim->id, $claim->visit_id);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_NEW',
            $claim->status, $claim->status, $res['message'] ?? ('code ' . ($res['code'] ?? '-')));

        return $res;
    }

    /** set_claim_data — kirim payload klaim lengkap (builder Fase 4). */
    public function eklaimSetData(string $claimId): array
    {
        $claim = $this->guardEklaimReady($claimId);

        $payload = $this->buildEklaimPayload($claim);
        $res = $this->eklaim->setClaimData($payload, $claim->id, $claim->visit_id);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_SET_DATA',
            $claim->status, $claim->status, $res['message'] ?? ('code ' . ($res['code'] ?? '-')));

        return $res;
    }

    /** grouper — jalankan grouper E-Klaim, simpan CBG + tarif balik ke klaim. */
    public function eklaimGrouper(string $claimId, int $stage = 1): BpjsClaim
    {
        $claim = $this->guardEklaimReady($claimId);

        $res = $this->eklaim->grouper($claim->no_sep, $stage, $claim->id, $claim->visit_id);

        // Bentuk respons grouper E-Klaim (umum): data.cbg.code / .tariff / .description.
        $cbg = $res['data']['cbg'] ?? $res['raw']['response']['cbg'] ?? [];
        $code  = $cbg['code']   ?? $cbg['kode']  ?? null;
        $tarif = $cbg['tariff'] ?? $cbg['tarif'] ?? null;

        if ($res['success'] && $code) {
            $claim->update([
                'inacbgs_kode'  => $code,
                'inacbgs_tarif' => $tarif,
            ]);
        }

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_GROUPER',
            $claim->status, $claim->status,
            $code ? "CBG: {$code} — Tarif: " . number_format((float) $tarif) : ($res['message'] ?? 'Grouper gagal'));

        if (! $res['success']) {
            throw new \Exception('Grouper E-Klaim gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /** claim_final — finalisasi (irreversible). Tandai klaim SUBMITTED. */
    public function eklaimFinal(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        // Idempotensi: klaim yang sudah final/selesai jangan difinalisasi ulang
        // (mencegah re-stamp submitted_at & panggilan WS claim_final berulang).
        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah difinalisasi. Gunakan Re-edit bila perlu koreksi.', 422);
        }

        if (! $claim->inacbgs_kode) {
            throw new \Exception('Grouping E-Klaim belum dilakukan sebelum finalisasi.', 422);
        }

        $res = $this->eklaim->claimFinal($claim->no_sep, $claim->id, $claim->visit_id);

        if (! $res['success']) {
            $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_FINAL_GAGAL',
                $claim->status, $claim->status, $res['message'] ?? 'Finalisasi gagal');
            throw new \Exception('Finalisasi E-Klaim gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        $user = auth('api')->user();
        $old  = $claim->status;
        $claim->update([
            'status'        => 'SUBMITTED',
            'bpjs_status'   => 'FINAL',
            'bpjs_response' => $res['raw'] ?? null,
            'submitted_at'  => now(),
        ]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'EKLAIM_FINAL', $old, 'SUBMITTED',
            'Klaim difinalisasi di E-Klaim. ' . ($res['message'] ?? ''));
        $this->log($user?->id, 'EKLAIM_FINAL', BpjsClaim::class, $claim->id, "SEP {$claim->no_sep}");

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /** get_claim_status — status klaim di E-Klaim (tidak mengubah data lokal). */
    public function eklaimStatus(string $claimId): array
    {
        $claim = BpjsClaim::findOrFail($claimId);

        return $this->eklaim->getClaimStatus($claim->no_sep, $claim->id, $claim->visit_id);
    }

    /** reedit_claim — buka kembali klaim final untuk koreksi. */
    public function eklaimReedit(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        $res = $this->eklaim->reeditClaim($claim->no_sep, $claim->id, $claim->visit_id);

        if (! $res['success']) {
            throw new \Exception('Re-edit E-Klaim gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        // Kembalikan ke DRAFT agar bisa dikoreksi & diproses ulang.
        $old = $claim->status;
        $claim->update(['status' => 'DRAFT', 'bpjs_status' => null, 'submitted_at' => null]);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_REEDIT',
            $old, 'DRAFT', 'Klaim dibuka kembali dari E-Klaim untuk koreksi.');

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Fase 4 — Builder payload set_claim_data dari relasi Visit Arumed.
     *
     * ⚠️ PERLU VERIFIKASI ke Manual WS E-Klaim build 5.10.x: nama field,
     * format tanggal, kode gender, separator diagnosa/prosedur, dan apakah
     * tarif dikirim per-komponen atau total. Struktur di bawah memakai bentuk
     * umum WS E-Klaim; sesuaikan setelah Manual WS tersedia.
     */
    public function buildEklaimPayload(BpjsClaim $claim): array
    {
        $visit   = $claim->visit;
        $patient = $visit?->patient;
        $exam    = $visit?->doctorExamination;
        $invoice = $visit?->billingInvoice;

        $isRanap = ($visit?->jenis_pelayanan ?? 'RAJAL') === 'RANAP';

        $tglMasuk = $visit?->admission_at
            ? \Illuminate\Support\Carbon::parse($visit->admission_at)->format('Y-m-d')
            : \Illuminate\Support\Carbon::parse($visit?->visit_date ?? now())->format('Y-m-d');
        $tglPulang = $visit?->discharge_at
            ? \Illuminate\Support\Carbon::parse($visit->discharge_at)->format('Y-m-d')
            : $tglMasuk;

        // Diagnosa: utama + sekunder digabung separator ';' (umum WS E-Klaim).
        $dxSekunder = collect($claim->diagnosis_sekunder ?? [])
            ->map(fn ($c) => is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c)
            ->filter()->values()->all();
        $diagnosa = implode(';', array_filter(array_merge([$claim->diagnosis_utama], $dxSekunder)));

        $prosedur = collect($claim->procedure_codes ?? [])
            ->map(fn ($c) => is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c)
            ->filter()->values()->all();

        return [
            'nomor_sep'      => $claim->no_sep,
            'nomor_kartu'    => $patient?->bpjs_number,
            'nomor_rm'       => $patient?->no_rm ?? $claim->patient_nik,
            'nama_pasien'    => $patient?->name,
            'tgl_lahir'      => $patient?->date_of_birth?->format('Y-m-d'),
            // E-Klaim: 1=laki-laki, 2=perempuan. Patient Arumed: L/P.
            'gender'         => ($patient?->gender === 'P') ? '2' : '1',
            'tgl_masuk'      => $tglMasuk,
            'tgl_pulang'     => $tglPulang,
            'jenis_rawat'    => $isRanap ? '1' : '2', // 1=inap, 2=jalan
            'kelas_rawat'    => (string) ($visit?->kelas_rawat_hak ?? '3'),
            'diagnosa'       => $diagnosa,
            'procedure'      => implode(';', $prosedur),
            'tarif_rs'       => (float) ($invoice?->total ?? 0),
            'kode_tarif'     => config('eklaim.kode_tarif', 'CS'),
            'cara_pulang'    => $this->mapCaraPulang($visit?->discharge_type),
            'dpjp'           => $exam?->doctor?->name,
        ];
    }

    /** Map discharge_type Arumed -> kode cara pulang E-Klaim. */
    private function mapCaraPulang(?string $type): string
    {
        if ($type === null) {
            return '1'; // default: atas persetujuan dokter (hindari null array offset)
        }

        return [
            'PULANG_SEHAT' => '1', // atas persetujuan dokter
            'RUJUK'        => '2',
            'APS'          => '3', // atas permintaan sendiri
            'MENINGGAL'    => '4',
        ][$type] ?? '1';
    }

    /** Guard umum sebelum call WS: klaim ada, SEP & diagnosis utama terisi. */
    private function guardEklaimReady(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::with(['visit.patient', 'visit.doctorExamination.doctor', 'visit.billingInvoice'])
            ->findOrFail($claimId);

        if (empty($claim->no_sep)) {
            throw new \Exception('Nomor SEP belum ada pada klaim.', 422);
        }
        if (empty($claim->diagnosis_utama)) {
            throw new \Exception('Diagnosis utama belum diisi.', 422);
        }

        return $claim;
    }

    // =========================================================================
    // LUPIS — format data utilisasi untuk VClaim
    // =========================================================================

    public function generateLupis(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::with(['visit.patient', 'visit.billingInvoice.items'])->findOrFail($claimId);

        if (! $claim->inacbgs_kode) {
            throw new \Exception('Jalankan INA-CBGs grouping terlebih dahulu sebelum generate LUPIS.', 422);
        }

        $visit   = $claim->visit;
        $patient = $visit->patient;

        // Conditional rawat inap: jnsPelayanan '1' + field inap (tglMasuk/los/
        // kelasRawat/caraKeluar). Kelas rawat = kelas HAK (kelas_rawat_hak).
        $isRanap = ($visit->jenis_pelayanan ?? 'RAJAL') === 'RANAP';
        $tglMasuk = $visit->admission_at
            ? \Illuminate\Support\Carbon::parse($visit->admission_at)->format('Y-m-d')
            : null;
        $tglPulang = $visit->discharge_at
            ? \Illuminate\Support\Carbon::parse($visit->discharge_at)->format('Y-m-d')
            : $visit->updated_at?->format('Y-m-d'); // RAJAL: fallback ke updated_at (perilaku lama)

        // LOS = malam admission..discharge, minimum 1 (masuk dihitung, pulang tidak).
        $los = null;
        if ($isRanap && $visit->admission_at && $visit->discharge_at) {
            $los = max(1, \Illuminate\Support\Carbon::parse($visit->admission_at)->startOfDay()
                ->diffInDays(\Illuminate\Support\Carbon::parse($visit->discharge_at)->startOfDay()));
        }

        // Cara keluar dari discharge_type (PULANG_SEHAT|RUJUK|APS|MENINGGAL).
        $caraKeluarMap = [
            'PULANG_SEHAT' => '1', // atas persetujuan dokter
            'RUJUK'        => '2',
            'APS'          => '3', // atas permintaan sendiri
            'MENINGGAL'    => '4',
        ];

        // LUPIS format (struktur sesuai spesifikasi BPJS)
        $lupisData = [
            'noSep'            => $claim->no_sep,
            'nik'              => $claim->patient_nik,
            'nama'             => $patient->name,
            'tglLahir'         => $patient->date_of_birth?->format('Y-m-d'),
            'jnsPelayanan'     => $isRanap ? '1' : '2', // 1=rawat inap, 2=rawat jalan
            'diagnosaUtama'    => $claim->diagnosis_utama,
            'diagnosaSekunder' => $claim->diagnosis_sekunder ?? [],
            'procedureCodes'   => $claim->procedure_codes ?? [],
            'cbgCode'          => $claim->inacbgs_kode,
            'cbgTarif'         => $claim->inacbgs_tarif,
            'totalBiaya'       => $visit->billingInvoice?->total ?? 0,
            'tglPulang'        => $tglPulang,
        ];

        // Field khusus rawat inap.
        if ($isRanap) {
            $lupisData['tglMasuk']   = $tglMasuk;
            $lupisData['los']        = $los;
            $lupisData['kelasRawat'] = (string) ($visit->kelas_rawat_hak ?? '3');
            $lupisData['caraKeluar'] = $visit->discharge_type ? ($caraKeluarMap[$visit->discharge_type] ?? '1') : '1';
        }

        $user = auth('api')->user();

        $claim->update(['lupis_data' => $lupisData]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'LUPIS_GENERATED', $claim->status, $claim->status);
        $this->log($user?->id, 'GENERATE_LUPIS', BpjsClaim::class, $claimId);

        return $claim->fresh();
    }

    // =========================================================================
    // WORKFLOW STATUS
    // =========================================================================

    public function setReview(string $claimId): BpjsClaim
    {
        return $this->transitionStatus($claimId, 'DRAFT', 'REVIEW', 'REVIEW');
    }

    public function setVerifikasi(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'REVIEW') {
            throw new \Exception('Klaim harus dalam status REVIEW untuk diverifikasi.', 422);
        }

        if (! $claim->inacbgs_kode) {
            throw new \Exception('Grouping INA-CBGs belum dilakukan.', 422);
        }

        if (! $claim->lupis_data) {
            throw new \Exception('Data LUPIS belum di-generate.', 422);
        }

        return $this->transitionStatus($claimId, 'REVIEW', 'VERIFIED', 'VERIFIKASI');
    }

    /**
     * Tolak/kembalikan klaim oleh VERIFIKATOR INTERNAL (sebelum submit ke BPJS).
     * Status → DIKEMBALIKAN. Bisa di-resubmit (perbaiki lalu ajukan ulang).
     */
    public function setReject(string $claimId, string $reason): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim ke BPJS, tidak bisa dikembalikan internal. Gunakan respons BPJS.', 422);
        }

        $user      = auth('api')->user();
        $oldStatus = $claim->status;

        $claim->update([
            'status'           => 'DIKEMBALIKAN',
            'rejection_reason' => $reason,
            'rejected_at'      => now(),
        ]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'RETURN_INTERNAL', $oldStatus, 'DIKEMBALIKAN', $reason);
        $this->log($user?->id, 'RETURN_CLAIM', BpjsClaim::class, $claimId, $reason);

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Tandai klaim DITOLAK oleh BPJS (setelah submit). Dipanggil saat memproses
     * respons VClaim / monitoring. Status → DITOLAK_BPJS.
     */
    public function markBpjsRejected(string $claimId, string $reason, ?array $bpjsResponse = null): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'SUBMITTED') {
            throw new \Exception('Hanya klaim berstatus SUBMITTED yang bisa ditandai ditolak BPJS.', 422);
        }

        $user      = auth('api')->user();
        $oldStatus = $claim->status;

        $claim->update([
            'status'           => 'DITOLAK_BPJS',
            'bpjs_status'      => 'DITOLAK',
            'bpjs_response'    => $bpjsResponse ?? $claim->bpjs_response,
            'rejection_reason' => $reason,
            'rejected_at'      => now(),
        ]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'REJECT_BPJS', $oldStatus, 'DITOLAK_BPJS', $reason);
        $this->log($user?->id, 'REJECT_BPJS_CLAIM', BpjsClaim::class, $claimId, $reason);

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Ajukan ulang klaim yang DIKEMBALIKAN (internal) atau DITOLAK_BPJS.
     * Mengembalikan ke DRAFT untuk diperbaiki & diproses ulang
     * (grouping → LUPIS → verifikasi → submit). resubmission_count bertambah.
     * Pola mengikuti AsuransiService::resubmitKlaim.
     */
    public function resubmitClaim(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        // Dukung status baru + 'DITOLAK' lama (backward-compat).
        if (! in_array($claim->status, ['DIKEMBALIKAN', 'DITOLAK_BPJS', 'DITOLAK'], true)) {
            throw new \Exception('Hanya klaim yang dikembalikan/ditolak yang bisa diajukan ulang.', 422);
        }

        $user      = auth('api')->user();
        $oldStatus = $claim->status;
        $newCount  = ($claim->resubmission_count ?? 0) + 1;

        $claim->update([
            'status'             => 'DRAFT',
            'resubmission_count' => $newCount,
            // Bersihkan jejak penolakan agar siklus baru bersih.
            'rejection_reason'   => null,
            'rejected_at'        => null,
            'bpjs_status'        => null,
            'bpjs_response'      => null,
            // Reset hasil grouping/LUPIS — wajib di-run ulang setelah perbaikan data.
            'inacbgs_kode'       => null,
            'inacbgs_tarif'      => null,
            'lupis_data'         => null,
            'submitted_at'       => null,
        ]);

        $this->addAuditLog(
            $claim->id,
            $user?->employee_id,
            'RESUBMIT',
            $oldStatus,
            'DRAFT',
            "Klaim diajukan ulang (pengajuan ke-{$newCount}). Perbaiki data lalu jalankan grouping → LUPIS → verifikasi."
        );
        $this->log($user?->id, 'RESUBMIT_CLAIM', BpjsClaim::class, $claimId, "resubmission #{$newCount}");

        return $claim->fresh(['auditLogs.performedBy']);
    }

    // =========================================================================
    // SUBMIT KE VCLAIM
    // =========================================================================

    /**
     * Kirim klaim final ke E-Klaim INA-CBG (BUKAN mock VClaim).
     *
     * Tombol "Kirim ke BPJS" di UI = claim_final WS E-Klaim (irreversible,
     * hanya bisa dibuka via Re-edit). Delegasi penuh ke eklaimFinal() yang
     * memanggil ws.php, menandai klaim SUBMITTED + bpjs_status FINAL, dan
     * mencatat audit. Guard status VERIFIED dipertahankan di sini.
     */
    public function submitClaim(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'VERIFIED') {
            throw new \Exception('Klaim harus dalam status VERIFIED sebelum dikirim.', 422);
        }

        // eklaimFinal: cek inacbgs_kode, call claim_final WS, set SUBMITTED + audit.
        return $this->eklaimFinal($claimId);
    }

    // =========================================================================
    // LAMPIRAN BERKAS KLAIM (upload PDF/gambar: resume RJ, hasil penunjang, dll)
    // =========================================================================

    /** Daftar lampiran klaim (terbaru dulu). */
    public function getAttachments(string $claimId): array
    {
        BpjsClaim::findOrFail($claimId); // 404 bila klaim tak ada

        return \App\Models\ClaimAttachment::with('uploadedBy:id,name')
            ->where('bpjs_claim_id', $claimId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id'        => $d->id,
                'category'  => $d->category,
                'title'     => $d->title,
                'file_name' => $d->file_name,
                'file_url'  => $d->file_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($d->file_path) : null,
                'mime_type' => $d->mime_type,
                'file_size' => $d->file_size,
                'by'        => $d->uploadedBy?->name,
                'at'        => $d->created_at?->toIso8601String(),
            ])->all();
    }

    /** Upload lampiran (PDF/gambar) ke klaim. */
    public function uploadAttachment(string $claimId, array $data, $file): \App\Models\ClaimAttachment
    {
        $claim = BpjsClaim::findOrFail($claimId);

        // Klaim yang sudah final/selesai tidak boleh ditambah lampiran.
        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim/selesai — lampiran tidak bisa ditambah.', 422);
        }

        $category = in_array($data['category'] ?? null, \App\Models\ClaimAttachment::CATEGORIES, true)
            ? $data['category']
            : 'LAINNYA';

        $path = $file->store('claim-attachments', 'public');

        $att = \App\Models\ClaimAttachment::create([
            'bpjs_claim_id'  => $claim->id,
            'category'       => $category,
            'title'          => $data['title'] ?? $file->getClientOriginalName(),
            'file_path'      => $path,
            'file_name'      => $file->getClientOriginalName(),
            'mime_type'      => $file->getClientMimeType(),
            'file_size'      => $file->getSize(),
            'uploaded_by_id' => auth('api')->user()?->employee_id,
        ]);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'UPLOAD_LAMPIRAN',
            $claim->status, $claim->status, "Lampiran {$category}: {$att->file_name}");

        return $att;
    }

    /** Hapus lampiran (beserta file fisik). */
    public function deleteAttachment(string $claimId, string $attachmentId): void
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim/selesai — lampiran tidak bisa dihapus.', 422);
        }

        $att = \App\Models\ClaimAttachment::where('bpjs_claim_id', $claimId)->findOrFail($attachmentId);

        if ($att->file_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($att->file_path);
        }

        $name = $att->file_name;
        $att->delete();

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'HAPUS_LAMPIRAN',
            $claim->status, $claim->status, "Hapus lampiran: {$name}");
    }

    // =========================================================================
    // MONITORING & LOGS
    // =========================================================================

    public function getAuditLog(string $claimId): \Illuminate\Database\Eloquent\Collection
    {
        BpjsClaim::findOrFail($claimId); // 404 if not found

        return ClaimAuditLog::with('performedBy')
            ->where('bpjs_claim_id', $claimId)
            ->orderBy('created_at')
            ->get();
    }

    public function getGroupingLog(string $claimId): \Illuminate\Database\Eloquent\Collection
    {
        return InacbgsGroupingLog::where('bpjs_claim_id', $claimId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getVclaimLog(array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = DB::table('bpjs_vclaim_logs');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['status'])) {
            $query->where('http_status', $filters['status']);
        }

        return $query->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function icareMonitoring(): array
    {
        $this->assertIcareEnabled();

        // TODO: Call IntegrasiService::getIcareMonitoring()
        return [
            'message' => 'iCare monitoring belum terhubung. Aktifkan integrasi iCare terlebih dahulu.',
            'status'  => 'NOT_CONFIGURED',
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function transitionStatus(
        string $claimId,
        string $fromStatus,
        string $toStatus,
        string $action
    ): BpjsClaim {
        $claim = BpjsClaim::findOrFail($claimId);
        $user  = auth('api')->user();

        if ($claim->status !== $fromStatus) {
            throw new \Exception("Klaim harus dalam status {$fromStatus} untuk tindakan ini.", 422);
        }

        $claim->update(['status' => $toStatus]);

        $this->addAuditLog($claim->id, $user?->employee_id, $action, $fromStatus, $toStatus);
        $this->log($user?->id, $action . '_CLAIM', BpjsClaim::class, $claimId, "{$fromStatus} → {$toStatus}");

        return $claim->fresh(['auditLogs.performedBy']);
    }

    private function addAuditLog(
        string $claimId,
        ?string $employeeId,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $notes = null
    ): void {
        ClaimAuditLog::create([
            'bpjs_claim_id'   => $claimId,
            'performed_by_id' => $employeeId,
            'action'          => $action,
            'old_status'      => $oldStatus,
            'new_status'      => $newStatus,
            'notes'           => $notes,
        ]);
    }

    private function assertIcareEnabled(): void
    {
        $config = IntegrationConfig::where('system_name', 'ICARE')->first();

        if (! $config?->is_enabled) {
            throw new \Exception('Integrasi iCare belum diaktifkan.', 503);
        }
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
