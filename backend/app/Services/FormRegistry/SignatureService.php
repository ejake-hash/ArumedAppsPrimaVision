<?php

namespace App\Services\FormRegistry;

use App\Models\DocumentSignature;
use App\Models\PatientDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Service untuk capture TTD digital.
 *
 * Aturan kunci (PMK 24/2022 + design doc Section 9):
 *   1. Append-only. `update()` / `delete()` throw exception (di model + sini).
 *   2. `captured_at` selalu dari server, BUKAN client clock.
 *   3. `integrity_hash` = SHA-256(svg + captured_at + patient_document_id + signer_identity).
 *      Disimpan saat capture; bisa di-verify ulang.
 *   4. Hanya boleh capture saat patient_document.status ∈ {DRAFT, RENDERED, PENDING_SIGNATURE}.
 *      Setelah FINALIZED — tidak boleh tambah signature (untuk koreksi → pakai addendum).
 *   5. `signer_external_identity` wajib untuk signer_type ∈ {witness, guardian}
 *      jika tidak ada signer_user_id / signer_patient_id.
 */
final class SignatureService
{
    /**
     * @param array{
     *   patient_document_id: string,
     *   signer_type: string,
     *   signer_user_id?: ?string,
     *   signer_patient_id?: ?string,
     *   signer_external_identity?: ?array,
     *   signature_svg?: ?string,
     *   signature_png_base64?: ?string,
     *   biometric_metadata?: ?array,
     *   audit_log?: ?array,
     *   captured_device_info?: ?array,
     *   captured_by_facilitator_user_id?: ?string,
     * } $data
     */
    public function capture(array $data): DocumentSignature
    {
        $doc = PatientDocument::query()->findOrFail($data['patient_document_id']);

        if (in_array($doc->status, ['FINALIZED', 'FINAL', 'VOID', 'REJECTED'], true)) {
            throw new RuntimeException("Dokumen sudah {$doc->status} — tidak bisa tambah signature. Pakai addendum untuk koreksi.");
        }

        $signerType = $data['signer_type'] ?? null;
        if (!in_array($signerType, DocumentSignature::SIGNER_TYPES, true)) {
            throw new RuntimeException("signer_type tidak valid: '{$signerType}'.");
        }
        $this->assertSignerIdentity($signerType, $data);

        $now = now();
        $signatureId = 'sig_' . Str::ulid()->toBase32();
        $svg = $data['signature_svg'] ?? '';
        $identityKey = $this->signerIdentityKey($data);

        // Hash pakai format detik biasa — microseconds tidak konsisten across
        // DB driver (SQLite text vs Postgres timestamp(3)). Trade-off: hash
        // collision dalam detik yang sama hampir mustahil di praktek.
        $hash = hash('sha256', $svg . '|' . $now->format('Y-m-d H:i:s') . '|' . $doc->id . '|' . $identityKey);

        return DB::transaction(function () use ($data, $doc, $signatureId, $now, $hash) {
            $sig = DocumentSignature::create([
                'signature_id'                    => $signatureId,
                'patient_document_id'             => $doc->id,
                'signer_type'                     => $data['signer_type'],
                'signer_user_id'                  => $data['signer_user_id']     ?? null,
                'signer_patient_id'               => $data['signer_patient_id']  ?? null,
                'signer_external_identity'        => $data['signer_external_identity'] ?? null,
                'signature_svg'                   => $data['signature_svg']       ?? null,
                'signature_png_base64'            => $data['signature_png_base64'] ?? null,
                'captured_at'                     => $now,
                'captured_device_info'            => $data['captured_device_info'] ?? [],
                'captured_by_facilitator_user_id' => $data['captured_by_facilitator_user_id'] ?? null,
                'biometric_metadata'              => $data['biometric_metadata']  ?? null,
                'audit_log'                       => $data['audit_log']           ?? [],
                'integrity_hash'                  => $hash,
                'created_at'                      => $now,
            ]);

            // Auto-advance status: DRAFT → RENDERED → PENDING_SIGNATURE
            // (soft enforcement — kalau ada signature, dokumen masuk PENDING_SIGNATURE).
            if (in_array($doc->status, ['DRAFT', 'RENDERED'], true)) {
                $doc->status = 'PENDING_SIGNATURE';
                $doc->save();
            }

            FormRegistryAudit::record(
                'FORM_SIG_CAPTURED',
                model: 'DocumentSignature',
                modelId: $sig->id,
                description: "Signature signer_type={$sig->signer_type}",
                context: [
                    'signature_id'        => $sig->signature_id,
                    'patient_document_id' => $doc->id,
                    'signer_type'         => $sig->signer_type,
                    'integrity_hash'      => $hash,
                    'biometric_summary'   => $sig->biometric_metadata,
                ],
            );

            return $sig;
        });
    }

    /**
     * Verifikasi integrity hash: re-hash dan bandingkan.
     * Return: { valid: bool, expected: string, actual: string }
     */
    public function verify(string $signatureId): array
    {
        $sig = DocumentSignature::query()->where('signature_id', $signatureId)->firstOrFail();
        $identityKey = $this->signerIdentityKey([
            'signer_user_id'           => $sig->signer_user_id,
            'signer_patient_id'        => $sig->signer_patient_id,
            'signer_external_identity' => $sig->signer_external_identity,
        ]);
        $expected = hash(
            'sha256',
            ($sig->signature_svg ?? '') . '|' . $sig->captured_at->format('Y-m-d H:i:s') . '|' . $sig->patient_document_id . '|' . $identityKey
        );

        return [
            'valid'    => hash_equals($expected, $sig->integrity_hash),
            'expected' => $expected,
            'actual'   => $sig->integrity_hash,
            'signature_id' => $sig->signature_id,
        ];
    }

    /** @return list<DocumentSignature> */
    public function listByDocument(string $patientDocumentId): array
    {
        return DocumentSignature::query()
            ->where('patient_document_id', $patientDocumentId)
            ->orderBy('captured_at')
            ->get()
            ->all();
    }

    /**
     * Daftar antrian TTD untuk dokter yang login.
     * Filter: patient_documents.status = 'PENDING_SIGNATURE' DAN ada field
     * signature_canvas dengan signer_type='doctor' yang belum di-capture
     * oleh dokter ini.
     *
     * Output di-group by patient. Sesuai design doc Section 9.3.
     */
    public function ttdQueueForDoctor(string $userId): array
    {
        $docs = PatientDocument::query()
            ->whereIn('status', ['PENDING_SIGNATURE', 'RENDERED', 'DRAFT'])
            ->with(['patient', 'visit', 'documentSignatures'])
            ->whereHas('visit')
            ->orderByDesc('created_at')
            ->get();

        // Filter: dokumen yang punya kebutuhan TTD dokter & dokter ini belum TTD.
        $relevant = $docs->filter(function (PatientDocument $d) use ($userId) {
            $tpl = $d->template_code ? \App\Models\DocumentTemplate::where('code', $d->template_code)->first() : null;
            if (!$tpl || !$tpl->field_schema) return false;

            $hasDoctorSig = $this->schemaRequiresDoctorSignature($tpl->field_schema);
            if (!$hasDoctorSig) return false;

            // Sudah TTD oleh dokter ini?
            $alreadySigned = $d->documentSignatures
                ->where('signer_type', 'doctor')
                ->where('signer_user_id', $userId)
                ->isNotEmpty();
            return !$alreadySigned;
        })->values();

        // Group by patient.
        $grouped = $relevant->groupBy(fn ($d) => $d->patient_id)->map(function ($docs) {
            $first = $docs->first();
            return [
                'patient' => [
                    'id'     => $first->patient_id,
                    'no_rm'  => $first->patient?->no_rm,
                    'name'   => $first->patient?->name,
                    'gender' => $first->patient?->gender,
                ],
                'documents' => $docs->map(fn ($d) => [
                    'id'             => $d->id,
                    'template_code'  => $d->template_code,
                    'status'         => $d->status,
                    'created_at'     => $d->created_at,
                    'visit_id'       => $d->visit_id,
                    'visit_date'     => $d->visit?->visit_date,
                    'signature_count'=> $d->documentSignatures->count(),
                ])->values(),
            ];
        })->values();

        return $grouped->all();
    }

    private function schemaRequiresDoctorSignature(array $schema): bool
    {
        $fields = $schema['fields'] ?? [];
        if (empty($fields) && isset($schema['pages'])) {
            foreach ($schema['pages'] as $page) {
                if (isset($page['fields']) && is_array($page['fields'])) {
                    $fields = array_merge($fields, $page['fields']);
                }
            }
        }
        foreach ($fields as $f) {
            if (($f['type'] ?? null) === 'signature_canvas' && ($f['signer_type'] ?? null) === 'doctor') {
                return true;
            }
        }
        return false;
    }

    private function signerIdentityKey(array $data): string
    {
        if (!empty($data['signer_user_id']))    return 'user:' . $data['signer_user_id'];
        if (!empty($data['signer_patient_id'])) return 'patient:' . $data['signer_patient_id'];
        if (!empty($data['signer_external_identity'])) {
            // Normalisasi: encode JSON dengan sort key supaya stable hash.
            $arr = $data['signer_external_identity'];
            ksort($arr);
            return 'ext:' . json_encode($arr, JSON_UNESCAPED_UNICODE);
        }
        return 'anon';
    }

    private function assertSignerIdentity(string $signerType, array $data): void
    {
        $needsUser     = in_array($signerType, ['doctor', 'nurse', 'staff'], true);
        $needsPatient  = $signerType === 'patient';
        $needsExternal = in_array($signerType, ['witness', 'guardian'], true);

        if ($needsUser && empty($data['signer_user_id'])) {
            throw new RuntimeException("signer_type='{$signerType}' butuh signer_user_id.");
        }
        if ($needsPatient && empty($data['signer_patient_id'])) {
            throw new RuntimeException("signer_type='patient' butuh signer_patient_id.");
        }
        if ($needsExternal) {
            $ext = $data['signer_external_identity'] ?? null;
            if (!is_array($ext) || empty($ext['nama'])) {
                throw new RuntimeException("signer_type='{$signerType}' butuh signer_external_identity dengan minimal field 'nama'.");
            }
        }
    }
}
