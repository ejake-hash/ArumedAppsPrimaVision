<?php

namespace App\Services\FormRegistry;

use App\Models\DocumentTemplate;
use App\Models\PatientDocument;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Router utama Form Registry.
 *
 * Tidak melakukan business-logic data klinis sendiri — delegate render ke
 * DocumentRenderer, binding ke BindingResolver. Untuk INPUT mode (Fase 3),
 * `submit()` akan delegate ke service domain berdasarkan binding (Dokter,
 * Perawat, Bedah, dst).
 */
final class FormRegistryService
{
    /**
     * Status flow (soft enforcement, Section 9.1 design doc):
     *   DRAFT → RENDERED → PENDING_SIGNATURE → FINALIZED
     *
     * - SignatureService::capture() auto-advance DRAFT/RENDERED → PENDING_SIGNATURE.
     * - markRendered() advance DRAFT → RENDERED (manual saat preview).
     * - finalize() validate semua required signer sudah TTD, baru FINALIZED.
     */
    public const STATUSES = ['DRAFT', 'RENDERED', 'PENDING_SIGNATURE', 'FINALIZED'];

    public function __construct(
        private readonly DocumentRenderer $renderer,
        private readonly SubmitRouter $submitRouter = new SubmitRouter(),
        private readonly SignatureService $signatures = new SignatureService(),
        private readonly ScoringEngine $scoring = new ScoringEngine(),
    ) {}

    /**
     * OUTPUT mode: render HTML final untuk satu (template_code, visit_id).
     */
    public function render(string $code, int|string $visitId): string
    {
        $template = $this->findActiveTemplateByCode($code);
        return $this->renderer->render($template, $visitId);
    }

    /**
     * INPUT mode: kembalikan field_schema untuk frontend dynamic form.
     * Fase 1: hanya schema mentah; tidak ada prefill value.
     */
    public function getSchema(string $code): array
    {
        $template = $this->findActiveTemplateByCode($code);
        return [
            'template_id'      => $template->id,
            'code'             => $template->code,
            'name'             => $template->name,
            'kind'             => $template->kind,
            'complexity_kind'  => $template->complexity_kind,
            'version'          => $template->version,
            'field_schema'     => $template->field_schema ?? [],
        ];
    }

    /**
     * Daftar template aktif yang ter-assign ke (station, section).
     * Filter berdasarkan kolom JSON `station_assignments`.
     */
    public function listByStationSection(string $station, string $section, int|string $visitId): array
    {
        // Validasi visit ada (defensive — endpoint pasti sudah passing valid id).
        if (!Visit::query()->whereKey($visitId)->exists()) {
            throw new RuntimeException("Visit {$visitId} tidak ditemukan.");
        }

        $templates = DocumentTemplate::query()
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->whereNotNull('station_assignments')
            ->get();

        $matched = $templates
            ->filter(function (DocumentTemplate $t) use ($station, $section) {
                foreach (($t->station_assignments ?? []) as $assign) {
                    if (($assign['station'] ?? null) === $station
                        && ($assign['section'] ?? null) === $section) {
                        return true;
                    }
                }
                return false;
            })
            ->values();

        // Eager-fetch existing PatientDocument terbaru untuk (visit, template_code)
        // — dipakai frontend untuk tahu status doc (FINALIZED → tombol Addendum).
        $codes = $matched->pluck('code')->all();
        $existingByCode = PatientDocument::query()
            ->where('visit_id', $visitId)
            ->whereIn('template_code', $codes)
            ->orderByDesc('created_at')
            ->get(['id', 'template_code', 'status', 'finalized_at'])
            ->keyBy('template_code');

        return $matched
            ->map(function (DocumentTemplate $t) use ($existingByCode) {
                $existing = $existingByCode->get($t->code);
                return [
                    'id'              => $t->id,
                    'code'            => $t->code,
                    'name'            => $t->name,
                    'kind'            => $t->kind,
                    'complexity_kind' => $t->complexity_kind,
                    'version'         => $t->version,
                    'assignments'     => $t->station_assignments,
                    'existing_document' => $existing ? [
                        'id'           => $existing->id,
                        'status'       => $existing->status,
                        'finalized_at' => optional($existing->finalized_at)?->toIso8601String(),
                    ] : null,
                ];
            })
            ->all();
    }

    /**
     * Finalize: snapshot rendered_html → patient_documents, status FINALIZED.
     * Idempoten — kalau sudah FINALIZED, return existing tanpa overwrite.
     */
    public function finalize(string $patientDocumentId, array $signatureIds = []): PatientDocument
    {
        return DB::transaction(function () use ($patientDocumentId, $signatureIds) {
            /** @var PatientDocument $doc */
            $doc = PatientDocument::query()->lockForUpdate()->findOrFail($patientDocumentId);

            if ($doc->status === 'FINALIZED' || $doc->finalized_at !== null) {
                // Immutable — return apa adanya.
                return $doc;
            }
            if ($doc->template_code === null) {
                throw new RuntimeException('Dokumen ini tidak ter-link ke Form Registry template (template_code kosong).');
            }
            if ($doc->visit_id === null) {
                throw new RuntimeException('Dokumen tidak punya visit_id — tidak bisa di-render.');
            }

            $template = $this->findActiveTemplateByCode($doc->template_code);

            // Validasi: semua required signature sudah ter-capture.
            $missing = $this->missingRequiredSigners($template->field_schema ?? [], $doc->id);
            if (!empty($missing)) {
                throw new RuntimeException('Belum bisa finalize — signature wajib belum lengkap: ' . implode(', ', $missing));
            }

            // Document payload = jawaban user (scored_radio, static field) yang
            // disimpan di signatures.static_payload saat submit.
            $payload = $doc->signatures['static_payload'] ?? [];

            // Render dengan payload (computed fields ter-resolve).
            $html = $this->renderer->render($template, $doc->visit_id, $payload);

            // Embed SVG signature inline ke placeholder {{ttd_*}}.
            $schema = $template->field_schema ?? [];
            $fieldMap = $this->renderer->extractSignatureFieldMap($schema);
            $sigByType = \App\Models\DocumentSignature::query()
                ->where('patient_document_id', $doc->id)
                ->get()
                ->keyBy('signer_type');
            $html = $this->renderer->embedSignatures($html, $fieldMap, $sigByType->all());

            // Hash include signature_ids di table (bukan dari param — supaya idempoten).
            $sigIds = $sigByType->pluck('signature_id')->sort()->values()->all();
            $hash = hash('sha256', $html . '|' . implode(',', $sigIds) . '|' . $doc->id);

            // Simpan HTML plain di `rendered_html` (longText) — BUKAN gzip ke
            // `rendered_html_gz`. Kolom _gz bertipe bytea di Postgres dan binding
            // string hasil gzcompress() ditolak ('invalid byte sequence for
            // encoding UTF8'). getSnapshot() sudah fallback ke plain rendered_html.
            // Dokumen RM kecil (~2-3KB) → gzip tidak memberi keuntungan berarti.
            // Pola ini selaras dengan AdmisiService::saveConsentDocument.
            $doc->rendered_html         = $html;
            $doc->rendered_html_gz      = null;
            $doc->template_version      = $template->version;
            $doc->status                = 'FINALIZED';
            $doc->finalized_at          = now();
            $doc->final_integrity_hash  = $hash;
            $doc->save();

            FormRegistryAudit::record(
                'FORM_DOC_FINALIZED',
                model: 'PatientDocument',
                modelId: $doc->id,
                description: "Finalize template={$template->code} v{$template->version}",
                context: [
                    'template_code'      => $template->code,
                    'template_version'   => $template->version,
                    'signature_ids'      => $sigIds,
                    'integrity_hash'     => $hash,
                    'rendered_html_size' => strlen($html),
                ],
            );

            return $doc;
        });
    }

    /**
     * INPUT mode submit (Fase 3).
     *
     * Alur:
     *   1. Cari template (active, by code) → validate kind != OUTPUT.
     *   2. Delegate ke SubmitRouter → sync tabel klinis per resource.
     *   3. Create patient_document DRAFT yang nge-link ke template_code +
     *      visit + static_payload (untuk audit). Status DRAFT — admin/dokter
     *      finalize terpisah (via /document/{id}/finalize).
     *   4. Return: { document_id, sync_result }
     */
    public function submit(string $code, string $visitId, array $data): array
    {
        $template = $this->findActiveTemplateByCode($code);
        if ($template->kind === 'OUTPUT') {
            throw new RuntimeException("Template '{$code}' adalah OUTPUT-only — tidak menerima submit INPUT.");
        }

        $visit = Visit::query()->findOrFail($visitId);
        $schema = $template->field_schema ?? [];

        $syncResult = $this->submitRouter->submit($visit, $schema, $data);

        // Fase 5: compute scored fields server-side (sanity check + simpan).
        // Frontend juga compute live, tapi backend otoritatif.
        $rawPayload = $syncResult['static_payload'] ?? [];
        $computed   = $this->scoring->computeAll($schema, $rawPayload);
        $staticPayload = array_merge($rawPayload, $computed);

        // Persist sebagai patient_document DRAFT — supaya bisa di-render/finalize nanti.
        $doc = PatientDocument::create([
            'patient_id'         => $visit->patient_id,
            'visit_id'           => $visit->id,
            'document_type_id'   => $template->document_type_id,
            'template_code'      => $template->code,
            'template_version'   => $template->version,
            'status'             => 'DRAFT',
            'created_by_station' => $syncResult['_station'] ?? null,
            // Simpan static payload + computed ke kolom signatures (jsonb).
            'signatures'         => empty($staticPayload) ? null : ['static_payload' => $staticPayload],
        ]);

        // Surface computed values di response — frontend bisa verifikasi server == client compute.
        $syncResult['computed'] = $computed;

        FormRegistryAudit::record(
            'FORM_DOC_SUBMITTED',
            model: 'PatientDocument',
            modelId: $doc->id,
            description: "Submit INPUT template={$template->code}",
            context: [
                'template_code'    => $template->code,
                'template_version' => $template->version,
                'visit_id'         => $visit->id,
                'patient_id'       => $visit->patient_id,
                'fields_synced'    => array_keys($syncResult['synced'] ?? []),
                'has_computed'     => !empty($computed),
            ],
        );

        return [
            'document_id' => $doc->id,
            'status'      => $doc->status,
            'sync'        => $syncResult,
        ];
    }

    /**
     * Ambil snapshot rendered_html dari patient_documents (bukan re-render).
     * Wajib untuk dokumen yang sudah FINALIZED (Section 13 design doc).
     */
    public function getSnapshot(string $patientDocumentId): array
    {
        /** @var PatientDocument $doc */
        $doc = PatientDocument::query()->findOrFail($patientDocumentId);

        // Forward-only gzip: decompress dari rendered_html_gz kalau ada,
        // fallback ke rendered_html plain (dokumen pre-gzip).
        $html = null;
        if (!empty($doc->rendered_html_gz)) {
            $raw = is_resource($doc->rendered_html_gz)
                ? stream_get_contents($doc->rendered_html_gz)
                : $doc->rendered_html_gz;
            $decompressed = @gzuncompress($raw);
            $html = $decompressed !== false ? $decompressed : null;
        }
        if ($html === null) {
            $html = $doc->rendered_html;
        }

        return [
            'id'                   => $doc->id,
            'status'               => $doc->status,
            'template_code'        => $doc->template_code,
            'template_version'     => $doc->template_version,
            'rendered_html'        => $html,
            'finalized_at'         => $doc->finalized_at,
            'final_integrity_hash' => $doc->final_integrity_hash,
        ];
    }

    /**
     * Soft transition: DRAFT → RENDERED. Dipanggil saat dokumen di-preview
     * (user kunci konten sebelum capture signature). Aman dipanggil
     * berkali-kali — idempoten.
     */
    public function markRendered(string $patientDocumentId): PatientDocument
    {
        $doc = PatientDocument::query()->findOrFail($patientDocumentId);
        if ($doc->status === 'DRAFT') {
            $doc->status = 'RENDERED';
            $doc->save();

            FormRegistryAudit::record(
                'FORM_DOC_RENDERED',
                model: 'PatientDocument',
                modelId: $doc->id,
                description: "Transition DRAFT → RENDERED",
                context: ['template_code' => $doc->template_code],
            );
        }
        return $doc;
    }

    /**
     * Edit isi dokumen DRAFT secara manual (dokter mengoreksi teks HTML hasil
     * render sebelum TTD). Hanya boleh saat status DRAFT — begitu masuk
     * RENDERED/PENDING_SIGNATURE/FINALIZED, isi terkunci (immutability dokumen
     * medis; koreksi setelah final lewat addendum).
     *
     * Menimpa langsung `rendered_html` (plain). Kolom `rendered_html_gz`
     * di-null-kan — bytea Postgres menolak string gzcompress (lihat catatan di
     * finalize()). Override ini lepas dari binding rekam medis (by design:
     * dokter mengetik bebas).
     */
    public function saveDraftContent(string $patientDocumentId, string $html): PatientDocument
    {
        $doc = PatientDocument::query()->findOrFail($patientDocumentId);

        if ($doc->status !== 'DRAFT') {
            throw new RuntimeException(
                "Isi dokumen hanya bisa diubah saat status DRAFT. Status saat ini: {$doc->status}."
            );
        }

        $doc->rendered_html    = $html;
        $doc->rendered_html_gz = null;
        $doc->save();

        FormRegistryAudit::record(
            'FORM_DOC_DRAFT_EDITED',
            model: 'PatientDocument',
            modelId: $doc->id,
            description: 'Isi DRAFT diedit manual oleh dokter',
            context: [
                'template_code'      => $doc->template_code,
                'rendered_html_size' => strlen($html),
            ],
        );

        return $doc;
    }

    /**
     * Buat addendum (koreksi post-FINALIZED). Wajib status dokumen sudah
     * FINALIZED / FINAL. Addendum sendiri perlu di-finalize via signature.
     */
    public function createAddendum(string $patientDocumentId, array $data): \App\Models\DocumentAddendum
    {
        $doc = PatientDocument::query()->findOrFail($patientDocumentId);
        if (!in_array($doc->status, ['FINALIZED', 'FINAL'], true)) {
            throw new RuntimeException('Addendum hanya bisa dibuat untuk dokumen yang sudah FINALIZED.');
        }

        $add = \App\Models\DocumentAddendum::create([
            'patient_document_id' => $doc->id,
            'alasan'              => $data['alasan'],
            'isi_koreksi'         => $data['isi_koreksi'],
            'created_by'          => auth('api')->id(),
        ]);

        FormRegistryAudit::record(
            'FORM_ADDENDUM_CREATED',
            model: 'DocumentAddendum',
            modelId: $add->id,
            description: "Addendum untuk dokumen {$doc->id}",
            context: [
                'patient_document_id' => $doc->id,
                'alasan'              => substr((string) $data['alasan'], 0, 200),
            ],
        );

        return $add;
    }

    /**
     * Daftar signer_type yang required tapi belum ada signature-nya.
     * @return list<string>
     */
    private function missingRequiredSigners(array $schema, string $patientDocumentId): array
    {
        // Kumpulkan required signer types dari field_schema.
        $required = [];
        $fields = $schema['fields'] ?? [];
        if (empty($fields) && isset($schema['pages'])) {
            foreach ($schema['pages'] as $page) {
                if (isset($page['fields']) && is_array($page['fields'])) {
                    array_push($fields, ...$page['fields']);
                }
            }
        }
        foreach ($fields as $f) {
            if (($f['type'] ?? null) !== 'signature_canvas') continue;
            if (!($f['required'] ?? false)) continue;
            $st = $f['signer_type'] ?? null;
            if ($st && !in_array($st, $required, true)) {
                $required[] = $st;
            }
        }
        if (empty($required)) return [];

        // Yang sudah ada signature-nya.
        $captured = \App\Models\DocumentSignature::query()
            ->where('patient_document_id', $patientDocumentId)
            ->pluck('signer_type')
            ->unique()
            ->all();

        return array_values(array_diff($required, $captured));
    }

    private function findActiveTemplateByCode(string $code): DocumentTemplate
    {
        $template = DocumentTemplate::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->first();

        if ($template === null) {
            throw new RuntimeException("Form template '{$code}' tidak ditemukan atau tidak aktif.");
        }
        return $template;
    }
}
