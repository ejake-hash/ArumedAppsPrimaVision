<?php

namespace Tests\Feature\Penunjang;

use App\Models\PenunjangIngestInbox;
use App\Services\PenunjangIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Karakterisasi idempotensi ingest hasil penunjang (jalur Inbox).
 *
 * Temuan audit 30 Jun 2026: cek-duplikat lalu create di PenunjangIngestService::ingest
 * tidak atomik & tanpa lock; external_ref hanya ber-index biasa (bukan unique) → dua
 * ingest dengan external_ref sama (retry bridge konkuren) bisa lolos cek bersamaan dan
 * menggandakan Inbox/DiagnosticResult.
 *
 * Test ini mengunci KONTRAK idempotensi pada jalur sekuensial (retry beruntun TIDAK
 * boleh menggandakan). Race konkuren tidak dapat direproduksi single-thread; fix
 * (pg_advisory_xact_lock + transaksi) men-serialize ingest ber-ref sama agar cek
 * jadi atomik. Test ini menjaga kontrak tetap utuh setelah fix.
 */
class IngestIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_ingest_with_same_external_ref_does_not_duplicate_inbox(): void
    {
        Storage::fake('public');
        $svc = app(PenunjangIngestService::class);
        $meta = ['external_ref' => 'STUDY-IDEM-1', 'source' => 'OCT'];

        // Ingest pertama: tak ada order cocok (tanpa accession/no_rm) → masuk Inbox.
        $r1 = $svc->ingest(UploadedFile::fake()->create('hasil.pdf', 10), $meta);
        $this->assertFalse($r1['matched']);
        $this->assertSame(
            1,
            PenunjangIngestInbox::where('external_ref', 'STUDY-IDEM-1')->count(),
            'Ingest pertama harus membuat tepat 1 inbox.'
        );

        // Ingest kedua dengan external_ref SAMA → harus dikenali duplikat, TIDAK
        // membuat inbox kedua.
        $r2 = $svc->ingest(UploadedFile::fake()->create('hasil.pdf', 10), $meta);
        $this->assertTrue($r2['duplicate'] ?? false, 'Ingest ulang ber-ref sama harus ditandai duplicate.');
        $this->assertSame(
            1,
            PenunjangIngestInbox::where('external_ref', 'STUDY-IDEM-1')->count(),
            'Ingest kedua TIDAK boleh membuat inbox kedua (idempoten).'
        );
    }
}
