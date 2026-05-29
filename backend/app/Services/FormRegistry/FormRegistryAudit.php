<?php

namespace App\Services\FormRegistry;

use App\Models\SystemLog;

/**
 * Helper audit log untuk Form Registry (Fase 6).
 *
 * Wrapper `system_logs` table existing dengan action namespace `FORM_*` supaya
 * audit Form Registry mudah di-query (mis. WHERE action LIKE 'FORM_%').
 *
 * Event yang di-record:
 *   - FORM_TEMPLATE_CREATED    — saat admin buat template baru (model=DocumentTemplate)
 *   - FORM_TEMPLATE_UPDATED    — saat admin edit template (model=DocumentTemplate)
 *   - FORM_TEMPLATE_ACTIVATED  — saat template di-activate (code locked)
 *   - FORM_TEMPLATE_DEACTIVATED — saat template di-deactivate
 *   - FORM_DOC_SUBMITTED       — saat INPUT submit (model=PatientDocument)
 *   - FORM_DOC_RENDERED        — saat manual mark-rendered
 *   - FORM_DOC_FINALIZED       — saat finalize (snapshot lock)
 *   - FORM_SIG_CAPTURED        — saat signature di-capture (model=DocumentSignature)
 *   - FORM_ADDENDUM_CREATED    — saat addendum dibuat
 *
 * IP address & user agent ambil dari Request facade — kalau di-call dari CLI
 * (mis. seeder), $request->ip() return null tanpa error.
 */
final class FormRegistryAudit
{
    public static function record(
        string $action,
        ?string $model = null,
        ?string $modelId = null,
        ?string $description = null,
        ?array $context = null,
    ): void {
        try {
            $req = request();
            SystemLog::create([
                'user_id'     => auth('api')->id(),
                'action'      => $action,
                'model'       => $model,
                'model_id'    => $modelId,
                'description' => self::buildDescription($description, $context),
                'ip_address'  => $req?->ip(),
                'user_agent'  => $req?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            // Audit log MUST NEVER bikin operasi utama gagal — swallow exception.
            // Log to error channel di-handle di tingkat aplikasi (Sentry, dll).
            report($e);
        }
    }

    /**
     * Append context dict ke description supaya audit lookup mudah.
     */
    private static function buildDescription(?string $base, ?array $context): ?string
    {
        if (empty($context)) return $base;
        $ctx = json_encode($context, JSON_UNESCAPED_UNICODE);
        return $base ? "{$base} | ctx={$ctx}" : "ctx={$ctx}";
    }

    /**
     * Helper: filter SystemLog query untuk Form Registry events saja.
     */
    public static function queryForDocument(string $patientDocumentId)
    {
        return SystemLog::query()
            ->where('action', 'like', 'FORM_%')
            ->where(function ($q) use ($patientDocumentId) {
                // Direct: action FORM_DOC_* model_id = doc id
                $q->where('model_id', $patientDocumentId)
                  // Indirect: signature/addendum yang context-nya mention doc id
                  ->orWhere('description', 'like', '%"patient_document_id":"' . $patientDocumentId . '"%');
            })
            ->orderByDesc('created_at');
    }
}
