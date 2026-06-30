<?php

namespace Tests\Feature\Kasir;

use App\Models\Insurer;
use App\Models\Procedure;
use App\Services\KasirService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Karakterisasi N+1 pada KasirService::getPrice.
 *
 * Temuan audit 30 Jun 2026: getPrice() dipanggil per-baris saat membangun/konsolidasi
 * tagihan (puluhan kali per invoice). resolveTariffInsurerId() melakukan Insurer::find()
 * pada SETIAP panggilan padahal insurer_id sama untuk semua baris satu invoice → query
 * insurers berulang. systemInsurerId() sudah memoized; Insurer::find belum.
 *
 * Test: panggil getPrice 3× dgn insurer_id sama → query lookup `insurers` (find) harus
 * cuma 1× (ter-memoize per-request), bukan 3×.
 */
class GetPriceInsurerCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_price_memoizes_insurer_lookup_across_calls(): void
    {
        $insurer = new Insurer();
        $insurer->forceFill(['name' => 'Asuransi Uji', 'type' => 'ASURANSI'])->save();

        $procedure = new Procedure();
        $procedure->forceFill(['name' => 'Tindakan Uji', 'code' => 'PROC-TEST-1'])->save();

        DB::table('procedure_tariffs')->insert([
            'id'           => (string) Str::uuid(),
            'procedure_id' => $procedure->id,
            'insurer_id'   => $insurer->id,
            'price'        => 50000,
            'is_active'    => true,
        ]);

        $svc = app(KasirService::class);

        DB::enableQueryLog();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(50000.0, $svc->getPrice('procedure', $procedure->id, 'UMUM', $insurer->id));
        }
        $insurerFinds = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'insurers') && str_contains($q['query'], '"id" ='))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(
            1,
            $insurerFinds,
            'Insurer::find harus di-cache per-request: hanya 1 query insurers meski getPrice dipanggil 3x.'
        );
    }
}
