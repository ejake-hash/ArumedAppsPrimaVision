<?php

namespace App\Services;

use App\Models\MarketingEvent;
use App\Models\MarketingEventParticipant;
use App\Models\PartnershipAgreement;
use App\Models\SystemLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * CRUD master marketing: Monitoring Kerjasama (PKS) & Program/Event — plus sinkron
 * peserta event dari Google Sheet. Pola mengikuti MasterDataService (log audit).
 */
class MarketingMasterService
{
    public function __construct(
        private readonly Request $request,
        private readonly GoogleSheetCsvService $sheets,
    ) {}

    // ═══════════════════════════ MONITORING KERJASAMA ═══════════════════════════

    public function indexKerjasama(array $filters = []): LengthAwarePaginator
    {
        $q = PartnershipAgreement::query()->with('insurer');

        if (! empty($filters['search'])) {
            $kw = trim($filters['search']);
            $q->where(function ($w) use ($kw) {
                $w->where('partner_name', 'ilike', "%{$kw}%")
                  ->orWhere('pks_number', 'ilike', "%{$kw}%");
            });
        }
        if (! empty($filters['partner_type'])) {
            $q->where('partner_type', $filters['partner_type']);
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $q->where('is_active', (bool) $filters['is_active']);
        }

        return $q->orderBy('partner_name')->paginate($filters['per_page'] ?? 50);
    }

    public function storeKerjasama(array $data): PartnershipAgreement
    {
        $row = PartnershipAgreement::create($this->cleanKerjasama($data));
        $this->log('CREATE_KERJASAMA', PartnershipAgreement::class, $row->id);

        return $row->load('insurer');
    }

    public function updateKerjasama(string $id, array $data): PartnershipAgreement
    {
        $row = PartnershipAgreement::findOrFail($id);
        $row->update($this->cleanKerjasama($data));
        $this->log('UPDATE_KERJASAMA', PartnershipAgreement::class, $id);

        return $row->fresh('insurer');
    }

    public function deleteKerjasama(string $id): void
    {
        $row = PartnershipAgreement::findOrFail($id);
        $row->delete();
        $this->log('DELETE_KERJASAMA', PartnershipAgreement::class, $id);
    }

    private function cleanKerjasama(array $data): array
    {
        // String opsional kosong → null.
        foreach (['insurer_id', 'pks_number', 'pks_start_date', 'addendum_date', 'pks_end_date', 'pic_name', 'pic_phone', 'notes'] as $k) {
            if (array_key_exists($k, $data) && trim((string) $data[$k]) === '') {
                $data[$k] = null;
            }
        }

        return $data;
    }

    // ═══════════════════════════ PROGRAM & EVENT ═══════════════════════════

    public function indexEvent(array $filters = []): LengthAwarePaginator
    {
        $q = MarketingEvent::query()->withCount('participants');

        if (! empty($filters['search'])) {
            $kw = trim($filters['search']);
            $q->where('name', 'ilike', "%{$kw}%");
        }

        return $q->orderByDesc('event_date')->orderBy('name')->paginate($filters['per_page'] ?? 50);
    }

    public function storeEvent(array $data): MarketingEvent
    {
        $row = MarketingEvent::create($this->cleanEvent($data));
        $this->log('CREATE_EVENT', MarketingEvent::class, $row->id);

        return $row->loadCount('participants');
    }

    public function updateEvent(string $id, array $data): MarketingEvent
    {
        $row = MarketingEvent::findOrFail($id);
        $row->update($this->cleanEvent($data));
        $this->log('UPDATE_EVENT', MarketingEvent::class, $id);

        return $row->fresh()->loadCount('participants');
    }

    public function deleteEvent(string $id): void
    {
        $row = MarketingEvent::findOrFail($id);
        $row->delete(); // peserta ikut terhapus (cascade FK)
        $this->log('DELETE_EVENT', MarketingEvent::class, $id);
    }

    private function cleanEvent(array $data): array
    {
        foreach (['event_date', 'location', 'description', 'participant_sheet_url', 'participant_gid'] as $k) {
            if (array_key_exists($k, $data) && trim((string) $data[$k]) === '') {
                $data[$k] = null;
            }
        }

        return $data;
    }

    /** Peserta satu event (paginated). */
    public function participants(string $eventId, int $perPage = 100): LengthAwarePaginator
    {
        MarketingEvent::findOrFail($eventId);

        return MarketingEventParticipant::query()
            ->where('event_id', $eventId)
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Sinkron peserta dari Google Sheet event. Idempotent (upsert by event+row_hash).
     *
     * @return array{ok:bool,fetched:int,inserted:int,message:?string}
     */
    public function syncParticipants(MarketingEvent $event): array
    {
        if (! $event->participant_sheet_url) {
            return ['ok' => false, 'fetched' => 0, 'inserted' => 0, 'message' => 'Event tidak punya URL Sheet peserta'];
        }

        $res = $this->sheets->fetchAssoc($event->participant_sheet_url, $event->participant_gid);
        if (! $res['ok']) {
            return ['ok' => false, 'fetched' => 0, 'inserted' => 0, 'message' => $res['message']];
        }

        $nameKey  = $this->guessKey($res['header'], ['nama', 'name']);
        $phoneKey = $this->guessKey($res['header'], ['hp', 'telp', 'phone', 'wa', 'no. hp', 'nomor']);

        $inserted = 0;
        $now = Carbon::now();
        foreach ($res['rows'] as $assoc) {
            $hash = $this->sheets->rowHash($assoc);
            $created = MarketingEventParticipant::firstOrCreate(
                ['event_id' => $event->id, 'row_hash' => $hash],
                [
                    'name'      => $nameKey ? ($assoc[$nameKey] ?? null) : null,
                    'phone'     => $phoneKey ? ($assoc[$phoneKey] ?? null) : null,
                    'payload'   => $assoc,
                    'synced_at' => $now,
                ]
            );
            if ($created->wasRecentlyCreated) {
                $inserted++;
            }
        }

        $event->update(['participants_synced_at' => $now]);

        return ['ok' => true, 'fetched' => count($res['rows']), 'inserted' => $inserted, 'message' => null];
    }

    private function guessKey(array $header, array $hints): ?string
    {
        foreach ($header as $h) {
            $lo = mb_strtolower($h);
            foreach ($hints as $hint) {
                if (str_contains($lo, $hint)) {
                    return $h;
                }
            }
        }

        return null;
    }

    private function log(string $action, string $model, string $modelId): void
    {
        SystemLog::create([
            'user_id'    => auth('api')->id(),
            'action'     => $action,
            'model'      => $model,
            'model_id'   => $modelId,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }
}
