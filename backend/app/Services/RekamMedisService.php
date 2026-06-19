<?php

namespace App\Services;

use App\Models\ClinicProfile;
use App\Models\DocumentNumberConfig;
use App\Models\DocumentType;
use App\Models\DocumentVerification;
use App\Models\MedicalRecord;
use App\Models\MedicalRecordVersion;
use App\Models\MedicalResume;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\SystemLog;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RekamMedisService
{
    public function __construct(private readonly Request $request) {}

    // =========================================================================
    // PASIEN — search & history
    // =========================================================================

    /**
     * Cari pasien untuk modul RME.
     * $mode: 'nama' | 'rm' | 'nik' | 'id' (default: semua field).
     * Mengembalikan bentuk siap-pakai untuk RekamMedisView (termasuk foto,
     * jumlah kunjungan, dan penjamin terakhir).
     */
    public function searchPatient(string $keyword, ?string $mode = null): \Illuminate\Support\Collection
    {
        $kw = trim($keyword);

        $query = Patient::active()
            // Sembunyikan placeholder anjungan/walk-in belum-terdaftar (no_rm NULL,
            // name 'Belum Terdaftar') dari hasil pencarian RME.
            ->whereNotNull('no_rm')
            ->where('name', '!=', 'Belum Terdaftar')
            ->withCount('visits')
            ->with(['visits' => fn ($q) => $q->orderByDesc('visit_date')->limit(1)]);

        if ($mode === 'id') {
            // Buka-langsung dari modul lain (mis. tombol "Buka Rekam Medis" di Admisi)
            // yang membawa patient_id — kecocokan persis, bukan ilike.
            $query->whereKey($kw);
        } elseif ($mode === 'rm') {
            $query->where('no_rm', 'ilike', "%{$kw}%");
        } elseif ($mode === 'nik') {
            $query->where('nik', 'like', "%{$kw}%");
        } elseif ($mode === 'nama') {
            $query->where('name', 'ilike', "%{$kw}%");
        } else {
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$kw}%")
                ->orWhere('no_rm', 'ilike', "%{$kw}%")
                ->orWhere('nik', 'like', "%{$kw}%")
                ->orWhere('bpjs_number', 'like', "%{$kw}%")
            );
        }

        return $query
            ->limit(15)
            ->get()
            ->map(fn (Patient $p) => [
                'id'                  => $p->id,
                'no_rm'               => $p->no_rm,
                'nama'                => $p->name,
                'nik'                 => $p->nik,
                'identity_type'       => $p->identity_type,
                'date_of_birth'       => $p->date_of_birth?->toDateString(),
                'gender'              => $p->gender,
                'address'             => $p->address,
                'allergy'             => $p->allergy_notes,
                'last_guarantor_type' => $p->visits->first()?->guarantor_type,
                'visit_count'         => $p->visits_count,
                'photo_url'           => $p->photo_url,
            ]);
    }

    /**
     * Daftar pasien yang punya kunjungan pada satu tanggal / rentang tanggal.
     * Dipakai RekamMedisView untuk "telusuri per tanggal" (mirip Rekap Kunjungan
     * BPJS) — 1 baris = 1 pasien, dengan ringkasan kunjungan periode terpilih.
     * Berpaginasi server-side. Filter: tanggal | tanggal_from+tanggal_to + search.
     */
    public function patientsByDate(array $f): LengthAwarePaginator
    {
        $from   = $f['tanggal_from'] ?? $f['tanggal'] ?? null;
        $to     = $f['tanggal_to']   ?? $f['tanggal'] ?? null;
        $search = trim($f['search'] ?? '');
        $perPage = max(1, min(200, (int) ($f['per_page'] ?? 50)));

        // Filter rentang visit_date yang dipakai bersama oleh whereHas, withCount,
        // dan eager-load relasi visits agar konsisten.
        $inRange = function ($q) use ($from, $to) {
            if ($from) { $q->whereDate('visit_date', '>=', $from); }
            if ($to)   { $q->whereDate('visit_date', '<=', $to); }
        };

        $query = Patient::active()
            // Buang placeholder anjungan/walk-in yang BELUM didaftarkan admisi
            // (no_rm NULL, name 'Belum Terdaftar'): mereka belum punya rekam medis,
            // jadi tak boleh muncul di telusur RME. Selaras dgn AdmisiService.
            ->whereNotNull('no_rm')
            ->where('name', '!=', 'Belum Terdaftar')
            ->whereHas('visits', $inRange)
            ->withCount(['visits as period_visits_count' => $inRange])
            ->with(['visits' => function ($q) use ($inRange) {
                $inRange($q);
                $q->orderByDesc('visit_date')->orderByDesc('created_at')
                  ->with(['dpjp', 'doctorExamination.doctor', 'doctorSchedule.employee']);
            }]);

        if ($search !== '') {
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('no_rm', 'ilike', "%{$search}%")
                ->orWhere('nik', 'like', "%{$search}%")
            );
        }

        $paginator = $query->orderBy('name')->paginate($perPage);

        $paginator->getCollection()->transform(function (Patient $p) {
            $last = $p->visits->first();
            $last?->append('dpjp_name');

            return [
                'id'                  => $p->id,
                'no_rm'               => $p->no_rm,
                'nama'                => $p->name,
                'nik'                 => $p->nik,
                'date_of_birth'       => $p->date_of_birth?->toDateString(),
                'gender'              => $p->gender,
                'address'             => $p->address,
                'allergy'             => $p->allergy_notes,
                'photo_url'           => $p->photo_url,
                'period_visits'       => $p->period_visits_count,
                'last_visit_date'     => $last?->visit_date?->toDateString(),
                'last_classification' => $last?->classification,
                'last_guarantor_type' => $last?->guarantor_type,
                'last_doctor'         => $last?->dpjp_name,
            ];
        });

        return $paginator;
    }

    /**
     * Full timeline — kunjungan pasien + semua catatan klinis per kunjungan.
     */
    public function getVisitHistory(string $patientId): array
    {
        Patient::findOrFail($patientId);

        $visits = Visit::with([
            'insurer',
            'nurseAssessment',
            'refractionRecord.prescription',
            'doctorExamination.surgeryPackage',
            'medicalResume',
            'prescriptions.items.medication',
            'billingInvoice',
            'patientDocuments.documentType',
        ])
            ->where('patient_id', $patientId)
            ->orderByDesc('visit_date')
            ->get();

        return $visits->map(fn ($visit) => [
            'visit_id'         => $visit->id,
            'visit_date'       => $visit->visit_date?->toDateString(),
            'classification'   => $visit->classification,
            'guarantor_type'   => $visit->guarantor_type,
            'current_station'  => $visit->current_station,
            'planning_follow_up' => $visit->planning_follow_up,
            'follow_up_date'   => $visit->follow_up_date?->toDateString(),
            'has_nurse_assessment' => $visit->nurseAssessment?->is_finalized ?? false,
            'has_refraction'   => $visit->refractionRecord?->is_finalized ?? false,
            'diagnosis_utama'  => $visit->doctorExamination?->diagnosis_utama,
            'planning'         => $visit->doctorExamination?->planning,
            'resume_medis'     => $visit->medicalResume ? [
                'id'           => $visit->medicalResume->id,
                'is_finalized' => $visit->medicalResume->is_finalized,
            ] : null,
            'dokumen_count'    => $visit->patientDocuments->count(),
            'invoice_status'   => $visit->billingInvoice?->status,
        ])->toArray();
    }

    public function indexKunjungan(string $patientId): LengthAwarePaginator
    {
        Patient::findOrFail($patientId);

        return Visit::with(['insurer', 'billingInvoice'])
            ->where('patient_id', $patientId)
            ->orderByDesc('visit_date')
            ->paginate(15);
    }

    // =========================================================================
    // DOKUMEN
    // =========================================================================

    public function indexDokumen(array $filters = []): LengthAwarePaginator
    {
        $query = PatientDocument::with(['patient', 'documentType', 'visit']);

        if (! empty($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (! empty($filters['visit_id'])) {
            $query->where('visit_id', $filters['visit_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['station'])) {
            $query->where('created_by_station', $filters['station']);
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 20);
    }

    public function showDokumen(string $id): PatientDocument
    {
        return PatientDocument::with([
            'patient',
            'visit',
            'documentType.templates',
            'verification',
            'notifications',
        ])->findOrFail($id);
    }

    public function storeDokumen(array $data): PatientDocument
    {
        $docType  = DocumentType::findOrFail($data['document_type_id']);
        $user     = auth('api')->user();

        $document = PatientDocument::create([
            'patient_id'             => $data['patient_id'],
            'visit_id'               => $data['visit_id'] ?? null,
            'document_type_id'       => $data['document_type_id'],
            'status'                 => 'DRAFT',
            'created_by_station'     => $data['created_by_station'],
            'pending_signature_roles' => [],
            'signatures'             => [],
            'printed_count'          => 0,
        ]);

        $this->log(
            $user->id,
            'CREATE_DOCUMENT',
            PatientDocument::class,
            $document->id,
            "Dokumen {$docType->code} dibuat di stasiun {$data['created_by_station']}"
        );

        return $document->load(['patient', 'documentType']);
    }

    public function updateDokumen(string $id, array $data): PatientDocument
    {
        $document = PatientDocument::findOrFail($id);

        if (in_array($document->status, ['FINAL', 'VOID'])) {
            throw new \Exception('Dokumen sudah final atau void, tidak bisa diubah.', 422);
        }

        $document->update(array_intersect_key($data, array_flip($document->getFillable())));

        $this->log(auth('api')->id(), 'UPDATE_DOCUMENT', PatientDocument::class, $id);

        return $document->fresh(['patient', 'documentType']);
    }

    /**
     * DRAFT → WAITING_SIGNATURE.
     * Populate pending_signature_roles from document_type.required_signatures.
     * Create Notification for each role that needs to sign.
     */
    public function submitDokumen(string $id): PatientDocument
    {
        $document = PatientDocument::with(['documentType', 'patient'])->findOrFail($id);

        if ($document->status !== 'DRAFT') {
            throw new \Exception('Hanya dokumen DRAFT yang bisa disubmit.', 422);
        }

        $requiredSigs = $document->documentType->required_signatures ?? [];
        $pendingRoles = array_column(
            array_filter($requiredSigs, fn ($s) => $s['is_required'] ?? true),
            'role'
        );

        return DB::transaction(function () use ($document, $pendingRoles) {
            $document->update([
                'status'                  => 'WAITING_SIGNATURE',
                'pending_signature_roles' => $pendingRoles,
            ]);

            // Kirim notifikasi ke setiap role yang harus TTD
            foreach ($pendingRoles as $role) {
                $this->notifySigners($document, $role);
            }

            $this->log(
                auth('api')->id(),
                'SUBMIT_DOCUMENT',
                PatientDocument::class,
                $document->id,
                "Menunggu TTD: " . implode(', ', $pendingRoles)
            );

            return $document->fresh(['patient', 'documentType', 'notifications']);
        });
    }

    public function voidDokumen(string $id, string $reason): PatientDocument
    {
        $document = PatientDocument::findOrFail($id);

        if ($document->status === 'VOID') {
            throw new \Exception('Dokumen sudah di-void.', 422);
        }

        DB::transaction(function () use ($document, $reason) {
            $document->update([
                'status'      => 'VOID',
                'void_reason' => $reason,
            ]);

            // Invalidate QR verification
            DocumentVerification::where('patient_document_id', $document->id)
                ->update(['is_valid' => false]);
        });

        $this->log(auth('api')->id(), 'VOID_DOCUMENT', PatientDocument::class, $id, $reason);

        return $document->fresh();
    }

    public function resendNotifDokumen(string $documentId): void
    {
        $document = PatientDocument::with('documentType')->findOrFail($documentId);

        if ($document->status !== 'WAITING_SIGNATURE') {
            throw new \Exception('Dokumen tidak dalam status menunggu TTD.', 422);
        }

        foreach ($document->pending_signature_roles ?? [] as $role) {
            $this->notifySigners($document, $role);
        }

        // Increment resend_count on existing notifications
        Notification::where('patient_document_id', $documentId)
            ->where('is_read', false)
            ->increment('resend_count');

        $this->log(auth('api')->id(), 'RESEND_NOTIF', PatientDocument::class, $documentId);
    }

    // =========================================================================
    // PDF GENERATION
    // =========================================================================

    /**
     * Return structured data for PDF rendering via Puppeteer.
     * Increment printed_count on the document.
     */
    public function generatePdf(string $documentId): array
    {
        $document = PatientDocument::with([
            'patient',
            'visit.doctorExamination',
            'visit.nurseAssessment',
            'visit.refractionRecord',
            'visit.medicalResume',
            'documentType.templates',
            'verification',
        ])->findOrFail($documentId);

        if (! in_array($document->status, ['WAITING_SIGNATURE', 'FINAL'])) {
            throw new \Exception('Hanya dokumen WAITING_SIGNATURE atau FINAL yang bisa dicetak.', 422);
        }

        $clinic    = ClinicProfile::first();
        $template  = $document->documentType->templates->first();

        // Increment print count
        $document->increment('printed_count');

        return [
            'clinic' => [
                'name'           => $clinic?->clinic_name,
                'address'        => $clinic?->address,
                'phone'          => $clinic?->phone,
                'logo_path'      => $clinic?->logo_path,
                'signature_path' => $clinic?->signature_path,
                'stamp_path'     => $clinic?->stamp_path,
                'director_name'  => $clinic?->director_name,
                'director_sip'   => $clinic?->director_sip,
                'watermark'      => $clinic?->watermark_enabled
                    ? $clinic?->watermark_type
                    : null,
            ],
            'document' => [
                'id'              => $document->id,
                'number'          => $document->document_number,
                'type_code'       => $document->documentType?->code,
                'type_name'       => $document->documentType?->name,
                'status'          => $document->status,
                'signatures'      => $document->signatures ?? [],
                'printed_count'   => $document->printed_count,
            ],
            'patient' => [
                'no_rm'        => $document->patient?->no_rm,
                'name'         => $document->patient?->name,
                'nik'          => $document->patient?->nik,
                'gender'       => $document->patient?->gender,
                'date_of_birth' => $document->patient?->date_of_birth?->format('d/m/Y'),
                'address'      => $document->patient?->address,
            ],
            'visit' => [
                'visit_date'     => $document->visit?->visit_date?->format('d/m/Y'),
                'guarantor_type' => $document->visit?->guarantor_type,
                'no_sep'         => $document->visit?->no_sep,
                'classification' => $document->visit?->classification,
            ],
            'clinical_data' => $this->buildClinicalData($document->visit),
            'template'      => [
                'header' => $template?->header_html,
                'body'   => $template?->body_html,
                'footer' => $template?->footer_html,
            ],
            'qr_token'      => $document->verification?->verification_token,
            'qr_url'        => $document->verification?->verification_url,
            'generated_at'  => now()->format('d/m/Y H:i'),
        ];
    }

    // =========================================================================
    // DOCUMENT NUMBER GENERATION (thread-safe)
    // =========================================================================

    /**
     * Generate document number from document_number_configs.
     * Tokens: {CODE}, {CLINIC}, {SEQ}, {YYYY}, {MM}, {DD}
     * Increment last_seq per config (lockForUpdate to prevent race conditions).
     */
    public function generateDocumentNumber(string $typeCode): string
    {
        $number = '';

        DB::transaction(function () use ($typeCode, &$number) {
            $config = DB::table('document_number_configs')
                ->where('document_type_code', $typeCode)
                ->lockForUpdate()
                ->first();

            if (! $config) {
                // Fallback default format
                $number = "RME/{$typeCode}/" . now()->format('Ym') . '/' . Str::padLeft(1, 7, '0');
                return;
            }

            $clinic   = ClinicProfile::value('clinic_code') ?? 'KMA';
            $nextSeq  = $config->last_seq + 1;
            $padded   = str_pad($nextSeq, $config->seq_length, '0', STR_PAD_LEFT);

            $number = str_replace(
                ['{CODE}', '{CLINIC}', '{SEQ}', '{YYYY}', '{MM}', '{DD}'],
                [$typeCode, $clinic, $padded, now()->format('Y'), now()->format('m'), now()->format('d')],
                $config->format
            );

            DB::table('document_number_configs')
                ->where('document_type_code', $typeCode)
                ->update(['last_seq' => $nextSeq]);
        });

        return $number;
    }

    // =========================================================================
    // QR CODE VERIFICATION
    // =========================================================================

    /**
     * Create or refresh DocumentVerification record with unique token.
     * Called when document reaches FINAL status.
     */
    public function generateQrCode(string $documentId): DocumentVerification
    {
        $document = PatientDocument::findOrFail($documentId);

        if ($document->status !== 'FINAL') {
            throw new \Exception('QR Code hanya bisa dibuat untuk dokumen FINAL.', 422);
        }

        $token = Str::uuid()->toString();
        $url   = url("/api/v1/rekam-medis/verifikasi/{$token}");
        $hash  = hash('sha256', json_encode([
            'id'     => $document->id,
            'number' => $document->document_number,
            'data'   => $document->toArray(),
        ]));

        $verification = DocumentVerification::updateOrCreate(
            ['patient_document_id' => $documentId],
            [
                'verification_token' => $token,
                'verification_url'   => $url,
                'document_hash'      => $hash,
                'is_valid'           => true,
                'scan_count'         => 0,
            ]
        );

        $this->log(auth('api')->id(), 'GENERATE_QR', DocumentVerification::class, $verification->id);

        return $verification;
    }

    /**
     * Verify document via QR token.
     * Increments scan_count on each hit (analytics).
     */
    public function verifyDocument(string $token): array
    {
        $verification = DocumentVerification::with([
            'patientDocument.documentSignatures',
        ])->where('verification_token', $token)->first();

        if (! $verification) {
            return ['valid' => false, 'message' => 'Token tidak ditemukan atau sudah tidak berlaku.'];
        }

        // Track scan
        $verification->increment('scan_count');
        $verification->update(['last_scanned_at' => now()]);

        $document = $verification->patientDocument;

        // Daftar penandatangan + tanggal TTD (info yang ditampilkan saat scan).
        // Sensitif (nama pasien/no RM/isi klinis) sengaja TIDAK diekspos publik.
        $signers = collect($document?->documentSignatures ?? [])
            ->sortBy('captured_at')
            ->map(function ($sig) {
                $waktu = null;
                if (!empty($sig->captured_at)) {
                    $waktu = optional($sig->captured_at)->timezone('Asia/Jakarta')?->format('d-m-Y H:i') . ' WIB';
                }
                return [
                    'nama'        => $sig->signer_name_snapshot ?? $this->signerDisplayName($sig),
                    'jabatan'     => $sig->signer_role_snapshot,
                    'metode'      => $sig->sign_method === 'PIN' ? 'TTD Elektronik (PIN)' : 'TTD Manual',
                    'ditandatangani_pada' => $waktu,
                ];
            })
            ->values()
            ->all();

        return [
            'valid'        => $verification->is_valid,
            'message'      => $verification->is_valid
                ? 'Dokumen valid dan terverifikasi.'
                : 'Dokumen sudah dibatalkan (VOID).',
            'signers'      => $signers,
            'finalized_at' => optional($document?->finalized_at)
                ? optional($document->finalized_at)->timezone('Asia/Jakarta')->format('d-m-Y H:i') . ' WIB'
                : null,
            'scan_count'   => $verification->scan_count,
        ];
    }

    /**
     * Nama tampilan penandatangan untuk halaman verifikasi bila snapshot kosong
     * (mis. signature mode DRAW pasien/saksi).
     */
    private function signerDisplayName($sig): string
    {
        if (!empty($sig->signer_external_identity['nama'])) {
            return $sig->signer_external_identity['nama'];
        }
        return match ($sig->signer_type) {
            'patient'  => 'Pasien',
            'guardian' => 'Wali',
            'witness'  => 'Saksi',
            default    => ucfirst((string) $sig->signer_type),
        };
    }

    // =========================================================================
    // MEDICAL RECORDS (generic form with versioning)
    // =========================================================================

    public function showMedicalRecord(string $visitId): ?MedicalRecord
    {
        return MedicalRecord::with(['documentType', 'createdBy', 'versions.changedBy'])
            ->where('visit_id', $visitId)
            ->first();
    }

    /**
     * Data Resume Medis Rawat Jalan (RM 1.7/RMRJ/22) untuk dicetak dari RME.
     * Mengembalikan field formulir (rmrj_data) + identitas pasien + profil klinik
     * (logo/nama) + nama dokter penandatangan. Read-only, permission rekam_medis.read.
     */
    public function resumeMedis(string $visitId): array
    {
        $visit  = Visit::with('patient')->find($visitId);
        $clinic = ClinicProfile::query()->first();

        // 1) UTAMAKAN dokumen RESUME_MEDIS yang sudah FINAL (rendered_html ber-stempel
        //    TTD + QR). Tujuannya: hasil cetak dari menu Kunjungan IDENTIK dengan tombol
        //    "Print" di section Dokumen / antrean TTD (TtdDokumenView) — keduanya pakai
        //    snapshot rendered_html dokumen final yang sama.
        $signedDoc = PatientDocument::query()
            ->where('visit_id', $visitId)
            ->where('template_code', 'RESUME_MEDIS')
            ->whereIn('status', ['FINALIZED', 'FINAL'])
            ->orderByDesc('finalized_at')
            ->first();

        $stampedHtml = null;
        if ($signedDoc) {
            $snap = app(\App\Services\FormRegistry\FormRegistryService::class)->getSnapshot($signedDoc->id);
            $stampedHtml = ! empty($snap['rendered_html']) ? $snap['rendered_html'] : null;
        }

        // 2) Fallback: Resume Medis Rawat Jalan legacy (MedicalResume → buildRmrjHtml)
        //    bila dokumen final belum ada / belum di-TTD.
        $resume = MedicalResume::with('doctor')->where('visit_id', $visitId)->first();

        if (! $stampedHtml && ! $resume) {
            throw new \Exception('Resume medis belum dibuat untuk kunjungan ini.', 404);
        }

        $html = $stampedHtml
            ?? app(\App\Services\DokterService::class)->buildRmrjHtml($resume, $visit);

        return [
            'rmrj'      => $resume?->rmrj_data ?? [],
            'rendered_html' => $html,
            'is_final'  => $signedDoc ? true : (bool) ($resume?->is_finalized),
            'signed'    => (bool) $signedDoc,   // true = HTML dari dokumen ber-stempel TTD
            'doctor'    => $resume?->doctor?->name ?? ($resume?->rmrj_data['dokter_merawat'] ?? null),
            'finalized_at' => optional($signedDoc?->finalized_at ?? $resume?->finalized_at)->format('Y-m-d'),
            'patient'   => $visit?->patient ? [
                'nama'          => $visit->patient->name,
                'no_rm'         => $visit->patient->no_rm,
                'nik'           => $visit->patient->nik,
                'gender'        => $visit->patient->gender,
                'date_of_birth' => optional($visit->patient->date_of_birth)->format('Y-m-d'),
            ] : null,
            'clinic'    => [
                'name'      => $clinic?->clinic_name,
                'logo_path' => $clinic?->logo_path,
                'address'   => $clinic?->address,
            ],
            'doc_code'  => 'RM 1.7/RMRJ/22',
        ];
    }

    /**
     * Kwitansi kunjungan (untuk dilihat/dicetak dari RME). Merakit data kwitansi via
     * KasirService::generateReceipt (sumber tunggal) lalu render blade pdf.receipt
     * menjadi HTML siap-tampil — pola sama dgn resumeMedis & cetak dokumen: FE fetch
     * via Axios lalu tampil/print di window baru (BUKAN window.open URL API). Read-only.
     */
    public function kwitansiKunjungan(string $visitId): array
    {
        $visit = Visit::with('billingInvoice')->find($visitId);
        if (! $visit) {
            throw new \Exception('Kunjungan tidak ditemukan.', 404);
        }

        $invoice = $visit->billingInvoice;
        if (! $invoice) {
            throw new \Exception('Belum ada kwitansi/tagihan untuk kunjungan ini.', 404);
        }

        $data = app(\App\Services\KasirService::class)->generateReceipt($invoice->id);
        // Jalur RME (browser: iframe preview + window cetak) → Blade bergaya Kasir
        // (`pdf.receipt_print`, flex/leader titik-titik) agar hasil cetak IDENTIK dgn
        // tombol "Cetak Rincian" di Kasir. Jalur email PDF tetap `pdf.receipt` (tabel,
        // aman utk dompdf yang tak dukung flexbox).
        $html = view('pdf.receipt_print', $data)->render();

        return [
            'rendered_html'  => $html,
            'invoice_number' => $invoice->invoice_number,
            'status'         => $invoice->status,
            'is_paid'        => $invoice->status === 'PAID',
        ];
    }

    public function storeMedicalRecord(array $data): MedicalRecord
    {
        $user   = auth('api')->user();
        $record = MedicalRecord::create([
            'visit_id'         => $data['visit_id'],
            'patient_id'       => $data['patient_id'],
            'document_type_id' => $data['document_type_id'] ?? null,
            'form_data'        => $data['form_data'] ?? [],
            'status'           => 'DRAFT',
            'created_by_id'    => $user->employee_id,
            'version'          => 1,
        ]);

        $this->log($user->id, 'STORE_MEDICAL_RECORD', MedicalRecord::class, $record->id);

        return $record->load(['documentType', 'createdBy']);
    }

    /**
     * Update medical record — create version snapshot before updating.
     */
    public function updateMedicalRecord(string $id, array $data): MedicalRecord
    {
        $record = MedicalRecord::findOrFail($id);
        $user   = auth('api')->user();

        DB::transaction(function () use ($record, $data, $user) {
            // Snapshot current state as version
            MedicalRecordVersion::create([
                'medical_record_id' => $record->id,
                'version'           => $record->version,
                'form_data'         => $record->form_data,
                'changed_by_id'     => $user->employee_id,
                'change_reason'     => $data['change_reason'] ?? null,
            ]);

            $record->update([
                'form_data' => $data['form_data'],
                'version'   => $record->version + 1,
            ]);
        });

        $this->log($user->id, 'UPDATE_MEDICAL_RECORD', MedicalRecord::class, $id, "v{$record->version}");

        return $record->fresh(['documentType', 'createdBy']);
    }

    public function getVersionsMedicalRecord(string $id): Collection
    {
        MedicalRecord::findOrFail($id);

        return MedicalRecordVersion::with('changedBy')
            ->where('medical_record_id', $id)
            ->orderByDesc('version')
            ->get();
    }

    // =========================================================================
    // NOTIFICATIONS
    // =========================================================================

    public function indexNotifikasi(): Collection
    {
        $userId = auth('api')->id();

        return Notification::with(['patientDocument.patient', 'patientDocument.documentType'])
            ->where('recipient_id', $userId)
            ->orderByRaw('is_read ASC, created_at DESC')
            ->limit(50)
            ->get();
    }

    public function bacaNotifikasi(string $id): Notification
    {
        $notif = Notification::where('recipient_id', auth('api')->id())->findOrFail($id);

        if (! $notif->is_read) {
            $notif->update(['is_read' => true, 'read_at' => now()]);
        }

        return $notif->fresh();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function buildClinicalData(?Visit $visit): array
    {
        if (! $visit) {
            return [];
        }

        $nurse    = $visit->nurseAssessment;
        $refraksi = $visit->refractionRecord;
        $doctor   = $visit->doctorExamination;
        $resume   = $visit->medicalResume;

        return [
            'ttv' => $nurse ? [
                'td'        => "{$nurse->td_sistol}/{$nurse->td_diastol} mmHg",
                'nadi'      => "{$nurse->nadi} x/mnt",
                'suhu'      => "{$nurse->suhu} °C",
                'spo2'      => "{$nurse->spo2}%",
                'complaint' => $nurse->chief_complaint,
                'allergy'   => $nurse->has_allergy ? $nurse->allergy_detail : 'Tidak ada',
            ] : null,
            'visus' => $refraksi ? [
                'visus_od'  => $refraksi->visus_akhir_od,
                'visus_os'  => $refraksi->visus_akhir_os,
                'iop_od'    => $refraksi->iop_od,
                'iop_os'    => $refraksi->iop_os,
                'clinical_notes' => $refraksi->clinical_notes,
            ] : null,
            'diagnosis' => $doctor ? [
                'utama'    => $doctor->diagnosis_utama,
                // diagnosis_sekunder kini {code,name}; pertahankan kontrak lama (array kode).
                'sekunder' => collect((array) $doctor->diagnosis_sekunder)
                    ->map(fn ($x) => is_array($x) ? ($x['code'] ?? $x['kode'] ?? null) : $x)
                    ->filter()->values()->all(),
                'planning' => $doctor->planning,
            ] : null,
            'resume' => $resume ? [
                'S' => $resume->resume_s,
                'O' => $resume->resume_o,
                'A' => $resume->resume_a,
                'P' => $resume->resume_p,
            ] : null,
        ];
    }

    private function notifySigners(PatientDocument $document, string $role): void
    {
        // Map role slug ke role_name di database
        $roleMap = [
            'DOCTOR'  => 'dokter',
            'ADMIN'   => 'superadmin',
            'PATIENT' => null, // Pasien tidak punya user record
        ];

        $roleName = $roleMap[$role] ?? strtolower($role);
        if (! $roleName) {
            return;
        }

        $users = User::whereHas('role', fn ($q) => $q->where('name', $roleName))
            ->where('is_active', true)
            ->get();

        foreach ($users as $user) {
            Notification::create([
                'recipient_id'        => $user->id,
                'type'                => 'SIGNATURE_REQUEST',
                'patient_document_id' => $document->id,
                'title'               => 'Dokumen Menunggu TTD',
                'message'             => "Dokumen {$document->documentType?->name} untuk pasien {$document->patient?->name} menunggu tanda tangan Anda.",
                'is_read'             => false,
                'resend_count'        => 0,
            ]);
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
