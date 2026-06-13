@php
    // ════════════════════════════════════════════════════════════════════════
    // KWITANSI CETAK — varian "tampilan Kasir" untuk Rekam Medis (browser print).
    //
    // Dirender dari data KasirService::generateReceipt (sumber tunggal, SAMA dgn
    // kwitansi Kasir & email). Markup + CSS MENIRU `.rincian-print` di KasirView.vue
    // (flex/leader titik-titik) agar hasil cetak dari menu Rekam Medis IDENTIK dgn
    // tombol "Cetak Rincian" di Kasir.
    //
    // ⚠️ JANGAN dipakai untuk dompdf (email) — flexbox tak didukung dompdf. Jalur
    // email PDF tetap `pdf.receipt` (layout tabel). File ini KHUSUS jalur browser
    // (RME: tampil di iframe preview + cetak di window baru), yang mendukung flex.
    // Bila markup Kasir berubah, samakan file ini.
    // ════════════════════════════════════════════════════════════════════════
    $rp = fn ($v) => 'Rp ' . number_format((float) ($v ?? 0), 0, ',', '.');
    $metodeLabel = fn ($c) => [
        'CASH' => 'Tunai', 'CREDIT_CARD' => 'Debit/Kredit', 'TRANSFER' => 'Transfer',
        'BPJS' => 'BPJS', 'INSURANCE' => 'Ditanggung Asuransi', 'WAIVED' => 'Gratis / Diskon 100%',
    ][$c] ?? ($c ?? '—');
    $penjaminLabel = fn ($g) => [
        'BPJS' => 'BPJS Kesehatan', 'ASURANSI' => 'Asuransi', 'PERUSAHAAN' => 'Perusahaan',
        'SOSIAL' => 'Sosial', 'UMUM' => 'Umum',
    ][strtoupper($g ?? '')] ?? 'Umum';

    // Diskon Paket (DISKON_PAKET) — dikeluarkan dari daftar item, ditambah balik ke
    // Subtotal tampilan, disajikan sebagai baris ringkasan "Diskon Paket".
    $PAKET_DISCOUNT_TYPE = 'DISKON_PAKET';
    $paketDiscount = 0.0;
    foreach (($items ?? []) as $it) {
        if (($it['item_type'] ?? null) === $PAKET_DISCOUNT_TYPE) {
            $paketDiscount += abs((float) ($it['net_price'] ?? $it['total_price'] ?? 0));
        }
    }

    // Grouping per kategori — meniru groupedPrintItems di KasirView.vue.
    $FALLBACK = 'Lainnya';
    $orderMap = [];
    foreach (($categories ?? []) as $cat) {
        if (! empty($cat['name'])) $orderMap[strtolower($cat['name'])] = $cat['sort_order'] ?? 100;
    }
    $buckets = [];
    foreach (($items ?? []) as $it) {
        if (($it['item_type'] ?? null) === $PAKET_DISCOUNT_TYPE) continue;
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
    $showLogo   = ($ps['show_logo'] ?? true) && ! empty($clinic['logo_url']);
    $showEsign  = ($ps['show_esign'] ?? true) && ! empty($cashier);
    $showFooter = ($ps['show_footer'] ?? true);
    $watermark  = $clinic['watermark_type'] ?? null;

    // Jenis layanan → judul + label + kelas warna (svcCode/svcTitle/svcLabel Kasir).
    $svcType  = strtoupper($service_type ?? (($inpatient ?? null) ? 'RANAP' : 'RAJAL'));
    $svcTitle = ['RANAP' => 'KWITANSI RAWAT INAP', 'IGD' => 'KWITANSI GAWAT DARURAT (IGD)', 'RAJAL' => 'KWITANSI RAWAT JALAN'][$svcType] ?? 'RINCIAN BIAYA PELAYANAN';
    $svcLabel = ['RANAP' => 'Rawat Inap', 'IGD' => 'Gawat Darurat (IGD)', 'RAJAL' => 'Rawat Jalan'][$svcType] ?? 'Rawat Jalan';

    // penjaminFull — base (+ insurer bila menambah info) (+ "— COB <insurer-2>").
    $pLabel = $penjaminLabel($patient['guarantor_type'] ?? null);
    $pIns   = trim((string) ($patient['insurer'] ?? ''));
    $pGt    = strtoupper((string) ($patient['guarantor_type'] ?? ''));
    $showIns = $pIns !== '' && strtoupper($pIns) !== $pGt && strtoupper($pIns) !== strtoupper($pLabel);
    $penjaminText = $pLabel . ($showIns ? ' — ' . $pIns : '');
    $cob = $patient['cob'] ?? null;
    if ($cob && (! empty($cob['insurer']) || ! empty($cob['guarantor_type']))) {
        $c2 = trim((string) ($cob['insurer'] ?? '')) ?: $penjaminLabel($cob['guarantor_type'] ?? null);
        $penjaminText .= ' — COB ' . $c2;
    }

    $isBpjs   = $pGt === 'BPJS';
    $isPaid   = (bool) ($invoice['is_paid'] ?? false);
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    /* Visual MENIRU `.rincian-print` @media print di KasirView.vue, tapi diterapkan
       di layar JUGA (RME menampilkan via iframe preview, bukan hanya cetak). */
    * { box-sizing: border-box; }
    body { margin: 0; background: #fff; }
    @media print { @page { size: A4 portrait; margin: 14mm 15mm; } }

    .rincian-print {
        position: relative; color: #000;
        font-family: 'Inter', Arial, Helvetica, sans-serif;
        font-size: 11px; line-height: 1.5; padding: 14mm 15mm;
    }
    @media print { .rincian-print { padding: 0; } }

    .rincian-print .rp-watermark {
        position: fixed; top: 45%; left: 50%;
        transform: translate(-50%, -50%) rotate(-30deg);
        font-size: 92px; font-weight: 800; letter-spacing: .12em;
        color: rgba(0, 0, 0, 0.06); z-index: 0; pointer-events: none;
    }

    .rincian-print .rp-kop { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #000; padding-bottom: 9px; }
    .rincian-print .rp-logo { height: 62px; width: auto; object-fit: contain; }
    .rincian-print .rp-clinic { font-size: 19px; font-weight: 800; letter-spacing: .02em; }
    .rincian-print .rp-line { font-size: 10.5px; }

    .rincian-print .rp-title { text-align: center; font-size: 14px; font-weight: 800; letter-spacing: .06em; text-decoration: underline; margin: 12px 0 1px; }
    .rincian-print .rp-title.rp-svc-ranap { color: #14532d; }
    .rincian-print .rp-title.rp-svc-igd   { color: #9a3412; }
    .rincian-print .rp-title.rp-svc-rajal { color: #1e3a8a; }
    .rincian-print .rp-subtitle { text-align: center; font-size: 11px; margin-bottom: 12px; }

    .rincian-print .rp-meta { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .rincian-print .rp-meta td { padding: 1.5px 0; vertical-align: top; font-size: 11px; }
    .rincian-print .rp-meta .k { width: 15%; color: #333; }
    .rincian-print .rp-meta .s { width: 10px; }
    .rincian-print .rp-meta .v { width: 35%; font-weight: 600; }
    .rincian-print .rp-meta-ranap td { background: #f3f7fb; }

    .rincian-print .rp-items { margin-bottom: 12px; }
    .rincian-print .rp-group { margin-bottom: 9px; page-break-inside: avoid; }
    .rincian-print .rp-group-head { display: flex; align-items: baseline; justify-content: space-between; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
    .rincian-print .rp-group-sub { font-weight: 700; white-space: nowrap; }
    .rincian-print .rp-row { display: flex; align-items: baseline; font-size: 10.8px; padding: 1.5px 0 1.5px 12px; }
    .rincian-print .rp-row-desc { flex: 0 1 auto; }
    .rincian-print .rp-row-qty { color: #555; }
    .rincian-print .rp-row-disc { color: #b45309; font-size: 9.5px; margin-left: 6px; }
    .rincian-print .rp-dots { flex: 1 1 auto; border-bottom: 1px dotted #bbb; margin: 0 6px; transform: translateY(-2px); min-width: 14px; }
    .rincian-print .rp-row-amt { flex: 0 0 auto; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .rincian-print .rp-row-gross { color: #999; text-decoration: line-through; font-size: 9.5px; margin-right: 5px; }
    .rincian-print .rp-empty { font-style: italic; color: #777; padding: 4px 12px; }

    .rincian-print .rp-summary { display: flex; justify-content: flex-end; margin-bottom: 16px; }
    .rincian-print .rp-summary table { border-collapse: collapse; min-width: 280px; }
    .rincian-print .rp-summary td { padding: 2.5px 7px; font-size: 11px; }
    .rincian-print .rp-summary td.c-num { text-align: right; white-space: nowrap; }
    .rincian-print .rp-summary .rp-grand td { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; font-weight: 800; font-size: 12.5px; }
    .rincian-print .rp-summary .rp-sisa td { font-weight: 700; }
    .rincian-print .rp-summary .rp-disc-paket td { color: #b45309; }

    .rincian-print .rp-status { display: inline-block; border: 2px solid #000; padding: 3px 14px; font-weight: 800; letter-spacing: .08em; font-size: 12px; margin-bottom: 24px; }
    .rincian-print .rp-status.lunas { color: #15803d; border-color: #15803d; }
    .rincian-print .rp-status.belum { color: #b45309; border-color: #b45309; }

    .rincian-print .rp-sign { display: flex; justify-content: flex-end; page-break-inside: avoid; }
    .rincian-print .rp-sign-col { width: 45%; text-align: center; }
    .rincian-print .rp-sign-lbl { font-size: 11px; margin-bottom: 4px; }
    .rincian-print .rp-sign-space { height: 62px; }
    .rincian-print .rp-sign-name { font-size: 11px; }
    .rincian-print .rp-esign { display: inline-block; padding-top: 6px; }
    .rincian-print .rp-esign-badge { display: inline-block; font-size: 9px; font-weight: 700; color: #15803d; border: 1px solid #15803d; border-radius: 4px; padding: 2px 8px; letter-spacing: .02em; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rincian-print .rp-esign-name { font-size: 11.5px; font-weight: 700; margin-top: 5px; }
    .rincian-print .rp-esign-meta { font-size: 8.5px; color: #555; margin-top: 1px; }

    .rincian-print .rp-footer { margin-top: 28px; padding-top: 7px; border-top: 1px solid #999; text-align: center; font-size: 9px; color: #444; }
</style>
</head>
<body>
<div class="rincian-print">
    @if($watermark)
        <div class="rp-watermark">{{ $watermark }}</div>
    @endif

    {{-- Kop kanonik (sumber tunggal) — identik dgn pratinjau Profil Institusi --}}
    @if(!empty($clinic['letterhead_html']))
        {!! $clinic['letterhead_html'] !!}
    @else
        <header class="rp-kop">
            @if($showLogo)<img src="{{ $clinic['logo_url'] }}" alt="Logo" class="rp-logo">@endif
            <div class="rp-kop-text">
                <div class="rp-clinic">{{ $clinic['name'] ?? 'Rumah Sakit' }}</div>
                @if(!empty($clinic['address']))<div class="rp-line">{{ $clinic['address'] }}</div>@endif
                <div class="rp-line">
                    @if(!empty($clinic['phone']))Telp: {{ $clinic['phone'] }}@endif
                    @if(!empty($clinic['email'])) · Email: {{ $clinic['email'] }}@endif
                </div>
            </div>
        </header>
    @endif

    <h1 class="rp-title rp-svc-{{ strtolower($svcType) }}">{{ $svcTitle }}</h1>
    <div class="rp-subtitle">No. {{ $invoice['number'] ?? '—' }}</div>

    <table class="rp-meta">
        <tbody>
            <tr>
                <td class="k">No. Rekam Medis</td><td class="s">:</td><td class="v">{{ $patient['no_rm'] ?? '—' }}</td>
                <td class="k">Tgl Kunjungan</td><td class="s">:</td><td class="v">{{ $invoice['visit_date'] ?? $invoice['date'] ?? '—' }}</td>
            </tr>
            <tr>
                <td class="k">Nama Pasien</td><td class="s">:</td><td class="v">{{ $patient['name'] ?? '—' }}</td>
                <td class="k">Metode Bayar</td><td class="s">:</td><td class="v">{{ !empty($invoice['payment_method']) ? $metodeLabel($invoice['payment_method']) : '—' }}</td>
            </tr>
            <tr>
                <td class="k">NIK</td><td class="s">:</td><td class="v">{{ $patient['nik'] ?? '—' }}</td>
                <td class="k">Penjamin</td><td class="s">:</td><td class="v">{{ $penjaminText }}</td>
            </tr>
            <tr>
                <td class="k">Dokter (DPJP)</td><td class="s">:</td><td class="v">{{ $patient['dpjp'] ?? '—' }}</td>
                <td class="k">Jenis Layanan</td><td class="s">:</td><td class="v">{{ $svcLabel }}</td>
            </tr>
            <tr>
                <td class="k">Tgl Invoice</td><td class="s">:</td><td class="v">{{ $invoice['date'] ?? '—' }}</td>
                <td class="k"></td><td class="s"></td><td class="v"></td>
            </tr>
        </tbody>
    </table>

    @if($inpatient ?? null)
        <table class="rp-meta rp-meta-ranap">
            <tbody>
                <tr>
                    <td class="k">Ruang / Bed</td><td class="s">:</td>
                    <td class="v">{{ $inpatient['room'] ?? '—' }}@if(!empty($inpatient['bed'])) / {{ $inpatient['bed'] }}@endif</td>
                    <td class="k">Kelas Hak</td><td class="s">:</td>
                    <td class="v">{{ $inpatient['kelas_rawat_hak'] ?? '—' }}@if(!empty($inpatient['titip_note'])) ({{ $inpatient['titip_note'] }})@endif</td>
                </tr>
                <tr>
                    <td class="k">Tgl Masuk</td><td class="s">:</td><td class="v">{{ $inpatient['admission_at'] ?? '—' }}</td>
                    <td class="k">Tgl Keluar</td><td class="s">:</td><td class="v">{{ $inpatient['discharge_at'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="k">Lama Rawat</td><td class="s">:</td><td class="v"><strong>{{ $inpatient['los'] ?? '—' }} malam</strong></td>
                    <td class="k">Cara Keluar</td><td class="s">:</td><td class="v">{{ $inpatient['discharge_type'] ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <div class="rp-items">
        @forelse($groups as $grp)
            <div class="rp-group">
                <div class="rp-group-head">
                    <span class="rp-group-name">{{ $grp['name'] }}</span>
                    <span class="rp-group-sub">{{ $rp($grp['subtotal']) }}</span>
                </div>
                @foreach($grp['items'] as $it)
                    <div class="rp-row">
                        <span class="rp-row-desc">
                            {{ $it['description'] ?? '' }}@if((float)($it['quantity'] ?? 1) > 1)<span class="rp-row-qty"> ({{ $it['quantity'] }}×)</span>@endif
                            @if((float)($it['discount_amount'] ?? 0) > 0)<span class="rp-row-disc">diskon −{{ $rp($it['discount_amount']) }}@if((float)($it['discount_percent'] ?? 0) > 0) ({{ $pct($it['discount_percent']) }}%)@endif</span>@endif
                        </span>
                        <span class="rp-dots"></span>
                        <span class="rp-row-amt">
                            @if((float)($it['discount_amount'] ?? 0) > 0)<span class="rp-row-gross">{{ $rp($it['total_price']) }}</span>@endif
                            {{ $rp($it['net_price'] ?? $it['total_price']) }}
                        </span>
                    </div>
                @endforeach
            </div>
        @empty
            <div class="rp-empty">Tidak ada item</div>
        @endforelse
    </div>

    <div class="rp-summary">
        <table>
            <tbody>
                <tr><td>Subtotal</td><td class="c-num">{{ $rp((float)($summary['subtotal'] ?? 0) + $paketDiscount) }}</td></tr>
                @if($paketDiscount)
                    <tr class="rp-disc-paket"><td>Diskon Paket</td><td class="c-num">− {{ $rp($paketDiscount) }}</td></tr>
                @endif
                @if((float)($summary['item_discount'] ?? 0))
                    <tr><td>Diskon Item</td><td class="c-num">− {{ $rp($summary['item_discount']) }}</td></tr>
                @endif
                @if((float)($summary['discount'] ?? 0))
                    <tr><td>Diskon Global{{ (float)($summary['discount_percent'] ?? 0) > 0 ? ' ('.$pct($summary['discount_percent']).'%)' : '' }}</td><td class="c-num">− {{ $rp($summary['discount']) }}</td></tr>
                @endif
                @if((float)($summary['tax'] ?? 0))
                    <tr><td>Pajak</td><td class="c-num">{{ $rp($summary['tax']) }}</td></tr>
                @endif
                <tr class="rp-grand"><td>TOTAL TAGIHAN</td><td class="c-num">{{ $rp($summary['total'] ?? 0) }}</td></tr>
                @if((float)($summary['covered_amount'] ?? 0))
                    <tr>
                        <td>{{ $isBpjs ? 'Ditanggung BPJS Kesehatan (klaim INA-CBG)' : 'Ditanggung Asuransi' }}</td>
                        <td class="c-num">{{ $isBpjs ? '' : '− ' . $rp($summary['covered_amount']) }}</td>
                    </tr>
                @endif
                <tr><td>Dibayar Pasien</td><td class="c-num">{{ $rp($summary['paid_amount'] ?? 0) }}</td></tr>
                @if($isPaid && (float)($summary['change'] ?? 0))
                    <tr><td>Kembalian</td><td class="c-num">{{ $rp($summary['change']) }}</td></tr>
                @endif
                @if((float)($summary['sisa'] ?? 0))
                    <tr class="rp-sisa"><td>Sisa Tagihan</td><td class="c-num">{{ $rp($summary['sisa']) }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="rp-status {{ $isPaid ? 'lunas' : 'belum' }}">{{ $isPaid ? 'LUNAS' : 'BELUM LUNAS / PRO FORMA' }}</div>

    <div class="rp-sign">
        <div class="rp-sign-col">
            <div class="rp-sign-lbl">Kasir</div>
            @if($showEsign)
                <div class="rp-esign">
                    <span class="rp-esign-badge">✓ Ditandatangani elektronik</span>
                    <div class="rp-esign-name">{{ $cashier }}</div>
                    <div class="rp-esign-meta">{{ $invoice['number'] ?? '' }}@if(!empty($invoice['paid_at'])) · {{ $invoice['paid_at'] }}@endif</div>
                </div>
            @else
                <div class="rp-sign-space"></div>
                <div class="rp-sign-name">( ......................................... )</div>
            @endif
        </div>
    </div>

    <footer class="rp-footer">
        @if($isPaid && !empty($invoice['paid_at']))Tgl Bayar: {{ $invoice['paid_at'] }} · @endif
        @if($showFooter && !empty($clinic['director_name']))Penanggung Jawab Rumah Sakit: {{ $clinic['director_name'] }}@if(!empty($clinic['director_sip'])) · SIP: {{ $clinic['director_sip'] }}@endif · @endif
        Dicetak: {{ \Illuminate\Support\Carbon::now()->timezone(config('app.timezone', 'Asia/Jakarta'))->format('d/m/Y H:i') }} · RS. Mata Prima Vision - PT. Karya Sistem Nusantara
    </footer>
</div>
</body>
</html>
