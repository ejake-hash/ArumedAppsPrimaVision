<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimLog;
use App\Models\InsuranceVerification;
use App\Models\InsurerDocumentRequirement;
use App\Models\Notification;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Modul Asuransi/TPA Non-BPJS.
 * - Verifikasi eligibility (input manual hasil cek portal TPA)
 * - Klaim CRUD + submit + status + resubmit
 * - Aging report
 *
 * Tidak menyentuh bpjs_claims / KlaimService / alur BPJS.
 * Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md
 */
class AsuransiService
{
    /**
     * Map status verifikasi → status flag di tabel visits.
     */
    private const VERIF_TO_VISIT_STATUS = [
        InsuranceVerification::STATUS_VERIFIED            => 'VERIFIED',
        InsuranceVerification::STATUS_NEEDS_CLARIFICATION => 'ISSUE',
        InsuranceVerification::STATUS_REJECTED            => 'ISSUE',
        InsuranceVerification::STATUS_PENDING             => 'PENDING',
    ];

    // =========================================================================
    // VERIFIKASI
    // =========================================================================

    /**
     * Ambil verifikasi terbaru untuk satu visit (atau null kalau belum ada).
     */
    public function getVerifikasi(string $visitId): ?InsuranceVerification
    {
        return InsuranceVerification::with(['insurer', 'verifiedBy'])
            ->where('visit_id', $visitId)
            ->latest()
            ->first();
    }

    /**
     * Input hasil verifikasi eligibility dari portal TPA.
     * Dipanggil oleh billing setelah cek manual.
     */
    public function createVerifikasi(array $data, ?string $userId = null): InsuranceVerification
    {
        return DB::transaction(function () use ($data, $userId) {
            $verif = InsuranceVerification::create([
                'visit_id'           => $data['visit_id'],
                'insurer_id'         => $data['insurer_id'],
                'verified_by'        => $userId ?? auth('api')->id(),
                'status'             => $data['status'] ?? InsuranceVerification::STATUS_PENDING,
                'policy_number'      => $data['policy_number']      ?? null,
                'member_name'        => $data['member_name']        ?? null,
                'member_card_number' => $data['member_card_number'] ?? null,
                'plafon_amount'      => $data['plafon_amount']      ?? null,
                'copayment_percent'  => $data['copayment_percent']  ?? 0,
                'copayment_amount'   => $data['copayment_amount']   ?? 0,
                'covered_amount'     => $data['covered_amount']     ?? null,
                'coverage_notes'     => $data['coverage_notes']     ?? null,
                'exclusion_flags'    => $data['exclusion_flags']    ?? null,
                'issue_notes'        => $data['issue_notes']        ?? null,
                'verified_at'        => isset($data['status']) && $data['status'] !== InsuranceVerification::STATUS_PENDING
                    ? now()
                    : null,
            ]);

            $this->syncVisitStatus($verif->visit_id, $verif->status);
            $this->syncCoveredToInvoice($verif);

            if (in_array($verif->status, [
                InsuranceVerification::STATUS_NEEDS_CLARIFICATION,
                InsuranceVerification::STATUS_REJECTED,
            ], true)) {
                $this->notifySupervisor($verif->visit_id, $verif->issue_notes ?? '');
            }

            return $verif->fresh(['insurer', 'verifiedBy']);
        });
    }

    public function updateVerifikasi(string $verifId, array $data, ?string $userId = null): InsuranceVerification
    {
        return DB::transaction(function () use ($verifId, $data, $userId) {
            $verif = InsuranceVerification::findOrFail($verifId);

            // Hanya kolom yang BENAR-BENAR dikirim yang di-update (cek key presence),
            // BUKAN array_filter(!== null): array_filter membuang nilai 0/null/''
            // sehingga admin tak bisa mereset cover ke null (batalkan cover) atau
            // mengosongkan catatan. Field yang dikirim eksplisit → diterapkan apa adanya.
            $payload = [];
            foreach ([
                'status', 'policy_number', 'member_name', 'member_card_number',
                'plafon_amount', 'copayment_percent', 'copayment_amount',
                'covered_amount', 'coverage_notes', 'exclusion_flags', 'issue_notes',
            ] as $key) {
                if (array_key_exists($key, $data)) {
                    $payload[$key] = $data[$key];
                }
            }

            if (isset($data['status']) && $data['status'] !== InsuranceVerification::STATUS_PENDING) {
                $payload['verified_at'] = now();
                $payload['verified_by'] = $userId ?? auth('api')->id() ?? $verif->verified_by;
            }

            $verif->update($payload);
            $this->syncCoveredToInvoice($verif->fresh());

            if (isset($data['status'])) {
                $this->syncVisitStatus($verif->visit_id, $data['status']);

                if (in_array($data['status'], [
                    InsuranceVerification::STATUS_NEEDS_CLARIFICATION,
                    InsuranceVerification::STATUS_REJECTED,
                ], true)) {
                    $this->notifySupervisor($verif->visit_id, $verif->issue_notes ?? '');
                }
            }

            return $verif->fresh(['insurer', 'verifiedBy']);
        });
    }

    /**
     * Daftar kunjungan hari ini yang status verifikasi-nya PENDING.
     * Dipakai billing untuk tahu siapa yang harus segera dicek ke portal TPA.
     */
    public function pendingVerifications(?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        return Visit::with([
                'patient:id,name,no_rm',
                'insurer:id,name,type,sla_days',
                'latestInsuranceVerification:id,visit_id,policy_number,member_name,member_card_number,created_at',
            ])
            ->where('insurance_verification_status', 'PENDING')
            ->whereDate('visit_date', $date)
            ->orderBy('created_at')
            ->get()
            ->map(function (Visit $v) {
                $verif = $v->latestInsuranceVerification;
                // Hitung tunggu sejak verifikasi jadi PENDING (saat admisi), BUKAN sejak
                // ambil antrean (visits.created_at) — itu termasuk waktu antre poli umum.
                // Fallback ke visits.created_at kalau row verif belum ada.
                $waitFrom = $verif->created_at ?? $v->created_at;
                $waitMinutes = (int) Carbon::parse($waitFrom)->diffInMinutes(now());
                return [
                    'visit_id'           => $v->id,
                    'no_antreen'         => $v->no_antreen,
                    'patient_id'         => $v->patient_id,
                    'patient_name'       => $v->patient->name ?? '-',
                    'mrn'                => $v->patient->no_rm ?? null,
                    'insurer_id'         => $v->insurer_id,
                    'insurer_name'       => $v->insurer->name ?? '-',
                    'guarantor_type'     => $v->guarantor_type,
                    'policy_number'      => $verif->policy_number ?? null,
                    'member_name'        => $verif->member_name ?? null,
                    'member_card_number' => $verif->member_card_number ?? null,
                    'wait_minutes'       => $waitMinutes,
                    'created_at'         => $v->created_at,
                ];
            })
            ->toArray();
    }

    /**
     * Kunjungan asuransi hari ini yang SUDAH diverifikasi (VERIFIED/ISSUE) tapi
     * BELUM selesai (belum lunas). Di tab ini admin menentukan jumlah cover &
     * melihat rincian tagihan riil sebelum pasien ke kasir.
     */
    public function inServiceVerifications(?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        return Visit::with([
                'patient:id,name,no_rm',
                'insurer:id,name,type,sla_days',
                'latestInsuranceVerification',
                'billingInvoice:id,visit_id,invoice_number,total,covered_amount,paid_amount,status',
            ])
            ->whereIn('insurance_verification_status', ['VERIFIED', 'ISSUE'])
            ->where('current_station', '!=', 'SELESAI')
            ->whereDate('visit_date', $date)
            ->orderBy('created_at')
            ->get()
            ->map(function (Visit $v) {
                $verif   = $v->latestInsuranceVerification;
                $invoice = $v->billingInvoice;
                $total   = (float) ($invoice->total ?? 0);
                $covered = (float) ($invoice->covered_amount ?? $verif->covered_amount ?? 0);
                return [
                    'visit_id'         => $v->id,
                    'no_antreen'       => $v->no_antreen,
                    'patient_id'       => $v->patient_id,
                    'patient_name'     => $v->patient->name ?? '-',
                    'mrn'              => $v->patient->no_rm ?? null,
                    'insurer_id'       => $v->insurer_id,
                    'insurer_name'     => $v->insurer->name ?? '-',
                    'guarantor_type'   => $v->guarantor_type,
                    'verif_status'     => $v->insurance_verification_status,
                    'verification_id'  => $verif->id ?? null,
                    'policy_number'    => $verif->policy_number ?? null,
                    'member_name'      => $verif->member_name ?? null,
                    'current_station'  => $v->current_station,
                    'has_invoice'      => (bool) $invoice,
                    'invoice_status'   => $invoice->status ?? null,
                    'invoice_total'    => $total,
                    'covered_amount'   => $covered,
                    'patient_due'      => max(0, $total - $covered - (float) ($invoice->paid_amount ?? 0)),
                ];
            })
            ->toArray();
    }

    // =========================================================================
    // KLAIM
    // =========================================================================

    /**
     * List klaim dengan filter (status, insurer, tanggal, search).
     */
    public function indexKlaim(array $filters = []): LengthAwarePaginator
    {
        $q = InsuranceClaim::query()
            ->with([
                'insurer:id,name,sla_days',
                'visit:id,patient_id,visit_date,no_antreen',
                'visit.patient:id,name,no_rm',
                'invoice:id,invoice_number,total',
            ]);

        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (!empty($filters['insurer_id'])) {
            $q->where('insurer_id', $filters['insurer_id']);
        }
        if (!empty($filters['date_from'])) {
            $q->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $q->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(fn ($qq) => $qq
                ->where('submission_ref', 'ilike', $term)
                ->orWhereHas('visit.patient', fn ($p) => $p->where('name', 'ilike', $term)));
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        return $q->orderByDesc('created_at')->paginate($perPage);
    }

    public function showKlaim(string $id): InsuranceClaim
    {
        return InsuranceClaim::with([
            'insurer',
            'visit.patient',
            'invoice',
            'verification',
            'submittedBy',
            'logs.performedBy',
        ])->findOrFail($id);
    }

    /**
     * Buat draft klaim.
     * Auto-populate documents_checklist dari InsurerDocumentRequirement.
     * Auto-link ke insurance_verification terbaru visit ybs (kalau belum di-pass eksplisit).
     */
    public function createDraftKlaim(array $data, ?string $userId = null): InsuranceClaim
    {
        return DB::transaction(function () use ($data, $userId) {
            $insurerId = $data['insurer_id'];

            $requirements = InsurerDocumentRequirement::where('insurer_id', $insurerId)
                ->orderBy('sort_order')
                ->get();

            $checklist = [];
            foreach ($requirements as $req) {
                $checklist[$req->document_name] = false;
            }

            // Auto-link verifikasi terbaru kalau tidak di-supply
            $verificationId = $data['insurance_verification_id']
                ?? InsuranceVerification::where('visit_id', $data['visit_id'])
                    ->where('insurer_id', $insurerId)
                    ->latest()
                    ->value('id');

            $claim = InsuranceClaim::create([
                'visit_id'                  => $data['visit_id'],
                'insurer_id'                => $insurerId,
                'billing_invoice_id'        => $data['billing_invoice_id'] ?? null,
                'insurance_verification_id' => $verificationId,
                'status'                    => InsuranceClaim::STATUS_DRAFT,
                'claim_amount'              => $data['claim_amount']           ?? 0,
                'patient_responsibility'    => $data['patient_responsibility'] ?? 0,
                'documents_checklist'       => $checklist,
                'notes'                     => $data['notes'] ?? null,
            ]);

            $this->addLog(
                $claim->id,
                $userId ?? auth('api')->id(),
                InsuranceClaimLog::ACTION_CREATED,
                null,
                InsuranceClaim::STATUS_DRAFT,
                ['source' => $data['source'] ?? 'manual']
            );

            return $claim->fresh(['insurer', 'visit.patient']);
        });
    }

    public function updateKlaim(string $claimId, array $data): InsuranceClaim
    {
        $claim = InsuranceClaim::findOrFail($claimId);

        if ($claim->status !== InsuranceClaim::STATUS_DRAFT) {
            abort(422, "Klaim status {$claim->status} tidak bisa di-update bebas. Gunakan endpoint sesuai workflow.");
        }

        $claim->update(array_filter([
            'claim_amount'           => $data['claim_amount']           ?? null,
            'patient_responsibility' => $data['patient_responsibility'] ?? null,
            'documents_checklist'    => $data['documents_checklist']    ?? null,
            'notes'                  => $data['notes']                  ?? null,
            'billing_invoice_id'     => $data['billing_invoice_id']     ?? null,
        ], fn ($v) => $v !== null));

        return $claim->fresh(['insurer', 'visit.patient']);
    }

    /**
     * Submit klaim ke TPA setelah billing input di portal & dapat nomor referensi.
     */
    public function submitKlaim(string $claimId, array $data, ?string $userId = null): InsuranceClaim
    {
        return DB::transaction(function () use ($claimId, $data, $userId) {
            $claim = InsuranceClaim::findOrFail($claimId);

            if ($claim->status !== InsuranceClaim::STATUS_DRAFT) {
                abort(422, "Hanya klaim status DRAFT yang bisa di-submit. Status saat ini: {$claim->status}.");
            }

            if (empty($data['submission_ref'])) {
                abort(422, 'Nomor referensi submission dari portal TPA wajib diisi.');
            }

            $this->validateDocumentChecklist($claim, $data['documents_checklist'] ?? null);

            $payload = [
                'status'         => InsuranceClaim::STATUS_SUBMITTED,
                'submission_ref' => $data['submission_ref'],
                'submitted_by'   => $userId ?? auth('api')->id(),
                'submitted_at'   => now(),
            ];
            if (isset($data['documents_checklist'])) {
                $payload['documents_checklist'] = $data['documents_checklist'];
            }
            if (isset($data['notes'])) {
                $payload['notes'] = $data['notes'];
            }
            if (isset($data['claim_amount'])) {
                $payload['claim_amount'] = $data['claim_amount'];
            }

            $claim->update($payload);

            $this->addLog(
                $claim->id,
                $userId ?? auth('api')->id(),
                InsuranceClaimLog::ACTION_SUBMITTED,
                InsuranceClaim::STATUS_DRAFT,
                InsuranceClaim::STATUS_SUBMITTED,
                ['submission_ref' => $data['submission_ref']]
            );

            return $claim->fresh(['insurer', 'visit.patient', 'logs']);
        });
    }

    /**
     * Update status klaim ke APPROVED / REJECTED / APPEALED.
     */
    public function updateStatusKlaim(string $claimId, array $data, ?string $userId = null): InsuranceClaim
    {
        return DB::transaction(function () use ($claimId, $data, $userId) {
            $claim = InsuranceClaim::findOrFail($claimId);
            $from  = $claim->status;
            $to    = $data['status'];

            if (!in_array($to, [
                InsuranceClaim::STATUS_APPROVED,
                InsuranceClaim::STATUS_REJECTED,
                InsuranceClaim::STATUS_APPEALED,
            ], true)) {
                abort(422, "Status transition tidak valid: {$to}");
            }

            if (!in_array($from, [
                InsuranceClaim::STATUS_SUBMITTED,
                InsuranceClaim::STATUS_APPEALED,
            ], true)) {
                abort(422, "Klaim status {$from} tidak bisa di-update ke {$to}. Submit dulu.");
            }

            $payload = ['status' => $to];

            if ($to === InsuranceClaim::STATUS_APPROVED) {
                if (!isset($data['approved_amount'])) {
                    abort(422, 'approved_amount wajib diisi saat menyetujui klaim.');
                }
                $payload['approved_amount']        = $data['approved_amount'];
                $payload['approved_at']            = now();
                $payload['patient_responsibility'] = max(
                    0,
                    (float)($claim->claim_amount ?? 0) - (float)$data['approved_amount']
                );
            }

            if ($to === InsuranceClaim::STATUS_REJECTED) {
                $payload['rejection_code']   = $data['rejection_code']   ?? null;
                $payload['rejection_reason'] = $data['rejection_reason'] ?? null;
                $payload['rejected_at']      = now();
            }

            if ($to === InsuranceClaim::STATUS_APPEALED) {
                $payload['appeal_notes'] = $data['appeal_notes'] ?? null;
            }

            $claim->update($payload);

            $this->addLog(
                $claim->id,
                $userId ?? auth('api')->id(),
                $to,
                $from,
                $to,
                array_filter([
                    'approved_amount'  => $data['approved_amount']  ?? null,
                    'rejection_code'   => $data['rejection_code']   ?? null,
                    'rejection_reason' => $data['rejection_reason'] ?? null,
                    'appeal_notes'     => $data['appeal_notes']     ?? null,
                ], fn ($v) => $v !== null)
            );

            return $claim->fresh(['insurer', 'visit.patient', 'logs']);
        });
    }

    /**
     * Resubmit klaim yang ditolak (revisi dokumen).
     */
    public function resubmitKlaim(string $claimId, array $data, ?string $userId = null): InsuranceClaim
    {
        return DB::transaction(function () use ($claimId, $data, $userId) {
            $claim = InsuranceClaim::findOrFail($claimId);

            if ($claim->status !== InsuranceClaim::STATUS_REJECTED) {
                abort(422, "Hanya klaim status REJECTED yang bisa di-resubmit. Status saat ini: {$claim->status}.");
            }

            if (empty($data['submission_ref'])) {
                abort(422, 'Nomor referensi submission baru wajib diisi.');
            }

            $newCount = $claim->resubmission_count + 1;

            $claim->update([
                'status'              => InsuranceClaim::STATUS_SUBMITTED,
                'submission_ref'      => $data['submission_ref'],
                'submitted_at'        => now(),
                'submitted_by'        => $userId ?? auth('api')->id(),
                'resubmission_count'  => $newCount,
                'rejection_code'      => null,
                'rejection_reason'    => null,
                'rejected_at'         => null,
                'documents_checklist' => $data['documents_checklist'] ?? $claim->documents_checklist,
                'notes'               => $data['notes'] ?? $claim->notes,
            ]);

            $this->addLog(
                $claim->id,
                $userId ?? auth('api')->id(),
                InsuranceClaimLog::ACTION_RESUBMITTED,
                InsuranceClaim::STATUS_REJECTED,
                InsuranceClaim::STATUS_SUBMITTED,
                [
                    'submission_ref'     => $data['submission_ref'],
                    'resubmission_count' => $newCount,
                ]
            );

            return $claim->fresh(['insurer', 'visit.patient', 'logs']);
        });
    }

    public function getLogs(string $claimId): array
    {
        return InsuranceClaimLog::with('performedBy:id,name')
            ->where('insurance_claim_id', $claimId)
            ->orderBy('performed_at')
            ->get()
            ->toArray();
    }

    // =========================================================================
    // LAPORAN & AGING
    // =========================================================================

    /**
     * Klaim outstanding (DRAFT/SUBMITTED/APPEALED) + usia per klaim + overdue flag.
     */
    public function getAgingReport(): array
    {
        $claims = InsuranceClaim::with(['insurer:id,name,sla_days', 'visit.patient:id,name'])
            ->whereIn('status', [
                InsuranceClaim::STATUS_DRAFT,
                InsuranceClaim::STATUS_SUBMITTED,
                InsuranceClaim::STATUS_APPEALED,
            ])
            ->orderBy('submitted_at')
            ->get();

        return $claims->map(function (InsuranceClaim $claim) {
            $reference = $claim->submitted_at ?? $claim->created_at;
            $age = $reference ? Carbon::parse($reference)->diffInDays(now()) : 0;
            $sla = $claim->insurer->sla_days ?? 14;

            return [
                'id'             => $claim->id,
                'visit_id'       => $claim->visit_id,
                'patient_name'   => $claim->visit->patient->name ?? '-',
                'insurer_id'     => $claim->insurer_id,
                'insurer_name'   => $claim->insurer->name ?? '-',
                'claim_amount'   => $claim->claim_amount,
                'status'         => $claim->status,
                'submission_ref' => $claim->submission_ref,
                'age_days'       => (int) $age,
                'sla_days'       => $sla,
                'is_overdue'     => $age > $sla,
                'submitted_at'   => $claim->submitted_at,
                'created_at'     => $claim->created_at,
            ];
        })->toArray();
    }

    /**
     * Ringkasan dashboard insurance.
     */
    public function dashboardSummary(): array
    {
        $startMonth = now()->startOfMonth();

        return [
            'pending_verification' => Visit::where('insurance_verification_status', 'PENDING')
                ->whereDate('visit_date', now()->toDateString())
                ->count(),
            'draft_claims' => InsuranceClaim::where('status', InsuranceClaim::STATUS_DRAFT)->count(),
            'overdue_claims' => collect($this->getAgingReport())
                ->where('is_overdue', true)
                ->count(),
            'submitted_this_month' => InsuranceClaim::where('status', InsuranceClaim::STATUS_SUBMITTED)
                ->where('submitted_at', '>=', $startMonth)
                ->count(),
            'approved_this_month' => InsuranceClaim::where('status', InsuranceClaim::STATUS_APPROVED)
                ->where('approved_at', '>=', $startMonth)
                ->count(),
            'rejected_this_month' => InsuranceClaim::where('status', InsuranceClaim::STATUS_REJECTED)
                ->where('rejected_at', '>=', $startMonth)
                ->count(),
        ];
    }

    // =========================================================================
    // DOCUMENT REQUIREMENTS (Master per TPA)
    // =========================================================================

    public function indexDocRequirement(string $insurerId): array
    {
        return InsurerDocumentRequirement::where('insurer_id', $insurerId)
            ->orderBy('sort_order')
            ->orderBy('document_name')
            ->get()
            ->toArray();
    }

    public function storeDocRequirement(string $insurerId, array $data): InsurerDocumentRequirement
    {
        return InsurerDocumentRequirement::create([
            'insurer_id'    => $insurerId,
            'document_name' => $data['document_name'],
            'is_required'   => $data['is_required'] ?? true,
            'notes'         => $data['notes'] ?? null,
            'sort_order'    => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateDocRequirement(string $id, array $data): InsurerDocumentRequirement
    {
        $req = InsurerDocumentRequirement::findOrFail($id);
        $req->update(array_filter([
            'document_name' => $data['document_name'] ?? null,
            'is_required'   => array_key_exists('is_required', $data) ? $data['is_required'] : null,
            'notes'         => $data['notes']         ?? null,
            'sort_order'    => array_key_exists('sort_order', $data) ? $data['sort_order'] : null,
        ], fn ($v) => $v !== null));
        return $req->fresh();
    }

    public function deleteDocRequirement(string $id): void
    {
        InsurerDocumentRequirement::findOrFail($id)->delete();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Sinkron nominal cover dari verifikasi ke billing_invoices.covered_amount.
     * Hanya jika admin sudah menentukan covered_amount (bukan NULL).
     * Sisa pasien di kasir = total − covered − paid_amount.
     */
    private function syncCoveredToInvoice(InsuranceVerification $verif): void
    {
        if ($verif->covered_amount === null) {
            return; // Belum ditentukan — kasir pakai estimasi copay seperti biasa.
        }

        $invoice = BillingInvoice::where('visit_id', $verif->visit_id)->first();
        if (! $invoice || in_array($invoice->status, ['PAID', 'CANCELLED'], true)) {
            return; // Tidak ada invoice atau sudah final — jangan ubah.
        }

        // Cover tidak boleh melebihi total tagihan.
        $covered = min((float) $verif->covered_amount, (float) $invoice->total);

        $invoice->update([
            'covered_amount' => $covered,
            'covered_by'     => auth('api')->id(),
            'covered_at'     => now(),
        ]);
    }

    /**
     * Rincian tagihan pasien untuk dilihat admin asuransi (read-only) sebelum
     * menentukan jumlah cover. Mengembalikan invoice + items, atau null kalau
     * invoice belum dibuat (pasien belum sampai kasir).
     */
    public function getBilling(string $visitId): ?BillingInvoice
    {
        return BillingInvoice::with(['items', 'visit.patient'])
            ->where('visit_id', $visitId)
            ->first();
    }

    private function syncVisitStatus(string $visitId, string $verifStatus): void
    {
        $visitStatus = self::VERIF_TO_VISIT_STATUS[$verifStatus] ?? 'ISSUE';

        Visit::where('id', $visitId)->update([
            'insurance_verification_status' => $visitStatus,
            'insurance_verified_at'         => $verifStatus !== InsuranceVerification::STATUS_PENDING
                ? now()
                : null,
        ]);
    }

    private function addLog(
        string $claimId,
        ?string $userId,
        string $action,
        ?string $from,
        ?string $to,
        array $metadata = [],
        ?string $notes = null
    ): InsuranceClaimLog {
        return InsuranceClaimLog::create([
            'insurance_claim_id' => $claimId,
            'performed_by'       => $userId,
            'action'             => $action,
            'from_status'        => $from,
            'to_status'          => $to,
            'notes'              => $notes,
            'metadata'           => $metadata ?: null,
            'performed_at'       => now(),
        ]);
    }

    /**
     * Pastikan semua dokumen required di master sudah dicentang di checklist.
     * Override checklist boleh di-pass dari args (kalau billing baru update).
     */
    private function validateDocumentChecklist(InsuranceClaim $claim, ?array $overrideChecklist = null): void
    {
        $checklist = $overrideChecklist ?? $claim->documents_checklist ?? [];

        $required = InsurerDocumentRequirement::where('insurer_id', $claim->insurer_id)
            ->where('is_required', true)
            ->pluck('document_name');

        $missing = [];
        foreach ($required as $docName) {
            if (empty($checklist[$docName])) {
                $missing[] = $docName;
            }
        }

        if (!empty($missing)) {
            abort(422, 'Dokumen wajib belum dilengkapi: ' . implode(', ', $missing));
        }
    }

    /**
     * Kirim notifikasi internal ke supervisor/kasir jika ada issue verifikasi.
     */
    private function notifySupervisor(string $visitId, string $notes): void
    {
        $visit = Visit::with('patient:id,name')->find($visitId);
        $patientName = $visit?->patient?->name ?? '-';

        Notification::create([
            'recipient_id'        => null, // broadcast — implementasi recipient resolver via Reverb nanti
            'type'                => 'INSURANCE_VERIFICATION_ISSUE',
            'patient_document_id' => null,
            'title'               => 'Verifikasi Asuransi: Perlu Perhatian',
            'message'             => "Pasien {$patientName}: {$notes}",
            'is_read'             => false,
            'resend_count'        => 0,
        ]);
    }
}
