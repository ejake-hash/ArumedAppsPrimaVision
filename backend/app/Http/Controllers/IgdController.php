<?php

namespace App\Http\Controllers;

use App\Models\Visit;
use App\Services\IgdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint IGD (Instalasi Gawat Darurat). 1 stasiun gabung long-lived
 * (papan internal, di-exclude dari Antrean TV). Lihat IgdService.
 */
class IgdController extends Controller
{
    public function __construct(private readonly IgdService $service) {}

    /** GET /igd/board — papan IGD (urut prioritas triase). */
    public function board(): JsonResponse
    {
        return $this->ok($this->service->board());
    }

    /** GET /igd/{visitId} — detail pasien IGD + triase + running bill. */
    public function detail(string $visitId): JsonResponse
    {
        try {
            return $this->ok($this->service->detail($visitId));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /igd/register — daftarkan pasien IGD (walk-in darurat). */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id'      => 'required|uuid',
            'guarantor_type'  => 'required|string|max:20',
            'insurer_id'      => 'nullable|uuid',
            'classification'  => 'nullable|string|max:20',
            'chief_complaint' => 'nullable|string|max:500',
            'triage_color'    => 'nullable|in:MERAH,KUNING,HIJAU,HITAM',
            'triage_level'    => 'nullable|in:1,2,3,4,5',
            'arrival_mode'    => 'nullable|in:KELUARGA,SENDIRI,POLISI,LAINNYA',
            'arrival_at'      => 'nullable|date',
        ]);

        try {
            $visit = $this->service->register($data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($visit, 'Pasien IGD terdaftar', 201);
    }

    /**
     * POST /igd/register-baru — daftar PASIEN BARU + langsung IGD (gawat darurat,
     * pasien belum terdaftar). NIK opsional bila identitas TANPA_IDENTITAS.
     */
    public function registerNew(Request $request): JsonResponse
    {
        $isTanpaIdentitas = $request->input('identity_type') === 'TANPA_IDENTITAS';

        $data = $request->validate([
            // Data pasien baru (pola AdmisiController::storePasien, NIK longgar utk darurat).
            'identity_type' => 'nullable|in:KTP,PASPOR,SIM,KIA,TANPA_IDENTITAS,LAINNYA',
            'nik'           => [$isTanpaIdentitas ? 'nullable' : 'nullable', 'string', 'max:32'],
            'name'          => 'required|string|max:255',
            'gender'        => 'required|in:L,P',
            'date_of_birth' => 'required|date|before_or_equal:today',
            'phone'         => 'nullable|string|max:20',
            'province'      => 'nullable|string|max:100',
            'address'       => 'nullable|string|max:500',
            'bpjs_number'   => 'nullable|string|max:20',
            'blood_type'    => 'nullable|in:A,B,AB,O',
            'allergy_notes' => 'nullable|string|max:500',
            // Data IGD.
            'guarantor_type'  => 'required|string|max:20',
            'insurer_id'      => 'nullable|uuid',
            'chief_complaint' => 'nullable|string|max:500',
            'triage_color'    => 'nullable|in:MERAH,KUNING,HIJAU,HITAM',
            'triage_level'    => 'nullable|in:1,2,3,4,5',
            'arrival_mode'    => 'nullable|in:KELUARGA,SENDIRI,POLISI,LAINNYA',
        ]);

        try {
            $visit = $this->service->registerNewPatient($data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($visit, 'Pasien baru terdaftar di IGD', 201);
    }

    /** POST /igd/{visitId}/triase — set/ubah triase berlevel + vital. */
    public function triase(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            // Triase ATS: kategori 1..5 (RM 3.7) ATAU warna (kompat lama). Salah satu wajib.
            'triage_level'    => 'nullable|in:1,2,3,4,5',
            'triage_color'    => 'nullable|in:MERAH,KUNING,HIJAU,HITAM',
            'chief_complaint' => 'nullable|string|max:500',
            'arrival_mode'    => 'nullable|in:KELUARGA,SENDIRI,POLISI,LAINNYA',
            'td_sistol'       => 'nullable|integer',
            'td_diastol'      => 'nullable|integer',
            'nadi'            => 'nullable|integer',
            'suhu'            => 'nullable|numeric',
            'respirasi'       => 'nullable|integer',
            'spo2'            => 'nullable|numeric',
            'gcs_e'           => 'nullable|integer',
            'gcs_v'           => 'nullable|integer',
            'gcs_m'           => 'nullable|integer',
            'keadaan_umum'    => 'nullable|in:BAIK,SEDANG,LEMAH,BURUK',
            'kesadaran'       => 'nullable|in:CM,SOMNOLEN,KOMA',
            'akral'           => 'nullable|string|max:50',
            'reflex_cahaya'   => 'nullable|string|max:50',
            'pain_score'      => 'nullable|integer|min:0|max:10',
            'pain_scale_type' => 'nullable|in:NRS,WONG_BAKER,FLACC',
            'pain_location'   => 'nullable|string|max:150',
            'pain_detail'     => 'nullable|array',
        ]);

        if (empty($data['triage_level']) && empty($data['triage_color'])) {
            return $this->error('Kategori triase (ATS 1–5) wajib dipilih.', 422);
        }

        try {
            $visit  = Visit::findOrFail($visitId);
            $record = $this->service->triase($visit, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Triase disimpan');
    }

    /** GET /igd/{visitId}/tarif-tindakan — picker tindakan + harga. */
    public function tarifTindakan(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->tarifTindakan($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** GET /igd/{visitId}/daftar-obat — picker obat + harga. */
    public function daftarObat(string $visitId, Request $request): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->daftarObat($visit, $request->query('search')));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /igd/{visitId}/tindakan — harga resolve otomatis. */
    public function addTindakan(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'procedure_id' => 'required|uuid',
            'quantity'     => 'nullable|numeric|min:0.01',
        ]);

        try {
            $visit  = Visit::findOrFail($visitId);
            $charge = $this->service->addTindakan($visit, $data['procedure_id'], $data['quantity'] ?? 1);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($charge, 'Tindakan dicatat');
    }

    /** POST /igd/{visitId}/obat — harga resolve otomatis. */
    public function addObat(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'medication_id' => 'required|uuid',
            'quantity'      => 'nullable|numeric|min:0.01',
        ]);

        try {
            $visit  = Visit::findOrFail($visitId);
            $charge = $this->service->addObat($visit, $data['medication_id'], $data['quantity'] ?? 1);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($charge, 'Obat dicatat');
    }

    /** DELETE /igd/{visitId}/charge/{chargeId} */
    public function deleteCharge(string $visitId, string $chargeId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            $this->service->deleteCharge($visit, $chargeId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Biaya dihapus');
    }

    /**
     * POST /igd/{visitId}/disposisi — keputusan akhir IGD
     * (PULANG/RANAP/RUJUK/MENINGGAL/BEDAH/RAJAL).
     *   BEDAH → operasi cito (paket+operator+lokasi, jadwal hari ini).
     *   RAJAL → rujuk poliklinik (poli tujuan + tanggal kunjungan).
     */
    public function disposisi(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'disposition' => 'required|in:PULANG,RANAP,RUJUK,MENINGGAL,BEDAH,RAJAL',
            'notes'       => 'nullable|string|max:1000',
            // BEDAH (operasi cito) — operator/anestesi opsional (boleh ditetapkan di papan Bedah).
            'surgery_package_id'        => 'nullable|uuid|exists:surgery_packages,id',
            'surgery_package_tariff_id' => 'nullable|uuid',
            'lead_surgeon_id'           => 'nullable|uuid|exists:employees,id',
            'anesthesiologist_id'       => 'nullable|uuid|exists:employees,id',
            'location_type'             => 'nullable|in:RUANG_BEDAH,RUANG_TINDAKAN',
            'scheduled_time'            => 'nullable|string|max:8',
            'operation_room'            => 'nullable|string|max:100',
            // RAJAL (rujuk poliklinik).
            'target_doctor_schedule_id' => 'nullable|uuid|exists:doctor_schedules,id',
            'scheduled_date'            => 'nullable|date',
        ]);

        // Aturan bersyarat: BEDAH (ruang bedah) wajib paket; RAJAL wajib poli tujuan.
        if ($data['disposition'] === 'BEDAH'
            && ($data['location_type'] ?? 'RUANG_BEDAH') !== 'RUANG_TINDAKAN'
            && empty($data['surgery_package_id'])) {
            return $this->error('Paket bedah wajib dipilih untuk operasi (Ruang Bedah).', 422);
        }
        if ($data['disposition'] === 'RAJAL' && empty($data['target_doctor_schedule_id'])) {
            return $this->error('Poli/dokter tujuan wajib dipilih untuk rujukan rawat jalan.', 422);
        }

        try {
            $visit  = Visit::findOrFail($visitId);
            $result = $this->service->disposisi($visit, $data['disposition'], $data['notes'] ?? null, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Disposisi IGD diproses');
    }

    /** GET /igd/{visitId}/bedah-options — paket bedah + operator + anestesiologis (modal disposisi BEDAH). */
    public function bedahOptions(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->bedahOptions($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** GET /igd/{visitId}/rajal-targets — jadwal poli tujuan (modal disposisi RAJAL). */
    public function rajalTargets(string $visitId): JsonResponse
    {
        try {
            Visit::findOrFail($visitId);
            return $this->ok($this->service->rajalTargets());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    // =========================================================================
    // CPPT (delegasi ke mesin RANAP via IgdService)
    // =========================================================================

    /** GET /igd/{visitId}/cppt — daftar CPPT pasien IGD. */
    public function indexCppt(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->cpptEntries($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /igd/{visitId}/cppt — tambah CPPT terintegrasi (SOAP + TTV). */
    public function addCppt(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'notes'      => 'nullable|string',
            'soap_s'     => 'nullable|string',
            'soap_o'     => 'nullable|string',
            'soap_a'     => 'nullable|string',
            'soap_p'     => 'nullable|string',
            'instruksi'  => 'nullable|string',
            'td_sistol'  => 'nullable|integer',
            'td_diastol' => 'nullable|integer',
            'nadi'       => 'nullable|integer',
            'suhu'       => 'nullable|numeric',
            'respirasi'  => 'nullable|integer',
            'spo2'       => 'nullable|numeric',
            'kgd'        => 'nullable|numeric',
            'pain_scale' => 'nullable|integer',
            'visus_od'   => 'nullable|string|max:20',
            'visus_os'   => 'nullable|string|max:20',
            'iop_od'     => 'nullable|numeric',
            'iop_os'     => 'nullable|numeric',
            'iop_method' => 'nullable|string|max:50',
        ]);

        if (! array_filter([
            $data['notes'] ?? null, $data['soap_s'] ?? null, $data['soap_o'] ?? null,
            $data['soap_a'] ?? null, $data['soap_p'] ?? null, $data['instruksi'] ?? null,
        ])) {
            return $this->error('Isi CPPT (SOAP atau catatan) wajib diisi.', 422);
        }

        try {
            $visit = Visit::findOrFail($visitId);
            $entry = $this->service->addCppt($visit, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($entry, 'CPPT dicatat');
    }

    /** PUT /igd/cppt/{id} — soft-edit CPPT. */
    public function updateCppt(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'notes'      => 'nullable|string',
            'soap_s'     => 'nullable|string',
            'soap_o'     => 'nullable|string',
            'soap_a'     => 'nullable|string',
            'soap_p'     => 'nullable|string',
            'instruksi'  => 'nullable|string',
            'td_sistol'  => 'nullable|integer',
            'td_diastol' => 'nullable|integer',
            'nadi'       => 'nullable|integer',
            'suhu'       => 'nullable|numeric',
            'respirasi'  => 'nullable|integer',
            'spo2'       => 'nullable|numeric',
            'kgd'        => 'nullable|numeric',
            'pain_scale' => 'nullable|integer',
            'visus_od'   => 'nullable|string|max:20',
            'visus_os'   => 'nullable|string|max:20',
            'iop_od'     => 'nullable|numeric',
            'iop_os'     => 'nullable|numeric',
            'iop_method' => 'nullable|string|max:50',
        ]);

        try {
            $entry = $this->service->updateCppt($id, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($entry, 'CPPT diperbarui');
    }

    /** POST /igd/cppt/{id}/verify — verifikasi DPJP atas entri CPPT. */
    public function verifyCppt(string $id): JsonResponse
    {
        try {
            $entry = $this->service->verifyCppt($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($entry, 'CPPT diverifikasi');
    }

    // =========================================================================
    // SEP IGD (BPJS gawat darurat) — terbit terpisah setelah diagnosa awal.
    // =========================================================================

    /** GET /igd/{visitId}/sep — info pra-SEP (status, no kartu, dll). */
    public function sepInfo(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::with('patient:id,bpjs_number')->findOrFail($visitId);
            return $this->ok($this->service->sepInfo($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /igd/{visitId}/sep — terbitkan SEP gawat darurat (diagnosa wajib). */
    public function generateSep(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'diag_awal'   => 'required|string|max:10',   // ICD-10 diagnosa awal
            'bpjs_number' => 'nullable|string|max:20',
            'kode_dpjp'   => 'nullable|string|max:20',
            'no_rujukan'  => 'nullable|string|max:30',
        ]);

        try {
            $visit  = Visit::with('patient')->findOrFail($visitId);
            $result = $this->service->generateSep($visit, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'SEP IGD diterbitkan');
    }

    // =========================================================================
    // RM 3.7 — ASESMEN / PENGKAJIAN GAWAT DARURAT
    // =========================================================================

    /** GET /igd/{visitId}/assessment — asesmen RM 3.7 + triase (prefill panel). */
    public function getAssessment(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::with(['patient', 'igdTriageRecord'])->findOrFail($visitId);
            return $this->ok($this->service->getAssessment($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** PUT /igd/{visitId}/assessment — autosave draft asesmen RM 3.7. */
    public function saveAssessment(string $visitId, Request $request): JsonResponse
    {
        $data = $this->validateAssessment($request);
        try {
            $visit = Visit::findOrFail($visitId);
            $assessment = $this->service->saveAssessment($visit, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($assessment, 'Asesmen disimpan');
    }

    /** POST /igd/{visitId}/assessment/finalize — finalisasi + terbitkan dokumen RM 3.7 (siap TTD). */
    public function finalizeAssessment(string $visitId, Request $request): JsonResponse
    {
        $data = $this->validateAssessment($request);
        try {
            $visit = Visit::with('patient')->findOrFail($visitId);
            $result = $this->service->finalizeAssessment($visit, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($result, 'Asesmen difinalisasi — silakan tanda tangan');
    }

    /** Validasi payload asesmen RM 3.7 (blok JSONB longgar + skalar terbatas). */
    private function validateAssessment(Request $request): array
    {
        return $request->validate([
            'anamnesa'            => 'nullable|array',
            'psikososial'         => 'nullable|array',
            'perilaku'            => 'nullable|array',
            'fisik'               => 'nullable|array',
            'mata_od_os'          => 'nullable|array',
            'penunjang'           => 'nullable|array',
            'planning'            => 'nullable|array',
            'diagnosa_kerja'      => 'nullable|string|max:255',
            'diagnosa_kerja_name' => 'nullable|string|max:255',
            'diagnosa_banding'    => 'nullable|string',
            'keadaan_pulang'      => 'nullable|in:BAIK,SEDANG,BURUK,PERDARAHAN,KOMA,MENINGGAL',
            'perawatan_lanjutan'  => 'nullable|in:RAWAT_JALAN,RAWAT_INAP,RAWAT_INTENSIF,DIRUJUK',
            'waktu_keluar'        => 'nullable|date',
        ]);
    }

    // =========================================================================
    // SELF-CHECKOUT IGD (hari libur / kasir tidak bertugas)
    // =========================================================================

    /** GET /igd/{visitId}/billing-preview — ringkasan tagihan IGD (konsolidasi lazily). */
    public function billingPreview(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::with('patient')->findOrFail($visitId);
            return $this->ok($this->service->billingPreview($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    /** POST /igd/{visitId}/self-checkout — disposisi + bayar + kwitansi (reuse KasirService). */
    public function selfCheckout(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'disposition'    => 'required|in:PULANG,RUJUK',
            'notes'          => 'nullable|string|max:1000',
            'payment_method' => 'nullable|in:CASH,CREDIT_CARD,TRANSFER',
            'paid_amount'    => 'nullable|numeric|min:0',
            'cash_received'  => 'nullable|numeric|min:0',
        ]);

        try {
            $visit  = Visit::with('patient')->findOrFail($visitId);
            $result = $this->service->selfCheckout($visit, $data['disposition'], $data['notes'] ?? null, [
                'payment_method' => $data['payment_method'] ?? 'CASH',
                'paid_amount'    => $data['paid_amount'] ?? null,
                'cash_received'  => $data['cash_received'] ?? null,
                'notes'          => $data['notes'] ?? null,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Pembayaran IGD tercatat & kwitansi siap');
    }

    // =========================================================================
    // HELPERS (base Controller kosong — wajib dideklarasi per-controller)
    // =========================================================================

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
