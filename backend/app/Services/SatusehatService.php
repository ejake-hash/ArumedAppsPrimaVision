<?php

namespace App\Services;

use App\Models\IntegrationConfig;
use App\Models\SatusehatResourceLog;
use App\Models\SatusehatSyncLog;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * Satu Sehat (SATUSEHAT) — FHIR R4 integration.
 *
 * PLACEHOLDER — implementasi setelah credentials (client_id, client_secret,
 * organization_id) tersedia dari dashboard Satu Sehat.
 *
 * Flow:
 *   Batch auto: setiap 23:59 via Laravel Scheduler
 *   Retry auto: setiap 01:00 jika batch sebelumnya PARTIAL/FAILED
 *   Manual:     POST /integrasi/satusehat/sync-manual
 *
 * 5 FHIR R4 resources wajib per kunjungan:
 *   1. Encounter          — data kunjungan
 *   2. Condition          — diagnosis (ICD-10)
 *   3. MedicationRequest  — resep obat
 *   4. MedicationDispense — pemberian obat (farmasi)
 *   5. ImagingStudy       — hasil penunjang (OCT, USG, dll)
 *
 * @see https://dev.satusehat.kemkes.go.id/
 */
class SatusehatService
{
    private ?IntegrationConfig $config = null;
    private ?string $accessToken       = null;

    public function boot(): void
    {
        $this->config = IntegrationConfig::where('system_name', 'SATUSEHAT')->first();
    }

    public function isEnabled(): bool
    {
        return $this->config?->is_enabled ?? false;
    }

    // =========================================================================
    // BATCH SYNC (dipanggil oleh Laravel Scheduler 23:59)
    // =========================================================================

    /**
     * Sync all unsynced/failed visits for today.
     * Called by: app/Console/Kernel.php or Laravel Scheduler
     */
    public function batchSync(string $syncType = 'AUTO'): SatusehatSyncLog
    {
        $this->assertEnabled();

        $syncLog = SatusehatSyncLog::create([
            'sync_date'    => today(),
            'sync_type'    => $syncType,
            'status'       => 'RUNNING',
            'total_sent'   => 0,
            'total_failed' => 0,
            'retry_count'  => 0,
        ]);

        try {
            $visits = Visit::where('satusehat_sync_status', 'PENDING')
                ->orWhere('satusehat_sync_status', 'FAILED')
                ->whereDate('visit_date', today())
                ->where('current_station', 'SELESAI')
                ->get();

            $sent   = 0;
            $failed = 0;

            foreach ($visits as $visit) {
                $result = $this->syncVisit($visit, $syncLog->id);
                $result ? $sent++ : $failed++;
            }

            $status = match (true) {
                $failed === 0            => 'SUCCESS',
                $sent === 0             => 'FAILED',
                default                  => 'PARTIAL',
            };

            $syncLog->update([
                'status'       => $status,
                'total_sent'   => $sent,
                'total_failed' => $failed,
                'next_retry_at' => $status !== 'SUCCESS'
                    ? now()->addHours(2)->setTime(1, 0)
                    : null,
            ]);
        } catch (\Throwable $e) {
            $syncLog->update(['status' => 'FAILED']);
        }

        return $syncLog->fresh();
    }

    // =========================================================================
    // PER-VISIT SYNC
    // =========================================================================

    public function syncVisit(Visit $visit, ?string $syncLogId = null): bool
    {
        $allSuccess = true;

        $resources = [
            'Encounter',
            'Condition',
            'MedicationRequest',
            'MedicationDispense',
            'ImagingStudy',
        ];

        foreach ($resources as $resourceType) {
            $success = $this->sendResource($visit, $resourceType, $syncLogId);
            if (! $success) {
                $allSuccess = false;
            }
        }

        $visit->update([
            'satusehat_sync_status' => $allSuccess ? 'SYNCED' : 'FAILED',
            'satusehat_synced_at'   => $allSuccess ? now() : null,
        ]);

        return $allSuccess;
    }

    // =========================================================================
    // RESOURCE SENDERS (FHIR R4)
    // =========================================================================

    /** POST /fhir-r4/Encounter */
    public function sendEncounter(Visit $visit): array
    {
        $fhirPayload = $this->buildEncounterPayload($visit);

        return $this->postFhir('Encounter', $fhirPayload, $visit->id);
    }

    /** POST /fhir-r4/Condition */
    public function sendCondition(Visit $visit): array
    {
        $fhirPayload = $this->buildConditionPayload($visit);

        return $this->postFhir('Condition', $fhirPayload, $visit->id);
    }

    /** POST /fhir-r4/MedicationRequest */
    public function sendMedicationRequest(Visit $visit): array
    {
        $fhirPayload = $this->buildMedicationRequestPayload($visit);

        return $this->postFhir('MedicationRequest', $fhirPayload, $visit->id);
    }

    /** POST /fhir-r4/MedicationDispense */
    public function sendMedicationDispense(Visit $visit): array
    {
        $fhirPayload = $this->buildMedicationDispensePayload($visit);

        return $this->postFhir('MedicationDispense', $fhirPayload, $visit->id);
    }

    /** POST /fhir-r4/ImagingStudy */
    public function sendImagingStudy(Visit $visit): array
    {
        $fhirPayload = $this->buildImagingStudyPayload($visit);

        return $this->postFhir('ImagingStudy', $fhirPayload, $visit->id);
    }

    // =========================================================================
    // RETRY
    // =========================================================================

    /**
     * Retry a specific sync log (PARTIAL or FAILED).
     * Called manually from IntegrasiController or by scheduler at 01:00.
     */
    public function retry(string $syncLogId): SatusehatSyncLog
    {
        $syncLog = SatusehatSyncLog::findOrFail($syncLogId);

        if ($syncLog->status === 'SUCCESS') {
            throw new \Exception('Sync sudah berhasil, tidak perlu retry.', 422);
        }

        $failedVisits = SatusehatResourceLog::where('satusehat_sync_log_id', $syncLogId)
            ->where('status', 'FAILED')
            ->with('visit')
            ->get()
            ->pluck('visit')
            ->unique('id');

        $sent   = 0;
        $failed = 0;

        foreach ($failedVisits as $visit) {
            if (! $visit) {
                continue;
            }
            $result = $this->syncVisit($visit, $syncLogId);
            $result ? $sent++ : $failed++;
        }

        $newStatus = $failed === 0 ? 'SUCCESS' : 'PARTIAL';

        $syncLog->update([
            'status'       => $newStatus,
            'retry_count'  => $syncLog->retry_count + 1,
            'total_sent'   => $syncLog->total_sent + $sent,
            'total_failed' => max(0, $syncLog->total_failed - $sent),
            'next_retry_at' => $newStatus !== 'SUCCESS' ? now()->addHours(2) : null,
        ]);

        return $syncLog->fresh();
    }

    public function testConnection(): array
    {
        $this->assertEnabled();

        // TODO: POST /oauth2/v1/accesstoken → get Bearer token
        // If token returned → connection OK

        return [
            'success' => true,
            'system'  => 'SATUSEHAT',
            'message' => 'Satu Sehat OAuth test — placeholder. Implement token request.',
        ];
    }

    // =========================================================================
    // PRIVATE — FHIR payload builders (all placeholders)
    // =========================================================================

    private function buildEncounterPayload(Visit $visit): array
    {
        return [
            'resourceType' => 'Encounter',
            'status'       => 'finished',
            'class'        => ['code' => 'AMB'],
            'subject'      => ['reference' => "Patient/{$visit->patient_id}"],
            '__placeholder' => true,
        ];
    }

    private function buildConditionPayload(Visit $visit): array
    {
        return [
            'resourceType' => 'Condition',
            'subject'      => ['reference' => "Patient/{$visit->patient_id}"],
            'encounter'    => ['reference' => "Encounter/{$visit->satusehat_encounter_id}"],
            '__placeholder' => true,
        ];
    }

    private function buildMedicationRequestPayload(Visit $visit): array
    {
        return ['resourceType' => 'MedicationRequest', '__placeholder' => true];
    }

    private function buildMedicationDispensePayload(Visit $visit): array
    {
        return ['resourceType' => 'MedicationDispense', '__placeholder' => true];
    }

    private function buildImagingStudyPayload(Visit $visit): array
    {
        return ['resourceType' => 'ImagingStudy', '__placeholder' => true];
    }

    private function postFhir(string $resourceType, array $payload, string $visitId, ?string $syncLogId = null): array
    {
        // TODO: get OAuth token, POST to /fhir-r4/{resourceType}
        // $response = Http::withToken($this->getAccessToken())
        //     ->post("{$this->baseUrl()}/fhir-r4/{$resourceType}", $payload);

        $mockResponse = ['resourceType' => $resourceType, 'id' => 'placeholder-id-' . uniqid()];
        $isSuccess    = true;

        SatusehatResourceLog::create([
            'satusehat_sync_log_id' => $syncLogId,
            'visit_id'              => $visitId,
            'resource_type'         => $resourceType,
            'fhir_payload'          => $payload,
            'response_payload'      => $mockResponse,
            'http_status'           => 201,
            'status'                => $isSuccess ? 'SUCCESS' : 'FAILED',
        ]);

        return $mockResponse;
    }

    private function sendResource(Visit $visit, string $resourceType, ?string $syncLogId): bool
    {
        try {
            $this->postFhir($resourceType, ['resourceType' => $resourceType, '__placeholder' => true], $visit->id, $syncLogId);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertEnabled(): void
    {
        $this->boot();

        if (! $this->isEnabled()) {
            throw new \Exception('Integrasi Satu Sehat belum diaktifkan.', 503);
        }
    }
}
