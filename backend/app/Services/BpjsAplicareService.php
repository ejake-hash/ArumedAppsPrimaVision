<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\BpjsAplicareLog;
use App\Models\Room;
use App\Services\Bpjs\BpjsClient;

/**
 * BPJS Aplicare (Aplikasi Ketersediaan Tempat Tidur — aplicaresws/rest).
 *
 * Signature SAMA dengan VClaim, namun response Aplicare = JSON POLOS (tidak
 * terenkripsi) → semua call pakai BpjsClient::request(..., encrypted: false).
 * Aplicare membalas metadata.code "1" (ref/kelas) ATAU "200" → successCodes ['1','200'].
 *
 * Cakupan (Docs/Briding Aplicare dan Apotek.docx):
 *   - GET  /ref/kelas               referensi kode kelas BPJS (NON/VVP/VIP/…)
 *   - POST /bed/update/{kodeppk}    sinkron ketersediaan satu ruang (INTI)
 *   - POST /bed/create/{kodeppk}    daftar ruang baru
 *   - POST /bed/delete/{kodeppk}    hapus ruang
 *   - GET  /bed/read/{kodeppk}/{start}/{limit}   rekonsiliasi ketersediaan
 *
 * Wiring: dipanggil non-blocking dari RanapService (admit/transfer/discharge/
 * markBedAvailable) via Job PushAplicareRoom, + command aplicare:sync sebagai
 * jaring rekonsiliasi. Gagal lapor BPJS TIDAK boleh memblok flow inap lokal;
 * cukup tercatat di bpjs_aplicare_logs.
 */
class BpjsAplicareService
{
    private BpjsClient $client;

    public function __construct(?BpjsClient $client = null)
    {
        $this->client = $client ?? BpjsClient::for('APLICARE');
    }

    public function boot(): void
    {
        $this->client = BpjsClient::for('APLICARE');
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    // Aplicare membalas metadata.code "1" untuk ref/kelas, "200" untuk lainnya.
    private const SUCCESS_CODES = ['1', '200'];

    // =========================================================================
    // REFERENSI
    // =========================================================================

    /** GET /ref/kelas — referensi kode kelas BPJS untuk mapping rooms.bpjs_kelas_code. */
    public function refKelas(): array
    {
        return $this->client->request('GET', '/ref/kelas', null, encrypted: false, successCodes: self::SUCCESS_CODES);
    }

    /**
     * Daftar kode kelas ternormalisasi {kode,nama} untuk picker UI master kamar.
     * Response: { response: { list: [ {kodekelas, namakelas}, ... ] } }.
     */
    public function refKelasOptions(): array
    {
        $res  = $this->refKelas();
        $list = $res['response']['list'] ?? [];

        return collect(is_array($list) ? $list : [])
            ->map(fn ($k) => [
                'kode' => (string) ($k['kodekelas'] ?? ''),
                'nama' => (string) ($k['namakelas'] ?? ''),
            ])
            ->filter(fn ($r) => $r['kode'] !== '')
            ->values()
            ->all();
    }

    // =========================================================================
    // BED — UPDATE / CREATE / DELETE / READ
    // =========================================================================

    /**
     * Hitung payload ketersediaan satu ruang dari okupansi NYATA bed.
     *   kapasitas = jumlah bed aktif di ruang
     *   tersedia  = jumlah bed aktif berstatus AVAILABLE
     * Gender (tersediapria/wanita/priawanita) dipetakan dari rooms.gender_policy;
     * default 0 bila ruang campur/bebas (klinik boleh tidak memakai breakdown gender).
     */
    public function buildBedPayload(Room $room): array
    {
        $beds      = $room->relationLoaded('activeBeds')
            ? $room->activeBeds
            : $room->beds()->where('is_active', true)->get();
        $kapasitas = $beds->count();
        $tersedia  = $beds->where('status', Bed::STATUS_AVAILABLE)->count();

        // koderuang: pakai kode khusus BPJS bila diisi, jika tidak fallback ke kode lokal.
        $koderuang = $room->bpjs_ruang_code ?: $room->code;

        $payload = [
            'kodekelas' => (string) $room->bpjs_kelas_code,
            'koderuang' => (string) $koderuang,
            'namaruang' => (string) $room->name,
            'kapasitas' => (string) $kapasitas,
            'tersedia'  => (string) $tersedia,
        ];

        // Breakdown gender (opsional). Ruang L → semua tersedia masuk pria, P → wanita,
        // MIX/null → priawanita. Bila tak ingin dipakai, BPJS terima ketiganya "0".
        $policy = $room->gender_policy;
        $payload['tersediapria']       = $policy === 'L' ? (string) $tersedia : '0';
        $payload['tersediawanita']     = $policy === 'P' ? (string) $tersedia : '0';
        $payload['tersediapriawanita'] = (! $policy || $policy === 'MIX') ? (string) $tersedia : '0';

        return $payload;
    }

    /** POST /bed/update/{kodeppk} — sinkron ketersediaan satu ruang. */
    public function pushRoom(Room $room): array
    {
        $payload = $this->buildBedPayload($room);
        $result  = $this->post("/bed/update/{$this->kodeppk()}", $payload);
        $this->log('UPDATE_BED', $room, $payload, $result);

        return $result;
    }

    /** POST /bed/create/{kodeppk} — daftarkan ruang baru ke Aplicare. */
    public function createRoom(Room $room): array
    {
        $payload = $this->buildBedPayload($room);
        $result  = $this->post("/bed/create/{$this->kodeppk()}", $payload);
        $this->log('CREATE_ROOM', $room, $payload, $result);

        return $result;
    }

    /** POST /bed/delete/{kodeppk} — hapus ruang dari Aplicare (saat room dihapus/nonaktif). */
    public function deleteRoom(string $kodekelas, string $koderuang, ?Room $room = null): array
    {
        $payload = ['kodekelas' => $kodekelas, 'koderuang' => $koderuang];
        $result  = $this->post("/bed/delete/{$this->kodeppk()}", $payload);
        $this->log('DELETE_ROOM', $room, $payload, $result);

        return $result;
    }

    /** GET /bed/read/{kodeppk}/{start}/{limit} — data ketersediaan untuk rekonsiliasi. */
    public function readBeds(int $start = 1, int $limit = 100): array
    {
        return $this->client->request(
            'GET',
            "/bed/read/{$this->kodeppk()}/{$start}/{$limit}",
            null,
            encrypted: false,
            successCodes: self::SUCCESS_CODES,
        );
    }

    /**
     * Sinkron SEMUA ruang aktif yang sudah dipetakan kode kelas BPJS-nya.
     * Ruang tanpa bpjs_kelas_code dilewati (kumpulkan sebagai skipped) — Aplicare
     * butuh kodekelas valid. Dipakai command rekonsiliasi aplicare:sync.
     *
     * @return array{sent:int, failed:int, skipped:int, rooms:list<array>}
     */
    public function syncAll(): array
    {
        $rooms = Room::with(['activeBeds'])
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $sent = $failed = $skipped = 0;
        $detail = [];

        foreach ($rooms as $room) {
            if (empty($room->bpjs_kelas_code)) {
                $skipped++;
                $detail[] = ['room' => $room->name, 'status' => 'SKIPPED', 'reason' => 'Kode kelas BPJS belum dipetakan'];
                continue;
            }

            $result = $this->pushRoom($room);
            $ok = $result['is_success'] ?? false;
            $ok ? $sent++ : $failed++;
            $detail[] = [
                'room'    => $room->name,
                'status'  => $ok ? 'SENT' : 'FAILED',
                'message' => $result['metaData']['message'] ?? null,
            ];
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'rooms' => $detail];
    }

    // =========================================================================
    // TEST
    // =========================================================================

    public function testConnection(): array
    {
        try {
            $result = $this->refKelas();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'system' => 'APLICARE'];
        }

        return [
            'success' => $result['is_success'] ?? false,
            'message' => $result['metaData']['message'] ?? 'Tidak ada respon',
            'system'  => 'APLICARE',
            'code'    => $result['metaData']['code'] ?? null,
        ];
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function kodeppk(): string
    {
        return $this->client->kodeFaskes();
    }

    private function post(string $path, array $body): array
    {
        return $this->client->request('POST', $path, $body, encrypted: false, successCodes: self::SUCCESS_CODES);
    }

    private function log(string $action, ?Room $room, array $request, array $result): void
    {
        BpjsAplicareLog::create([
            'room_id'          => $room?->id,
            'action'           => $action,
            'kodekelas'        => $request['kodekelas'] ?? null,
            'koderuang'        => $request['koderuang'] ?? null,
            'request_payload'  => $request,
            'response_payload' => $result['response'] ?? $result,
            'http_status'      => $result['http_status'] ?? 0,
            'is_success'       => $result['is_success'] ?? false,
            'error_message'    => ($result['is_success'] ?? false) ? null : ($result['metaData']['message'] ?? null),
        ]);
    }
}
