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
     * OUTPUT/HYBRID preview: render HTML untuk satu (template_code, visit_id).
     *
     * Kalau $payload kosong, otomatis muat static_payload dari patient_document
     * TERBARU (non-final) untuk (visit, code) — supaya preview "Cetak" pada form
     * HYBRID menampilkan jawaban editable yang baru di-submit (bukan kosong).
     *
     * Ini DRY-RUN (tidak persist & tidak menanam stempel TTD). Placeholder
     * signature/sisa ({{ttd_*}}, {{qr_verifikasi}}, dst) dibersihkan agar preview
     * rapi; embed bukti TTD + QR hanya terjadi di finalize() pada snapshot final.
     */
    public function render(string $code, int|string $visitId, array $payload = []): string
    {
        $template = $this->findActiveTemplateByCode($code);

        if (empty($payload)) {
            $doc = PatientDocument::query()
                ->where('visit_id', $visitId)
                ->where('template_code', $code)
                ->orderByDesc('created_at')
                ->first();
            $stored = $doc?->signatures['static_payload'] ?? null;
            if (is_array($stored)) {
                $payload = $stored;
            }
        }

        $html = $this->renderer->render($template, $visitId, $payload);

        // Preview only: buang placeholder yang tersisa (signature {{ttd_*}} dan
        // {{qr_verifikasi}} dibiarkan utuh oleh renderer untuk embed saat finalize).
        return preg_replace('/\{\{\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\}\}/', '', $html) ?? $html;
    }

    /**
     * HYBRID/INPUT prefill: nilai awal field editable diambil dari data klinis
     * yang sudah ada (anti "kerja dua kali"). Hanya field yang punya konfigurasi
     * `prefill` (atau `default`) yang di-resolve; field display-only & signature
     * diabaikan.
     *
     * Bentuk konfigurasi di field_schema:
     *   'prefill' => ['via' => 'db'|'aggregate'|'clinic'|'static',
     *                 'source' => 'doctorExamination.anamnese',
     *                 'format' => 'items_pretty',        // aggregate saja
     *                 'value'  => 'RS Mata Prima Vision'] // static literal
     *
     * Nilai prefill HANYA jadi default UI; saat submit, field editable (binding
     * kind 'static') tersimpan ke static_payload dokumen — TIDAK menulis balik ke
     * sumber klinis (lihat keputusan COB/klaim di Docs/PLAN-KATALOG-FORMULIR-RM.md).
     *
     * @return array{defaults: array<string,mixed>, sources: array<string,mixed>}
     */
    public function prefill(string $code, int|string $visitId): array
    {
        $template = $this->findActiveTemplateByCode($code);
        $visit = Visit::query()->findOrFail($visitId);

        $resolver = new BindingResolver();
        $defaults = [];

        foreach ($this->flattenSchemaFields($template->field_schema ?? []) as $field) {
            $key = $field['key'] ?? null;
            if (!is_string($key) || $key === '') continue;

            $prefill = $field['prefill'] ?? null;
            if (!is_array($prefill)) continue;

            $via = $prefill['via'] ?? 'db';
            $resolved = $resolver->resolve($visit, [
                'kind'   => $via,
                'source' => (string) ($prefill['source'] ?? ''),
                'format' => $prefill['format'] ?? null,
                'value'  => $prefill['value'] ?? null,
            ]);

            if ($resolved !== null && $resolved !== '') {
                $defaults[$key] = $resolved;
            }
        }

        // sources == defaults (guardrail audit: nilai sumber = nilai prefill awal;
        // divergensi vs hasil edit dokter dapat direkonstruksi dari kedua sisi).
        return ['defaults' => $defaults, 'sources' => $defaults];
    }

    /** Ratakan field_schema (single_page / multi_page) → flat list. */
    private function flattenSchemaFields(array $schema): array
    {
        if (isset($schema['fields']) && is_array($schema['fields'])) {
            return $schema['fields'];
        }
        $all = [];
        if (isset($schema['pages']) && is_array($schema['pages'])) {
            foreach ($schema['pages'] as $page) {
                if (isset($page['fields']) && is_array($page['fields'])) {
                    array_push($all, ...$page['fields']);
                }
            }
        }
        return $all;
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
                    // field_schema + custom_component_name WAJIB dikirim: FormRMRenderer
                    // membangun field input (tab "Isi Data") dari sini. Tanpa ini, form
                    // HYBRID/INPUT tampil kosong (hanya tombol Simpan) — user tak bisa isi.
                    'field_schema'          => $t->field_schema,
                    'custom_component_name' => $t->custom_component_name,
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
            // Sumber schema untuk cek "wajib" bisa di-OVERRIDE per-dokumen lewat
            // signatures.field_schema_override — dipakai saat slot signer tertentu
            // bersifat KONDISIONAL (mis. laporan bedah tanpa anestesi → slot TTD
            // anestesi di-set required=false). extractSignatureFieldMap + render
            // tetap pakai field_schema TEMPLATE (peta placeholder & layout sama).
            $signerSchema = $doc->signatures['field_schema_override'] ?? ($template->field_schema ?? []);
            $missing = $this->missingRequiredSigners($signerSchema, $doc->id);
            if (!empty($missing)) {
                throw new RuntimeException('Belum bisa finalize — signature wajib belum lengkap: ' . implode(', ', $missing));
            }

            // Document payload = jawaban user (scored_radio, static field) yang
            // disimpan di signatures.static_payload saat submit.
            $payload = $doc->signatures['static_payload'] ?? [];

            // Render dengan payload (computed fields ter-resolve).
            $html = $this->renderer->render($template, $doc->visit_id, $payload);

            // QR verifikasi: buat/refresh DocumentVerification SEBELUM embed,
            // supaya stempel TTD (mode PIN) & footer bisa menanam URL token.
            // document_hash di-update di akhir (setelah HTML final terbentuk).
            $verification = $this->ensureVerification($doc->id);
            $verifyUrl = $verification->verification_url;

            // Embed bukti TTD inline ke placeholder {{ttd_*}}:
            //   - mode DRAW → goresan SVG
            //   - mode PIN  → kotak stempel "Ditandatangani elektronik" + QR
            $schema = $template->field_schema ?? [];
            $fieldMap = $this->renderer->extractSignatureFieldMap($schema);
            $sigByType = \App\Models\DocumentSignature::query()
                ->where('patient_document_id', $doc->id)
                ->get()
                ->keyBy('signer_type');
            $html = $this->renderer->embedSignatures($html, $fieldMap, $sigByType->all(), $verifyUrl);

            // Footer QR opsional: kalau template punya placeholder {{qr_verifikasi}},
            // tanam QR + teks pindai-untuk-verifikasi.
            $html = $this->embedFooterQr($html, $verifyUrl);

            // Penanda revisi: dokumen hasil "generate ulang" diberi banner agar
            // jelas ini koreksi, bukan versi asli.
            if ((int) ($doc->revision ?? 0) > 0) {
                $html = $this->prependRevisionBanner($html, (int) $doc->revision);
            }

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

            // Sinkronkan document_hash verifikasi dengan integrity hash final.
            $verification->document_hash = $hash;
            $verification->is_valid      = true;
            $verification->save();

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
                    'verification_token' => $verification->verification_token,
                ],
            );

            return $doc;
        });
    }

    /**
     * Override/regenerasi rendered_html dokumen yang SUDAH final dengan template +
     * resolver/wiring TERKINI, secara IN-PLACE (tanpa membuat versi baru). Jawaban
     * manual dokter (static_payload) & tanda tangan yang sudah ada DIPERTAHANKAN dan
     * di-embed ulang. `revision` dinaikkan + banner "REVISI ke-N" agar koreksi
     * tertelusur. Dipakai untuk memperbaiki dokumen lama yang ter-render dengan
     * wiring lama/keliru (mis. Resume Medis sebelum FormTemplateSeeder di-update).
     *
     * ⚠️ medico-legal: ini menulis ulang snapshot dokumen ber-TTD. Sah HANYA untuk
     * koreksi BUG render (data klinis tak berubah, hanya tampilannya yang dibetulkan).
     * Untuk koreksi SUBSTANSI klinis gunakan reviseDocument() (versi baru + TTD ulang).
     */
    public function regenerateFinalized(string $patientDocumentId): PatientDocument
    {
        return DB::transaction(function () use ($patientDocumentId) {
            /** @var PatientDocument $doc */
            $doc = PatientDocument::query()->lockForUpdate()->findOrFail($patientDocumentId);

            if (!in_array($doc->status, ['FINALIZED', 'FINAL'], true)) {
                throw new RuntimeException('Hanya dokumen final yang bisa diregenerasi in-place.');
            }
            if ($doc->template_code === null || $doc->visit_id === null) {
                throw new RuntimeException('Dokumen tidak ter-link template_code/visit_id.');
            }

            $template = $this->findActiveTemplateByCode($doc->template_code);
            $payload  = $doc->signatures['static_payload'] ?? [];

            // Render ulang dari template + data terkini (aggregate/db ter-resolve lagi),
            // jawaban manual dokter dipertahankan via static_payload.
            $html = $this->renderer->render($template, $doc->visit_id, $payload);

            // Embed ulang TTD yang sudah ada + QR verifikasi (pertahankan tanda tangan).
            $verification = $this->ensureVerification($doc->id);
            $verifyUrl    = $verification->verification_url;
            $fieldMap = $this->renderer->extractSignatureFieldMap($template->field_schema ?? []);
            $sigByType = \App\Models\DocumentSignature::query()
                ->where('patient_document_id', $doc->id)
                ->get()
                ->keyBy('signer_type');
            $html = $this->renderer->embedSignatures($html, $fieldMap, $sigByType->all(), $verifyUrl);
            $html = $this->embedFooterQr($html, $verifyUrl);

            $newRevision = (int) ($doc->revision ?? 0) + 1;
            $html = $this->prependRevisionBanner($html, $newRevision);

            $sigIds = $sigByType->pluck('signature_id')->sort()->values()->all();
            $hash = hash('sha256', $html . '|' . implode(',', $sigIds) . '|' . $doc->id);

            $doc->rendered_html        = $html;
            $doc->rendered_html_gz     = null;
            $doc->template_version     = $template->version;
            $doc->revision             = $newRevision;
            $doc->final_integrity_hash = $hash;
            $doc->save();

            $verification->document_hash = $hash;
            $verification->is_valid      = true;
            $verification->save();

            FormRegistryAudit::record(
                'FORM_DOC_REGENERATED',
                model: 'PatientDocument',
                modelId: $doc->id,
                description: "Regenerasi in-place template={$template->code} v{$template->version} (revisi {$newRevision})",
                context: [
                    'template_code' => $template->code,
                    'revision'      => $newRevision,
                ],
            );

            return $doc;
        });
    }

    /**
     * Buat/ambil DocumentVerification untuk dokumen (idempoten by patient_document_id).
     * Token UUID acak + URL ke endpoint verifikasi publik. Hash diisi/diupdate
     * oleh pemanggil setelah HTML final terbentuk.
     */
    private function ensureVerification(string $patientDocumentId): \App\Models\DocumentVerification
    {
        $existing = \App\Models\DocumentVerification::query()
            ->where('patient_document_id', $patientDocumentId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $token = (string) \Illuminate\Support\Str::uuid();
        return \App\Models\DocumentVerification::create([
            'patient_document_id' => $patientDocumentId,
            'verification_token'  => $token,
            'verification_url'    => url('/api/v1/rekam-medis/verifikasi/' . $token),
            'document_hash'       => '',     // diisi oleh finalize() setelah hash final
            'is_valid'            => true,
            'scan_count'          => 0,
        ]);
    }

    /** Banner kecil "REVISI ke-N · tanggal" di atas dokumen hasil revisi. */
    private function prependRevisionBanner(string $html, int $revision): string
    {
        $date = now()->timezone('Asia/Jakarta')->format('d/m/Y');
        $banner = '<div style="text-align:right;font-size:10px;font-weight:700;color:#b45309;'
            . 'padding:2px 18px;border-bottom:1px dashed #f0c98a;background:#fffaf2;">'
            . 'REVISI ke-' . $revision . ' · ' . $date . '</div>';
        return $banner . $html;
    }

    /**
     * Ganti placeholder {{qr_verifikasi}} (kalau ada di template) dengan blok QR
     * + teks pindai-untuk-verifikasi. Kalau template tidak memuat placeholder,
     * HTML dikembalikan apa adanya (QR per-stempel sudah cukup).
     */
    private function embedFooterQr(string $html, ?string $verifyUrl): string
    {
        if (!str_contains($html, '{{qr_verifikasi}}') && !str_contains($html, '{{ qr_verifikasi }}')) {
            return $html;
        }
        $block = '';
        if ($verifyUrl) {
            $block = '<div style="display:inline-flex;flex-direction:column;align-items:center;gap:4px;">'
                . '<div style="width:90px;height:90px;">' . QrCodeHelper::svg($verifyUrl, 90) . '</div>'
                . '<div style="font-size:9px;color:#666;max-width:120px;text-align:center;">Pindai untuk memverifikasi keaslian dokumen</div>'
                . '</div>';
        }
        return preg_replace('/\{\{\s*qr_verifikasi\s*\}\}/', $block, $html) ?? $html;
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
     * Revisi dokumen final (koreksi via "generate ulang + TTD ulang").
     *
     * Alih-alih mengubah dokumen yang sudah TTD (immutable), dibuat dokumen
     * VERSI BARU yang menyalin metadata + jawaban (static_payload) dokumen lama;
     * status RENDERED → masuk antrian TTD lagi → dokter TTD ulang. Saat finalize,
     * isi di-render ULANG dari data terkini (mis. diagnosa/koding yang sudah
     * dikoreksi) + diberi banner "REVISI ke-N". Dokumen lama ditandai SUPERSEDED
     * tetapi rendered_html + TTD-nya DISIMPAN sebagai riwayat (tak dihapus).
     */
    public function reviseDocument(string $patientDocumentId, ?string $reason = null): PatientDocument
    {
        return DB::transaction(function () use ($patientDocumentId, $reason) {
            /** @var PatientDocument $old */
            $old = PatientDocument::query()->lockForUpdate()->findOrFail($patientDocumentId);

            if (!in_array($old->status, ['FINALIZED', 'FINAL'], true)) {
                throw new RuntimeException('Hanya dokumen yang sudah final yang bisa direvisi.');
            }

            // Salin jawaban editable (static_payload) supaya isi manual tak hilang.
            // field_schema_override tidak relevan untuk versi baru.
            $sig = is_array($old->signatures) ? $old->signatures : [];
            unset($sig['field_schema_override']);
            if ($reason !== null && $reason !== '') {
                $sig['revision_reason'] = $reason;
            }

            $new = PatientDocument::create([
                'patient_id'             => $old->patient_id,
                'visit_id'               => $old->visit_id,
                'bpjs_claim_id'          => $old->bpjs_claim_id,
                'document_type_id'       => $old->document_type_id,
                'template_code'          => $old->template_code,
                'template_version'       => $old->template_version,
                'status'                 => 'RENDERED',
                'created_by_station'     => 'revisi',
                'signatures'             => empty($sig) ? null : $sig,
                'claim_coding_hash'      => $old->claim_coding_hash,
                'revision'               => (int) ($old->revision ?? 0) + 1,
                'supersedes_document_id' => $old->id,
            ]);

            // Lama → SUPERSEDED (rendered_html + signatures DIPERTAHANKAN = riwayat).
            $old->update(['status' => 'SUPERSEDED']);

            FormRegistryAudit::record(
                'FORM_DOC_REVISED',
                model: 'PatientDocument',
                modelId: $new->id,
                description: "Revisi ke-{$new->revision} dari dokumen {$old->id}",
                context: [
                    'supersedes_document_id' => $old->id,
                    'revision'               => $new->revision,
                    'template_code'          => $old->template_code,
                    'reason'                 => $reason,
                ],
            );

            return $new;
        });
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
