<?php

namespace App\Http\Controllers;

use App\Services\FormRegistry\FormRegistryService;
use App\Services\FormRegistry\SignatureService;
use App\Services\RekamMedisService;
use App\Services\RmeAggregatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RekamMedisController extends Controller
{
    public function __construct(
        private readonly RekamMedisService $service,
        private readonly FormRegistryService $formRegistry,
        private readonly SignatureService $signatures,
        private readonly RmeAggregatorService $rme,
    ) {}

    // =========================================================================
    // PASIEN
    // =========================================================================

    /**
     * GET /rekam-medis/pasien?keyword=&mode=
     * Pencarian pasien untuk modul RME (mode: nama | rm | nik).
     */
    public function cariPasien(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => 'required|string|min:1',
            'mode'    => 'nullable|in:nama,rm,nik',
        ]);

        return $this->ok($this->service->searchPatient($validated['keyword'], $validated['mode'] ?? null));
    }

    /**
     * GET /rekam-medis/pasien/{patientId}
     * Full clinical timeline for a patient.
     */
    public function riwayatPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->service->getVisitHistory($patientId));
    }

    /**
     * GET /rekam-medis/pasien/{patientId}/kunjungan
     * Riwayat kunjungan diperkaya untuk tabel RME (1 baris = 1 kunjungan).
     */
    public function indexKunjungan(string $patientId): JsonResponse
    {
        return $this->ok($this->rme->kunjungan($patientId));
    }

    /** GET /rekam-medis/pasien/{patientId}/ringkasan */
    public function ringkasanPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->rme->ringkasan($patientId));
    }

    /** GET /rekam-medis/pasien/{patientId}/refraksi */
    public function refraksiPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->rme->refraksi($patientId));
    }

    /** GET /rekam-medis/pasien/{patientId}/penunjang */
    public function penunjangPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->rme->penunjang($patientId));
    }

    /**
     * GET /rekam-medis/pasien/{patientId}/cppt
     * CPPT lintas-episode (RAJAL/IGD/RANAP) + SOAP dokter poli — 1 timeline.
     */
    public function cpptPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->rme->cppt($patientId));
    }

    /** GET /rekam-medis/pasien/{patientId}/obat */
    public function obatPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->rme->obat($patientId));
    }

    /** GET /rekam-medis/pasien/{patientId}/bedah */
    public function bedahPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->rme->bedah($patientId));
    }

    /** GET /rekam-medis/pasien/{patientId}/diagnosis */
    public function diagnosisPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->rme->diagnosis($patientId));
    }

    /**
     * GET /rekam-medis/pasien/{patientId}/dokumen
     * Daftar dokumen RM pasien (tab Dokumen).
     */
    public function dokumenPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->service->indexDokumen(['patient_id' => $patientId, 'per_page' => 100]));
    }

    // =========================================================================
    // DOKUMEN
    // =========================================================================

    /**
     * GET /rekam-medis/dokumen
     * Query: patient_id, visit_id, status, station, per_page
     */
    public function indexDokumen(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexDokumen(
            $request->only(['patient_id', 'visit_id', 'status', 'station', 'per_page'])
        ));
    }

    /** GET /rekam-medis/dokumen/{id} */
    public function showDokumen(string $id): JsonResponse
    {
        return $this->ok($this->service->showDokumen($id));
    }

    /**
     * POST /rekam-medis/dokumen
     * Create new patient document (DRAFT).
     * Body: { patient_id, visit_id?, document_type_id, created_by_station }
     */
    public function storeDokumen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id'         => 'required|uuid|exists:patients,id',
            'visit_id'           => 'nullable|uuid|exists:visits,id',
            'document_type_id'   => 'required|uuid|exists:document_types,id',
            'created_by_station' => 'required|in:ADMISI,TRIASE,REFRAKSIONIS,DOKTER,PENUNJANG,BEDAH,FARMASI,KASIR',
        ]);

        try {
            $document = $this->service->storeDokumen($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen dibuat', 201);
    }

    /** PUT /rekam-medis/dokumen/{id} */
    public function updateDokumen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'document_type_id'   => 'sometimes|uuid|exists:document_types,id',
            'created_by_station' => 'sometimes|in:ADMISI,TRIASE,REFRAKSIONIS,DOKTER,PENUNJANG,BEDAH,FARMASI,KASIR',
        ]);

        try {
            $document = $this->service->updateDokumen($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen diperbarui');
    }

    /**
     * POST /rekam-medis/dokumen/{id}/submit
     * DRAFT → WAITING_SIGNATURE.
     * Derives pending_signature_roles from document_type.required_signatures.
     * Creates Notification for each signer.
     */
    public function submitDokumen(string $id): JsonResponse
    {
        try {
            $document = $this->service->submitDokumen($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen disubmit. Notifikasi TTD telah dikirim.');
    }

    /**
     * POST /rekam-medis/dokumen/{id}/void
     * Body: { alasan }
     * Admin-only: invalidate document + QR Code.
     */
    public function voidDokumen(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'alasan' => 'required|string|min:5|max:500',
        ]);

        try {
            $document = $this->service->voidDokumen($id, $request->alasan);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen di-void. QR Code dinonaktifkan.');
    }

    /**
     * GET /rekam-medis/dokumen/{id}/cetak
     * Returns structured data for PDF rendering via Puppeteer.
     * Increments printed_count.
     */
    public function cetakDokumen(string $id): JsonResponse
    {
        try {
            $pdfData = $this->service->generatePdf($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($pdfData, 'Data PDF siap cetak');
    }

    /**
     * POST /rekam-medis/dokumen/{id}/resend-notif
     * Resend signature request notification.
     */
    public function resendNotifDokumen(string $id): JsonResponse
    {
        try {
            $this->service->resendNotifDokumen($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Notifikasi TTD dikirim ulang');
    }

    // =========================================================================
    // QR VERIFICATION
    // =========================================================================

    /**
     * GET /rekam-medis/verifikasi/{token}
     * Public scan endpoint — verify document by QR token.
     * Tracks scan_count for analytics.
     */
    public function verifikasiDokumen(string $token): JsonResponse
    {
        $result = $this->service->verifyDocument($token);

        $status = $result['valid'] ? 200 : 404;

        return response()->json([
            'success' => $result['valid'],
            'data'    => $result,
            'message' => $result['message'],
            'errors'  => null,
        ], $status);
    }

    // =========================================================================
    // MEDICAL RECORD (generic + versioning)
    // =========================================================================

    /** GET /rekam-medis/medical-record/{visitId} */
    public function showMedicalRecord(string $visitId): JsonResponse
    {
        return $this->ok($this->service->showMedicalRecord($visitId));
    }

    /** GET /rekam-medis/kunjungan/{visitId}/resume-medis — data cetak Resume Medis RM 1.7 */
    public function resumeMedis(string $visitId): JsonResponse
    {
        try {
            return $this->ok($this->service->resumeMedis($visitId));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    /**
     * GET /rekam-medis/kunjungan/{visitId}/kwitansi
     * Kwitansi kunjungan siap-tampil/cetak (HTML) untuk modul RME.
     */
    public function kwitansiKunjungan(string $visitId): JsonResponse
    {
        try {
            return $this->ok($this->service->kwitansiKunjungan($visitId));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    /**
     * POST /rekam-medis/medical-record
     * Body: { visit_id, patient_id, document_type_id?, form_data }
     */
    public function storeMedicalRecord(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'         => 'required|uuid|exists:visits,id',
            'patient_id'       => 'required|uuid|exists:patients,id',
            'document_type_id' => 'nullable|uuid|exists:document_types,id',
            'form_data'        => 'required|array',
        ]);

        try {
            $record = $this->service->storeMedicalRecord($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Rekam medis dibuat', 201);
    }

    /**
     * PUT /rekam-medis/medical-record/{id}
     * Saves version snapshot before updating.
     * Body: { form_data, change_reason? }
     */
    public function updateMedicalRecord(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'form_data'     => 'required|array',
            'change_reason' => 'nullable|string|max:255',
        ]);

        try {
            $record = $this->service->updateMedicalRecord($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Rekam medis diperbarui. Versi sebelumnya disimpan.');
    }

    /** GET /rekam-medis/medical-record/{id}/versions */
    public function versionsMedicalRecord(string $id): JsonResponse
    {
        return $this->ok($this->service->getVersionsMedicalRecord($id));
    }

    // =========================================================================
    // NOTIFICATIONS
    // =========================================================================

    /** GET /rekam-medis/notifikasi */
    public function indexNotifikasi(): JsonResponse
    {
        return $this->ok($this->service->indexNotifikasi());
    }

    /** PUT /rekam-medis/notifikasi/{id}/baca */
    public function bacaNotifikasi(string $id): JsonResponse
    {
        try {
            $notif = $this->service->bacaNotifikasi($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }

        return $this->ok($notif, 'Notifikasi ditandai dibaca');
    }

    // =========================================================================
    // FORM REGISTRY — Runtime (Fase 1)
    // =========================================================================

    /**
     * GET /rekam-medis/forms?station=X&section=Y&visit_id=Z
     * Daftar template aktif untuk (station, section, visit).
     */
    public function indexForms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station'  => 'required|string|max:50',
            'section'  => 'required|string|max:50',
            'visit_id' => 'required|uuid|exists:visits,id',
        ]);

        try {
            $forms = $this->formRegistry->listByStationSection(
                $validated['station'],
                $validated['section'],
                $validated['visit_id'],
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($forms);
    }

    /**
     * GET /rekam-medis/form/{code}/render?visit_id=Z
     * Render OUTPUT mode → HTML string (dry-run; tidak persist).
     */
    public function renderForm(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
        ]);

        try {
            $html = $this->formRegistry->render($code, $validated['visit_id']);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }

        return $this->ok([
            'code'     => $code,
            'visit_id' => $validated['visit_id'],
            'html'     => $html,
        ]);
    }

    /**
     * GET /rekam-medis/form/{code}/prefill?visit_id=Z
     * Nilai awal field editable (HYBRID/INPUT) dari data klinis yang sudah ada —
     * supaya dokter tidak mengisi ulang. Hanya field ber-konfigurasi `prefill`.
     */
    public function prefillForm(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
        ]);

        try {
            $result = $this->formRegistry->prefill($code, $validated['visit_id']);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }

        return $this->ok($result);
    }

    /**
     * POST /rekam-medis/form/{code}/submit
     * INPUT mode — submit data → router-by-binding ke tabel klinis +
     * create patient_document DRAFT untuk audit. Status FINALIZED terpisah.
     */
    public function submitForm(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
            'data'     => 'required|array',
        ]);

        try {
            $result = $this->formRegistry->submit($code, $validated['visit_id'], $validated['data']);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($result, 'Form INPUT tersimpan (status DRAFT). Finalisasi terpisah untuk lock dokumen.');
    }

    /**
     * POST /rekam-medis/document/{id}/finalize
     * Snapshot rendered_html ke patient_documents + lock immutable.
     */
    public function finalizeDocument(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'signature_ids'   => 'nullable|array',
            'signature_ids.*' => 'uuid',
        ]);

        try {
            $doc = $this->formRegistry->finalize($id, $validated['signature_ids'] ?? []);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($doc, 'Dokumen difinalisasi');
    }

    /**
     * GET /rekam-medis/document/{id}/render
     * Ambil snapshot rendered_html (BUKAN re-render dari template).
     */
    public function showDocumentSnapshot(string $id): JsonResponse
    {
        try {
            $snap = $this->formRegistry->getSnapshot($id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }

        return $this->ok($snap);
    }

    /**
     * PUT /rekam-medis/document/{id}/draft-content
     * Edit isi dokumen DRAFT (override teks HTML manual oleh dokter sebelum TTD).
     * Hanya status DRAFT; selain itu 422.
     */
    public function saveDraftContent(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'rendered_html' => 'required|string',
        ]);

        try {
            $doc = $this->formRegistry->saveDraftContent($id, $validated['rendered_html']);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($this->formRegistry->getSnapshot($doc->id), 'Isi dokumen disimpan');
    }

    /**
     * POST /rekam-medis/document/{id}/mark-rendered
     * Soft transition DRAFT → RENDERED (idempoten).
     */
    public function markDocumentRendered(string $id): JsonResponse
    {
        try {
            $doc = $this->formRegistry->markRendered($id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
        return $this->ok($doc, 'Status: ' . $doc->status);
    }

    /**
     * POST /rekam-medis/document/{id}/sign
     * Capture signature (append-only). Auto-advance status → PENDING_SIGNATURE.
     */
    public function signDocument(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'signer_type'              => 'required|in:patient,guardian,witness,doctor,doctor_anestesi,nurse,staff',
            'signer_user_id'           => 'nullable|uuid|exists:users,id',
            'signer_patient_id'        => 'nullable|uuid|exists:patients,id',
            'signer_external_identity' => 'nullable|array',
            'signer_external_identity.nama'      => 'nullable|string|max:255',
            'signer_external_identity.nik'       => 'nullable|string|max:20',
            'signer_external_identity.hubungan'  => 'nullable|string|max:100',
            'signature_svg'            => 'nullable|string',
            'signature_png_base64'     => 'nullable|string',
            'signature_pin'            => 'nullable|string|max:20',
            'biometric_metadata'       => 'nullable|array',
            'audit_log'                => 'nullable|array',
        ]);

        $validated['patient_document_id']             = $id;
        $validated['captured_device_info']            = [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
        $validated['captured_by_facilitator_user_id'] = auth('api')->id();

        // Nakes TTD via PIN: signer_user_id default ke user yang login bila kosong.
        if (in_array($validated['signer_type'], \App\Services\FormRegistry\SignatureService::INTERNAL_SIGNER_TYPES, true)
            && empty($validated['signer_user_id'])) {
            $validated['signer_user_id'] = auth('api')->id();
        }

        try {
            $sig = $this->signatures->capture($validated);
        } catch (\Throwable $e) {
            // Pertahankan 401 untuk PIN salah (SignatureService set code 401).
            $code = (int) $e->getCode();
            return $this->error($e->getMessage(), in_array($code, [401, 403, 404], true) ? $code : 422);
        }

        return $this->ok($sig, 'Signature ter-capture', 201);
    }

    /**
     * GET /rekam-medis/document/{id}/signatures
     * Daftar semua signature untuk dokumen ini.
     */
    public function listDocumentSignatures(string $id): JsonResponse
    {
        return $this->ok($this->signatures->listByDocument($id));
    }

    /**
     * GET /rekam-medis/signature/{signatureId}/verify
     * Re-hash dan bandingkan dengan stored integrity_hash.
     */
    public function verifySignature(string $signatureId): JsonResponse
    {
        try {
            $result = $this->signatures->verify($signatureId);
        } catch (\Throwable $e) {
            return $this->error('Signature tidak ditemukan.', 404);
        }
        return $this->ok($result);
    }

    /**
     * GET /rekam-medis/signature/{signatureId}/audit
     * Admin: lihat audit metadata lengkap (timestamps, device, biometric, audit_log timeline).
     */
    public function auditSignature(string $signatureId): JsonResponse
    {
        $sig = \App\Models\DocumentSignature::query()
            ->where('signature_id', $signatureId)
            ->firstOrFail();
        return $this->ok($sig);
    }

    /**
     * GET /rekam-medis/document/{id}/audit-log
     * Audit history per dokumen (Fase 6). Include event template_id terkait
     * + signature + addendum yang context-nya menyebut dokumen ini.
     */
    public function documentAuditLog(string $id): JsonResponse
    {
        $logs = \App\Services\FormRegistry\FormRegistryAudit::queryForDocument($id)
            ->with('user:id,name,email')
            ->limit(200)
            ->get();
        return $this->ok($logs);
    }

    /**
     * signer_type untuk antrean/TTD berdasarkan role user login.
     * Dokter anestesi (role dokter_anestesi) → 'doctor_anestesi' (antrean & slot TTD
     * anestesi terpisah); selain itu → 'doctor' (DPJP/operator, perilaku lama).
     */
    private function signerTypeForUser(): string
    {
        return auth('api')->user()?->role?->name === 'dokter_anestesi'
            ? 'doctor_anestesi'
            : 'doctor';
    }

    /**
     * GET /rekam-medis/ttd-queue
     * Antrian TTD dokter yang login — terfilter di SQL + pagination server-side.
     * Query: page, per_page (default 10, maks 100), search, status.
     */
    public function ttdQueue(Request $request): JsonResponse
    {
        $userId = auth('api')->id();
        $paginator = $this->signatures->ttdQueueForDoctor($userId, [
            'per_page' => (int) $request->query('per_page', 10),
            'search'   => $request->query('search'),
            'status'   => $request->query('status'),
        ], $this->signerTypeForUser());
        // Paginator di-serialize Laravel jadi {data, current_page, last_page, total, ...}
        // → FE baca rows di data.data.data, meta di data.data.{total,last_page,...}.
        return $this->ok($paginator);
    }

    /**
     * POST /rekam-medis/ttd-bulk-sign
     * Tandatangani banyak dokumen sekaligus sebagai dokter (1× PIN). Best-effort.
     * Body: { document_ids: uuid[1..100], signature_pin: string }.
     */
    public function ttdBulkSign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_ids'   => 'required|array|min:1|max:100',
            'document_ids.*' => 'uuid',
            'signature_pin'  => 'required|string|max:20',
        ]);

        $userId = auth('api')->id();
        try {
            $result = $this->signatures->bulkSignAsDoctor(
                $userId,
                $validated['document_ids'],
                $validated['signature_pin'],
                $this->signerTypeForUser(),
            );
        } catch (\Throwable $e) {
            // Pertahankan 401 untuk PIN salah (service set code 401).
            $code = (int) $e->getCode();
            return $this->error($e->getMessage(), in_array($code, [401, 403, 404], true) ? $code : 422);
        }

        return $this->ok($result, 'Bulk TTD selesai');
    }

    /**
     * GET /rekam-medis/ttd-count
     * Jumlah dokumen di antrian TTD dokter (untuk badge sidebar) + jumlah yang
     * sudah ditandatangani hari ini (untuk kartu statistik di halaman TTD).
     */
    public function ttdCount(): JsonResponse
    {
        $userId = auth('api')->id();
        $signerType = $this->signerTypeForUser();
        return $this->ok([
            'count'        => $this->signatures->ttdCountForDoctor($userId, $signerType),
            'signed_today' => $this->signatures->signedTodayCountForDoctor($userId, $signerType),
        ]);
    }

    /**
     * GET /rekam-medis/ttd-signed-today
     * Dokumen yang sudah ditandatangani dokter yang login HARI INI (paginated).
     * Query: page, per_page (default 10, maks 100), search.
     */
    public function ttdSignedToday(Request $request): JsonResponse
    {
        $userId = auth('api')->id();
        $paginator = $this->signatures->signedTodayForDoctor($userId, [
            'per_page' => (int) $request->query('per_page', 10),
            'search'   => $request->query('search'),
        ], $this->signerTypeForUser());
        return $this->ok($paginator);
    }

    /**
     * POST /rekam-medis/document/{id}/addendum
     * Koreksi post-FINALIZED. Addendum perlu di-TTD terpisah (Fase 5+).
     */
    public function createAddendum(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'alasan'       => 'required|string|max:500',
            'isi_koreksi'  => 'required|string',
        ]);

        try {
            $add = $this->formRegistry->createAddendum($id, $validated);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->ok($add, 'Addendum dibuat (perlu TTD lanjutan).', 201);
    }

    /**
     * POST /rekam-medis/document/{id}/revisi
     * Koreksi dokumen final via "generate ulang + TTD ulang": buat versi baru
     * (otomatis terkoreksi dari data terkini) → masuk antrian TTD; versi lama
     * jadi SUPERSEDED (riwayat).
     */
    public function reviseDocument(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'alasan' => 'required|string|max:500',
        ]);

        try {
            $new = $this->formRegistry->reviseDocument($id, $validated['alasan']);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->ok([
            'document_id' => $new->id,
            'revision'    => $new->revision,
            'status'      => $new->status,
        ], 'Dokumen versi baru dibuat — menunggu tanda tangan ulang.', 201);
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
