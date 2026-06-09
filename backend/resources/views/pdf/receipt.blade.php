@php
    // Helper rupiah & label — mandiri (tak bergantung helper global) agar PDF
    // bisa dirender di worker queue tanpa konteks request.
    $rp = fn ($v) => 'Rp ' . number_format((float) ($v ?? 0), 0, ',', '.');
    $metodeLabel = fn ($c) => [
        'CASH' => 'Tunai', 'CREDIT_CARD' => 'Debit/Kredit', 'TRANSFER' => 'Transfer',
        'BPJS' => 'BPJS', 'INSURANCE' => 'Ditanggung Asuransi', 'WAIVED' => 'Gratis / Diskon 100%',
    ][$c] ?? ($c ?? '—');
    $penjaminLabel = fn ($g) => [
        'BPJS' => 'BPJS Kesehatan', 'ASURANSI' => 'Asuransi', 'PERUSAHAAN' => 'Perusahaan',
        'SOSIAL' => 'Sosial', 'UMUM' => 'Umum',
    ][strtoupper($g ?? '')] ?? 'Umum';

    // Grouping per kategori — meniru groupItemsByCategory di KasirView.vue.
    $FALLBACK = 'Lainnya';
    $orderMap = [];
    foreach (($categories ?? []) as $cat) {
        if (! empty($cat['name'])) $orderMap[strtolower($cat['name'])] = $cat['sort_order'] ?? 100;
    }
    $buckets = [];
    foreach (($items ?? []) as $it) {
        $rawCat = trim((string) ($it['category'] ?? '')) ?: $FALLBACK;
        $key = array_key_exists(strtolower($rawCat), $orderMap) ? $rawCat : $FALLBACK;
        $buckets[$key][] = $it;
    }
    $groups = [];
    foreach ($buckets as $name => $rows) {
        $groups[] = [
            'name' => $name,
            'sort_order' => $orderMap[strtolower($name)] ?? 99999,
            'items' => $rows,
            'subtotal' => array_sum(array_map(fn ($r) => (float) ($r['net_price'] ?? $r['total_price'] ?? 0), $rows)),
        ];
    }
    usort($groups, function ($a, $b) use ($FALLBACK) {
        if ($a['name'] === $FALLBACK) return 1;
        if ($b['name'] === $FALLBACK) return -1;
        return $a['sort_order'] <=> $b['sort_order'];
    });

    $ps = $print_settings ?? [];
    $showLogo  = ($ps['show_logo'] ?? true) && ! empty($clinic['logo_url']);
    $showEsign = ($ps['show_esign'] ?? true) && ! empty($cashier);
    $showFooter = ($ps['show_footer'] ?? true);
    $watermark = $clinic['watermark_type'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; }
    .wrap { position: relative; padding: 4px; }
    .watermark {
        position: fixed; top: 42%; left: 0; right: 0; text-align: center;
        font-size: 64px; font-weight: bold; color: #000; opacity: 0.06;
        transform: rotate(-24deg); letter-spacing: 6px; z-index: 0;
    }
    .kop { width: 100%; border-bottom: 2px solid #0E3A66; padding-bottom: 8px; margin-bottom: 10px; }
    .kop td { vertical-align: middle; }
    .kop-logo { width: 64px; }
    .kop-logo img { max-width: 60px; max-height: 60px; }
    .clinic-name { font-size: 16px; font-weight: bold; color: #0E3A66; }
    .clinic-line { font-size: 10px; color: #444; }
    .title { text-align: center; font-size: 14px; font-weight: bold; margin: 4px 0 1px; letter-spacing: 1px; }
    /* Pembeda jenis layanan: garis bawah berwarna pada judul kwitansi. */
    .title.svc-ranap { color: #14532d; border-bottom: 2px solid #14532d; display: inline-block; width: 100%; padding-bottom: 2px; }
    .title.svc-igd   { color: #9a3412; border-bottom: 2px solid #9a3412; display: inline-block; width: 100%; padding-bottom: 2px; }
    .title.svc-rajal { color: #1e3a8a; border-bottom: 2px solid #1e3a8a; display: inline-block; width: 100%; padding-bottom: 2px; }
    .subtitle { text-align: center; font-size: 10px; color: #555; margin-bottom: 10px; }
    table.meta { width: 100%; font-size: 10.5px; margin-bottom: 8px; border-collapse: collapse; }
    table.meta td { padding: 1px 3px; vertical-align: top; }
    table.meta td.k { width: 90px; color: #555; }
    table.meta td.s { width: 8px; }
    table.meta.ranap { background: #f3f7fb; border: 1px solid #dce6f0; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .grp-head td { background: #eef3f8; font-weight: bold; font-size: 10.5px; padding: 4px 6px; border-top: 1px solid #cfd9e4; }
    .grp-head .amt { text-align: right; }
    table.items td.row { padding: 3px 6px; font-size: 10.5px; border-bottom: 1px dotted #ddd; }
    .row-desc { width: 70%; }
    .row-qty { color: #666; font-size: 9.5px; }
    .row-disc { color: #b91c1c; font-size: 9px; }
    .row-amt { text-align: right; white-space: nowrap; }
    .gross { color: #999; text-decoration: line-through; font-size: 9px; }
    table.summary { width: 55%; margin-left: 45%; margin-top: 10px; border-collapse: collapse; }
    table.summary td { padding: 2px 4px; font-size: 10.5px; }
    table.summary td.c-num { text-align: right; white-space: nowrap; }
    table.summary tr.grand td { border-top: 1.5px solid #0E3A66; font-weight: bold; font-size: 12px; color: #0E3A66; padding-top: 4px; }
    table.summary tr.sisa td { color: #b91c1c; font-weight: bold; }
    .status { margin-top: 12px; text-align: center; font-weight: bold; font-size: 13px; padding: 6px; border-radius: 4px; }
    .status.lunas { background: #e7f6ee; color: #1f7d4a; border: 1px solid #b6e3c8; }
    .status.belum { background: #fdeaea; color: #b91c1c; border: 1px solid #f3c2c2; }
    .sign { margin-top: 22px; width: 100%; }
    .sign td { width: 50%; vertical-align: top; font-size: 10.5px; }
    .sign .col-r { text-align: center; }
    .esign-badge { display: inline-block; background: #e7f6ee; color: #1f7d4a; font-size: 9px; padding: 1px 6px; border-radius: 3px; border: 1px solid #b6e3c8; }
    .esign-name { font-weight: bold; margin-top: 3px; }
    .esign-meta { font-size: 8.5px; color: #777; }
    .sign-space { height: 46px; }
    .footer { margin-top: 18px; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8.5px; color: #777; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
    @if($watermark)
        <div class="watermark">{{ $watermark }}</div>
    @endif

    {{-- Kop kanonik (sumber tunggal) — identik dgn pratinjau Profil Institusi --}}
    @if(!empty($clinic['letterhead_html']))
        {!! $clinic['letterhead_html'] !!}
    @else
        <table class="kop">
            <tr>
                @if($showLogo)
                    <td class="kop-logo"><img src="{{ $clinic['logo_url'] }}" alt="Logo"></td>
                @endif
                <td>
                    <div class="clinic-name">{{ $clinic['name'] ?? 'Rumah Sakit' }}</div>
                    @if(!empty($clinic['address']))<div class="clinic-line">{{ $clinic['address'] }}</div>@endif
                    <div class="clinic-line">
                        @if(!empty($clinic['phone']))Telp: {{ $clinic['phone'] }}@endif
                        @if(!empty($clinic['email'])) · Email: {{ $clinic['email'] }}@endif
                    </div>
                </td>
            </tr>
        </table>
    @endif

    @php
        $svcType = $service_type ?? (($inpatient ?? null) ? 'RANAP' : 'RAJAL');
        $svcTitle = ['RANAP' => 'KWITANSI RAWAT INAP', 'IGD' => 'KWITANSI GAWAT DARURAT (IGD)', 'RAJAL' => 'KWITANSI RAWAT JALAN'][$svcType] ?? 'RINCIAN BIAYA PELAYANAN';
    @endphp
    <div class="title svc-{{ strtolower($svcType) }}">{{ $svcTitle }}</div>
    <div class="subtitle">No. {{ $invoice['number'] ?? '—' }}</div>

    <table class="meta">
        <tr>
            <td class="k">No. Rekam Medis</td><td class="s">:</td><td>{{ $patient['no_rm'] ?? '—' }}</td>
            <td class="k">Tanggal</td><td class="s">:</td><td>{{ $invoice['date'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Nama Pasien</td><td class="s">:</td><td>{{ $patient['name'] ?? '—' }}</td>
            <td class="k">Metode Bayar</td><td class="s">:</td><td>{{ !empty($invoice['payment_method']) ? $metodeLabel($invoice['payment_method']) : '—' }}</td>
        </tr>
        <tr>
            <td class="k">NIK</td><td class="s">:</td><td>{{ $patient['nik'] ?? '—' }}</td>
            <td class="k">Penjamin</td><td class="s">:</td>
            @php
                $pLabel = $penjaminLabel($patient['guarantor_type'] ?? null);
                $pIns   = trim((string) ($patient['insurer'] ?? ''));
                $pGt    = strtoupper((string) ($patient['guarantor_type'] ?? ''));
                // Tampilkan insurer hanya bila menambah info (bukan redundan "Umum — UMUM").
                $showIns = $pIns !== '' && strtoupper($pIns) !== $pGt && strtoupper($pIns) !== strtoupper($pLabel);
            @endphp
            <td>{{ $pLabel }}@if($showIns) — {{ $pIns }}@endif</td>
        </tr>
        <tr>
            <td class="k">Dokter (DPJP)</td><td class="s">:</td><td>{{ $patient['dpjp'] ?? '—' }}</td>
            <td class="k">Jenis Layanan</td><td class="s">:</td>
            <td>{{ ['RANAP' => 'Rawat Inap', 'IGD' => 'Gawat Darurat (IGD)', 'RAJAL' => 'Rawat Jalan'][$svcType] ?? 'Rawat Jalan' }}</td>
        </tr>
    </table>

    @if($inpatient ?? null)
        <table class="meta ranap">
            <tr>
                <td class="k">Ruang / Bed</td><td class="s">:</td>
                <td>{{ $inpatient['room'] ?? '—' }}@if(!empty($inpatient['bed'])) / {{ $inpatient['bed'] }}@endif</td>
                <td class="k">Kelas Hak</td><td class="s">:</td><td>{{ $inpatient['kelas_rawat_hak'] ?? '—' }}</td>
            </tr>
            <tr>
                <td class="k">Tgl Masuk</td><td class="s">:</td><td>{{ $inpatient['admission_at'] ?? '—' }}</td>
                <td class="k">Tgl Keluar</td><td class="s">:</td><td>{{ $inpatient['discharge_at'] ?? '—' }}</td>
            </tr>
            <tr>
                <td class="k">Lama Rawat</td><td class="s">:</td><td><b>{{ $inpatient['los'] ?? '—' }} malam</b></td>
                <td class="k">Cara Keluar</td><td class="s">:</td><td>{{ $inpatient['discharge_type'] ?? '—' }}</td>
            </tr>
        </table>
    @endif

    <table class="items">
        @forelse($groups as $grp)
            <tr class="grp-head">
                <td>{{ $grp['name'] }}</td>
                <td class="amt">{{ $rp($grp['subtotal']) }}</td>
            </tr>
            @foreach($grp['items'] as $it)
                <tr>
                    <td class="row row-desc">
                        {{ $it['description'] ?? '' }}@if((float)($it['quantity'] ?? 1) > 1)<span class="row-qty"> ({{ $it['quantity'] }}×)</span>@endif
                        @if((float)($it['discount_amount'] ?? 0) > 0)
                            <span class="row-disc">diskon −{{ $rp($it['discount_amount']) }}@if((float)($it['discount_percent'] ?? 0) > 0) ({{ rtrim(rtrim(number_format((float)$it['discount_percent'],2),'0'),'.') }}%)@endif</span>
                        @endif
                    </td>
                    <td class="row row-amt">
                        @if((float)($it['discount_amount'] ?? 0) > 0)<span class="gross">{{ $rp($it['total_price']) }}</span> @endif
                        {{ $rp($it['net_price'] ?? $it['total_price']) }}
                    </td>
                </tr>
            @endforeach
        @empty
            <tr><td class="row" colspan="2" style="text-align:center;color:#999">Tidak ada item</td></tr>
        @endforelse
    </table>

    <table class="summary">
        <tr><td>Subtotal</td><td class="c-num">{{ $rp($summary['subtotal'] ?? 0) }}</td></tr>
        @if((float)($summary['item_discount'] ?? 0))
            <tr><td>Diskon Item</td><td class="c-num">− {{ $rp($summary['item_discount']) }}</td></tr>
        @endif
        @if((float)($summary['discount'] ?? 0))
            <tr><td>Diskon Global{{ (float)($summary['discount_percent'] ?? 0) > 0 ? ' ('.rtrim(rtrim(number_format((float)$summary['discount_percent'],2),'0'),'.').'%)' : '' }}</td><td class="c-num">− {{ $rp($summary['discount']) }}</td></tr>
        @endif
        @if((float)($summary['tax'] ?? 0))
            <tr><td>Pajak</td><td class="c-num">{{ $rp($summary['tax']) }}</td></tr>
        @endif
        <tr class="grand"><td>TOTAL TAGIHAN</td><td class="c-num">{{ $rp($summary['total'] ?? 0) }}</td></tr>
        @if((float)($summary['covered_amount'] ?? 0))
            <tr><td>Ditanggung Asuransi</td><td class="c-num">− {{ $rp($summary['covered_amount']) }}</td></tr>
        @endif
        <tr><td>Dibayar Pasien</td><td class="c-num">{{ $rp($summary['paid_amount'] ?? 0) }}</td></tr>
        @if(($invoice['is_paid'] ?? false) && (float)($summary['change'] ?? 0))
            <tr><td>Kembalian</td><td class="c-num">{{ $rp($summary['change']) }}</td></tr>
        @endif
        @if((float)($summary['sisa'] ?? 0))
            <tr class="sisa"><td>Sisa Tagihan</td><td class="c-num">{{ $rp($summary['sisa']) }}</td></tr>
        @endif
    </table>

    <div class="status {{ ($invoice['is_paid'] ?? false) ? 'lunas' : 'belum' }}">
        {{ ($invoice['is_paid'] ?? false) ? 'LUNAS' : 'BELUM LUNAS / PRO FORMA' }}
    </div>

    <table class="sign">
        <tr>
            <td></td>
            <td class="col-r">
                <div>Kasir</div>
                @if($showEsign)
                    <div style="margin-top:4px"><span class="esign-badge">✓ Ditandatangani elektronik</span></div>
                    <div class="esign-name">{{ $cashier }}</div>
                    <div class="esign-meta">{{ $invoice['number'] ?? '' }}@if(!empty($invoice['paid_at'])) · {{ $invoice['paid_at'] }}@endif</div>
                @else
                    <div class="sign-space"></div>
                    <div>( ......................................... )</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="footer">
        @if($showFooter && !empty($clinic['director_name']))
            Penanggung Jawab Rumah Sakit: {{ $clinic['director_name'] }}@if(!empty($clinic['director_sip'])) · SIP: {{ $clinic['director_sip'] }}@endif ·
        @endif
        Arumed Apps
    </div>
</div>
</body>
</html>
