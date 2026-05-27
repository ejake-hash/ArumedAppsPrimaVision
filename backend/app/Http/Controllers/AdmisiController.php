<?php

namespace App\Http\Controllers;

use App\Models\DoctorSchedule;
use App\Services\AdmisiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdmisiController extends Controller
{
    public function __construct(private readonly AdmisiService $service) {}

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    public function dashboard(): JsonResponse
    {
        return $this->ok($this->service->getDashboard());
    }

    // =========================================================================
    // KUNJUNGAN
    // =========================================================================

    /**
     * GET /admisi/kunjungan
     * Query params: tanggal, station, guarantor_type, classification, search, per_page
     */
    public function indexKunjungan(Request $request): JsonResponse
    {
        $data = $this->service->getKunjungan($request->only([
            'tanggal', 'station', 'guarantor_type', 'classification', 'search', 'per_page', 'page',
        ]));

        return $this->ok($data);
    }

    public function showKunjungan(string $id): JsonResponse
    {
        return $this->ok($this->service->getKunjunganById($id));
    }

    public function cancelKunjungan(string $id): JsonResponse
    {
        try {
            $this->service->cancelKunjungan($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Kunjungan tidak ditemukan atau sudah dibatalkan sebelumnya.', 404);
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            $http = ($code >= 100 && $code <= 599) ? $code : 500;
            logger()->error('cancelKunjungan failed', [
                'visit_id' => $id,
                'message'  => $e->getMessage(),
                'code'     => $e->getCode(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return $this->error($e->getMessage(), $http);
        }

        return $this->ok(null, 'Kunjungan berhasil dibatalkan');
    }

    // =========================================================================
    // PASIEN
    // =========================================================================

    /**
     * GET /admisi/pasien?keyword=
     * Search by NIK, BPJS number, no_rm, or name.
     */
    public function cariPasien(Request $request): JsonResponse
    {
        $request->validate(['keyword' => 'required|string|min:1']);

        return $this->ok($this->service->cariPasien($request->keyword));
    }

    /**
     * POST /admisi/pasien — create patient record only (tanpa daftar kunjungan)
     */
    public function storePasien(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identity_type' => 'nullable|in:KTP,PASPOR,SIM,KIA,TANPA_IDENTITAS,LAINNYA',
            'nik'          => $this->nikRules($request, required: true),
            'name'         => 'required|string|max:255',
            'gender'       => 'required|in:L,P',
            'date_of_birth' => 'required|date|before:today',
            'phone'        => 'nullable|string|max:20',
            'address'      => 'nullable|string|max:500',
            'province'     => 'nullable|string|max:100',
            'bpjs_number'  => 'nullable|string|max:20|unique:patients,bpjs_number',
            'blood_type'   => 'nullable|in:A,B,AB,O',
            'allergy_notes' => 'nullable|string|max:500',
            'photo'        => 'nullable|string',
        ]);

        $patient = $this->service->storePasien($validated);

        return $this->ok($patient, 'Pasien berhasil didaftarkan', 201);
    }

    /**
     * Aturan validasi NIK yang bergantung pada jenis identitas:
     *  - KTP        → wajib (kecuali patient_id sudah ada), tepat 16 digit
     *  - selain KTP → opsional, maks 50 char (paspor/SIM/KIA/dll)
     *  - Tanpa Identitas → boleh kosong
     * Selalu unique (NULL diabaikan otomatis oleh Laravel).
     *
     * @return array<int,string>
     */
    private function nikRules(Request $request, bool $required, ?string $ignoreId = null): array
    {
        $isKtp        = $request->input('identity_type', 'KTP') === 'KTP';
        $needRequired = $required && $isKtp && ! $request->filled('patient_id');

        $rules = [$needRequired ? 'required' : 'nullable', 'string', $isKtp ? 'size:16' : 'max:50'];

        $rules[] = $ignoreId
            ? 'unique:patients,nik,' . $ignoreId
            : 'unique:patients,nik';

        return $rules;
    }

    /**
     * Varian aturan NIK untuk walk-in: keunikan dicek manual di service
     * (terhadap placeholder), jadi tanpa rule `unique` di sini.
     *
     * @return array<int,string>
     */
    private function nikRulesWalkIn(Request $request): array
    {
        $isKtp        = $request->input('identity_type', 'KTP') === 'KTP';
        $needRequired = $isKtp && ! $request->filled('patient_id');

        return [$needRequired ? 'required' : 'nullable', 'string', $isKtp ? 'size:16' : 'max:50'];
    }

    public function showPasien(string $id): JsonResponse
    {
        return $this->ok($this->service->getPasienById($id));
    }

    /**
     * GET /admisi/pasien/{id}/kunjungan?page=&per_page=&tanggal=
     * Riwayat kunjungan pasien (paginated, filter tanggal) untuk tab Riwayat.
     */
    public function indexKunjunganPasien(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'tanggal'  => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page'     => 'nullable|integer|min:1',
        ]);

        return $this->ok($this->service->getKunjunganPasien($id, $validated));
    }

    public function updatePasien(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'identity_type' => 'nullable|in:KTP,PASPOR,SIM,KIA,TANPA_IDENTITAS,LAINNYA',
            'nik'          => $this->nikRules($request, required: false, ignoreId: $id),
            'name'         => 'sometimes|string|max:255',
            'gender'       => 'sometimes|in:L,P',
            'date_of_birth' => 'sometimes|date|before:today',
            'phone'        => 'nullable|string|max:20',
            'address'      => 'nullable|string|max:500',
            'province'     => 'nullable|string|max:100',
            'bpjs_number'  => 'nullable|string|max:20|unique:patients,bpjs_number,' . $id,
            'blood_type'   => 'nullable|in:A,B,AB,O',
            'allergy_notes' => 'nullable|string|max:500',
            'photo'        => 'nullable|string',
        ]);

        return $this->ok($this->service->updatePasien($id, $validated), 'Data pasien diperbarui');
    }

    // =========================================================================
    // DAFTAR KUNJUNGAN
    // =========================================================================

    /**
     * POST /admisi/daftar
     * Main flow: daftarkan kunjungan baru (buat pasien jika belum ada).
     */
    public function daftarKunjungan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Pasien yang sudah ada
            'patient_id'   => 'nullable|uuid|exists:patients,id',

            // Data pasien baru (wajib jika patient_id kosong)
            'identity_type' => 'nullable|in:KTP,PASPOR,SIM,KIA,TANPA_IDENTITAS,LAINNYA',
            'nik'          => $this->nikRules($request, required: true),
            'name'         => 'required_without:patient_id|string|max:255',
            'gender'       => 'required_without:patient_id|in:L,P',
            'date_of_birth' => 'required_without:patient_id|date|before:today',
            'phone'        => 'nullable|string|max:20',
            'address'      => 'nullable|string|max:500',
            'province'     => 'nullable|string|max:100',
            'bpjs_number'  => 'nullable|string|max:20',
            'blood_type'   => 'nullable|in:A,B,AB,O',
            'allergy_notes' => 'nullable|string|max:500',
            'photo'        => 'nullable|string',

            // Data kunjungan
            'classification'     => 'required|in:Baru,Pre-Op,Post-Op,Kontrol',
            'guarantor_type'     => 'required|in:UMUM,BPJS,ASURANSI,PERUSAHAAN,SOSIAL',
            'insurer_id'         => 'nullable|uuid|exists:insurers,id',
            'bpjs_booking_code'  => 'nullable|string|max:50',
            // Wajib pilih dokter saat admisi
            'doctor_schedule_id' => 'required|uuid|exists:doctor_schedules,id',
        ]);

        // insurer_id wajib jika penjamin selain UMUM & BPJS
        if (
            in_array($validated['guarantor_type'], ['ASURANSI', 'PERUSAHAAN', 'SOSIAL'])
            && empty($validated['insurer_id'])
        ) {
            return $this->validationError(['insurer_id' => ['insurer_id wajib diisi untuk penjamin ini.']]);
        }

        try {
            $visit = $this->service->registerVisit($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }

        return $this->ok($visit, 'Kunjungan berhasil didaftarkan', 201);
    }

    // =========================================================================
    // ANJUNGAN MANDIRI (Kiosk — Public, No Auth)
    // =========================================================================

    /**
     * POST /anjungan/tiket-umum
     * Kiosk self-service untuk pasien Umum / Pasien Baru:
     * - Buat Patient placeholder (anonymous walk-in)
     * - Buat Visit (UMUM, Baru, station=ADMISI)
     * - Buat Queue ADMISI
     * - Broadcast AdmisiQueueUpdated (added) → AdmisiView auto-refresh via WS
     */
    public function anjunganTiketUmum(): JsonResponse
    {
        try {
            $result = $this->service->ambilTiketUmumKiosk();
        } catch (\Throwable $e) {
            // $e->getCode() bisa berisi SQLSTATE (mis. "23505") yang bukan HTTP status valid
            $code = (int) $e->getCode();
            $http = ($code >= 100 && $code <= 599) ? $code : 500;

            logger()->error('anjunganTiketUmum failed', [
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $this->error('Gagal menerbitkan tiket: ' . $e->getMessage(), $http);
        }

        return $this->ok($result, 'Tiket umum diterbitkan', 201);
    }

    // =========================================================================
    // DAFTARKAN WALK-IN
    // =========================================================================

    /**
     * PUT /admisi/kunjungan/{visitId}/daftarkan-walkin
     * Merge data registrasi ke Visit walk-in yang dibuat dari kiosk Anjungan.
     */
    public function daftarkanWalkIn(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            // Pasien lama
            'patient_id'   => 'nullable|uuid|exists:patients,id',

            // Pasien baru (wajib jika patient_id kosong) — NIK unique dicek di service
            // (kecuali placeholder sendiri)
            'identity_type' => 'nullable|in:KTP,PASPOR,SIM,KIA,TANPA_IDENTITAS,LAINNYA',
            'nik'          => $this->nikRulesWalkIn($request),
            'name'         => 'required_without:patient_id|string|max:255',
            'gender'       => 'required_without:patient_id|in:L,P',
            'date_of_birth' => 'required_without:patient_id|date|before:today',
            'phone'        => 'nullable|string|max:20',
            'address'      => 'nullable|string|max:500',
            'province'     => 'nullable|string|max:100',
            'bpjs_number'  => 'nullable|string|max:20',
            'blood_type'   => 'nullable|in:A,B,AB,O',
            'allergy_notes' => 'nullable|string|max:500',
            'photo'        => 'nullable|string',

            // Data kunjungan
            'classification'     => 'required|in:Baru,Pre-Op,Post-Op,Kontrol',
            'guarantor_type'     => 'required|in:UMUM,BPJS,ASURANSI,PERUSAHAAN,SOSIAL',
            'insurer_id'         => 'nullable|uuid|exists:insurers,id',
            'bpjs_booking_code'  => 'nullable|string|max:50',
            // Wajib pilih dokter saat admisi
            'doctor_schedule_id' => 'required|uuid|exists:doctor_schedules,id',
        ]);

        if (
            in_array($validated['guarantor_type'], ['ASURANSI', 'PERUSAHAAN', 'SOSIAL'])
            && empty($validated['insurer_id'])
        ) {
            return $this->validationError(['insurer_id' => ['insurer_id wajib diisi untuk penjamin ini.']]);
        }

        try {
            $visit = $this->service->daftarkanWalkIn($visitId, $validated);
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            $http = ($code >= 100 && $code <= 599) ? $code : 500;
            return $this->error($e->getMessage(), $http);
        }

        return $this->ok($visit, 'Walk-in berhasil didaftarkan');
    }

    // =========================================================================
    // ANTRIAN ADMISI
    // =========================================================================

    public function indexAntrian(): JsonResponse
    {
        return $this->ok($this->service->getAntrian());
    }

    /** POST /admisi/antrian — buat antrian manual (jika perlu) */
    public function createAntrian(Request $request): JsonResponse
    {
        $request->validate(['visit_id' => 'required|uuid|exists:visits,id']);

        try {
            $queue = $this->service->createAntrianAdmisi($request->visit_id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Antrian dibuat', 201);
    }

    public function panggilAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->panggilAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dipanggil');
    }

    /**
     * PUT /admisi/antrian/{id}/selesai
     * Selesaikan admisi → otomatis buat antrian TRIASE + REFRAKSIONIS.
     */
    public function selesaiAntrian(string $id): JsonResponse
    {
        try {
            $visit = $this->service->selesaiAdmisi($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($visit, 'Admisi selesai. Antrian TRIASE dan REFRAKSIONIS dibuat.');
    }

    // =========================================================================
    // JADWAL DOKTER
    // =========================================================================

    public function indexJadwalDokter(): JsonResponse
    {
        return $this->ok($this->service->getDoctorSchedules());
    }

    public function storeJadwalDokter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|uuid|exists:employees,id',
            'day_of_week' => 'required|integer|between:1,7',
            'start_time'  => 'required|date_format:H:i',
            'end_time'    => 'required|date_format:H:i|after:start_time',
            'room'        => 'nullable|string|max:100',
            'is_active'   => 'boolean',
        ]);

        $schedule = $this->service->createDoctorSchedule($validated['employee_id'], $validated);

        return $this->ok($schedule, 'Jadwal ditambahkan', 201);
    }

    public function updateJadwalDokter(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'start_time' => 'sometimes|date_format:H:i',
            'end_time'   => 'sometimes|date_format:H:i',
            'room'       => 'nullable|string|max:100',
            'is_active'  => 'boolean',
        ]);

        try {
            $schedule = $this->service->updateDoctorSchedule($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 404);
        }

        return $this->ok($schedule, 'Jadwal diperbarui');
    }

    public function destroyJadwalDokter(string $id): JsonResponse
    {
        DoctorSchedule::findOrFail($id)->delete();

        return $this->ok(null, 'Jadwal dihapus');
    }

    // =========================================================================
    // BPJS
    // =========================================================================

    public function bpjsCekPeserta(Request $request): JsonResponse
    {
        $request->validate([
            'nik'         => 'nullable|string|size:16',
            'bpjs_number' => 'nullable|string|max:20',
        ]);

        // Minimal salah satu
        if (empty($request->nik) && empty($request->bpjs_number)) {
            return $this->validationError(['nik' => ['NIK atau nomor BPJS wajib diisi.']]);
        }

        try {
            $data = $this->service->bpjsCekPeserta($request->only(['nik', 'bpjs_number']));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($data, 'Data peserta BPJS');
    }

    public function bpjsGenerateSep(Request $request): JsonResponse
    {
        $request->validate([
            'visit_id'    => 'required|uuid|exists:visits,id',
            'bpjs_number' => 'required|string|max:20',
            'no_rujukan'  => 'nullable|string|max:50',
        ]);

        try {
            $data = $this->service->bpjsGenerateSep($request->all());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($data, 'SEP berhasil digenerate');
    }

    public function bpjsCancelSep(Request $request): JsonResponse
    {
        $request->validate([
            'no_sep' => 'required|string|max:50',
            'alasan' => 'required|string|max:255',
        ]);

        try {
            $data = $this->service->bpjsCancelSep($request->all());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($data, 'SEP berhasil dibatalkan');
    }

    public function bpjsCekRujukan(Request $request): JsonResponse
    {
        $request->validate(['no_rujukan' => 'required|string|max:50']);

        try {
            $data = $this->service->bpjsCekRujukan($request->all());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($data, 'Data rujukan BPJS');
    }

    public function bpjsCekSuratKontrol(Request $request): JsonResponse
    {
        $request->validate(['no_surat_kontrol' => 'required|string|max:50']);

        try {
            $data = $this->service->bpjsCekSuratKontrol($request->all());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($data, 'Data surat kontrol BPJS');
    }

    public function bpjsValidasiBooking(Request $request): JsonResponse
    {
        $request->validate(['booking_code' => 'required|string|max:50']);

        try {
            $data = $this->service->bpjsValidasiBooking($request->all());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($data, 'Kode booking valid');
    }

    // =========================================================================
    // RESPONSE HELPERS
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

    private function error(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => 'Validasi gagal',
            'errors'  => $errors,
        ], 422);
    }
}
