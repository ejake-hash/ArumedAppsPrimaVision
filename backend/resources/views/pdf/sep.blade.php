{{--
  Surat Eligibilitas Peserta (SEP) — replika luaran resmi portal VClaim BPJS.
  Dibangun ulang dari data GET /SEP (BPJS tak punya API cetak PDF). Dirancang
  untuk dompdf: layout <table>, satuan px, tanpa flexbox/mm. Ringkas → 1 halaman.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; }
    @page { margin: 12mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; }

    .head { width: 100%; border-collapse: collapse; border-bottom: 2px solid #16873f; padding-bottom: 4px; }
    .head td { vertical-align: middle; }
    .head .logo { width: 250px; }
    .head .logo img { width: 240px; height: auto; display: block; }
    .bpjs-txt .b1 { font-size: 22px; font-weight: bold; color: #1f9d57; letter-spacing: -0.5px; }
    .bpjs-txt .b1 .k { color: #0a4f9e; }
    .bpjs-txt .b2 { font-size: 8px; font-weight: bold; color: #0a4f9e; letter-spacing: 0.5px; }
    .title .t1 { font-size: 16px; font-weight: bold; color: #111; line-height: 1.05; }
    .title .t2 { font-size: 13px; font-weight: bold; color: #111; line-height: 1.1; }

    .grid { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .grid > td { width: 50%; vertical-align: top; padding: 0 10px 0 0; }
    .grid > td.right { padding: 0 0 0 14px; }

    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 2px 0; vertical-align: top; font-size: 10.5px; line-height: 1.25; }
    table.kv td.k { width: 92px; white-space: nowrap; }
    table.kv td.s { width: 8px; }
    table.kv td.v { font-weight: normal; }
    .right table.kv td.k { width: 86px; }

    .katarak-flag { font-weight: bold; font-size: 11px; margin-bottom: 4px; color: #111; }

    .legal { margin-top: 16px; font-size: 8px; color: #222; line-height: 1.35; }
    .legal p { margin: 2px 0; }
    .legal .hd { font-weight: bold; }

    .sign { width: 100%; border-collapse: collapse; margin-top: 6px; }
    .sign td { vertical-align: top; }
    .sign .approve { text-align: left; }
    .sign .approve .lbl { font-size: 11px; font-weight: bold; color: #111; margin-bottom: 4px; }
    .sign .approve img { height: 84px; width: 84px; display: block; }
    .sign .approve .nm { font-size: 11px; font-weight: bold; margin-top: 2px; }
    .sign .approve .ct { font-size: 9px; color: #444; margin-top: 1px; }
</style>
</head>
<body>

{{-- ── Kop: logo BPJS + judul SEP + nama RS ── --}}
<table class="head">
    <tr>
        <td class="logo">
            @if($bpjs_logo)
                <img src="{{ $bpjs_logo }}" alt="BPJS Kesehatan"/>
            @else
                <div class="bpjs-txt">
                    <div class="b1">BPJS <span class="k">Kesehatan</span></div>
                    <div class="b2">Badan Penyelenggara Jaminan Sosial</div>
                </div>
            @endif
        </td>
        <td class="title">
            <div class="t1">SURAT ELIGIBITAS PESERTA</div>
            <div class="t2">{{ $rs_name }}</div>
        </td>
    </tr>
</table>

{{-- ── Dua kolom data peserta & pelayanan ── --}}
<table class="grid">
    <tr>
        <td>
            <table class="kv">
                <tr><td class="k">No.SEP</td><td class="s">:</td><td class="v">{{ $no_sep }}</td></tr>
                <tr><td class="k">Tgl.SEP</td><td class="s">:</td><td class="v">{{ $tgl_sep }}</td></tr>
                <tr><td class="k">No.Kartu</td><td class="s">:</td><td class="v">{{ $no_kartu }} (MR: {{ $no_mr }})</td></tr>
                <tr><td class="k">Nama Peserta</td><td class="s">:</td><td class="v">{{ $nama }}</td></tr>
                <tr><td class="k">Tgl.Lahir</td><td class="s">:</td><td class="v">{{ $tgl_lahir }} Kelamin: {{ $kelamin }}</td></tr>
                <tr><td class="k">No.Telepon</td><td class="s">:</td><td class="v">{{ $no_telp }}</td></tr>
                <tr><td class="k">Sub/Spesialis</td><td class="s">:</td><td class="v">{{ $sub_spesialis }}</td></tr>
                <tr><td class="k">Dokter</td><td class="s">:</td><td class="v">{{ $dokter }}</td></tr>
                <tr><td class="k">Faskes Perujuk</td><td class="s">:</td><td class="v">{{ $faskes_perujuk }}</td></tr>
                <tr><td class="k">Diagnosa Awal</td><td class="s">:</td><td class="v">{{ $diagnosa }}</td></tr>
                <tr><td class="k">Informasi PRB</td><td class="s">:</td><td class="v">{{ $prb }}</td></tr>
                <tr><td class="k">Catatan</td><td class="s">:</td><td class="v">{{ $catatan }}</td></tr>
            </table>
        </td>
        <td class="right">
            @if($is_katarak)
                <div class="katarak-flag">*Pasien Operasi Katarak</div>
            @endif
            <table class="kv">
                <tr><td class="k">Peserta</td><td class="s">:</td><td class="v">{{ $peserta }}</td></tr>
                <tr><td class="k">Jns.Rawat</td><td class="s">:</td><td class="v">{{ $jns_rawat }}</td></tr>
                <tr><td class="k">Jns.Kunjungan</td><td class="s">:</td><td class="v">{{ $jns_kunjungan }}</td></tr>
                <tr><td class="k">Poli Perujuk</td><td class="s">:</td><td class="v">{{ $poli_perujuk }}</td></tr>
                <tr><td class="k">Kls.Hak</td><td class="s">:</td><td class="v">{{ $kls_hak }}</td></tr>
                <tr><td class="k">Kls.Rawat</td><td class="s">:</td><td class="v">{{ $kls_rawat }}</td></tr>
                <tr><td class="k">Penjamin</td><td class="s">:</td><td class="v">{{ $penjamin }}</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- ── Pernyataan persetujuan (verbatim luaran resmi BPJS) ── --}}
<div class="legal">
    <p class="hd">*Saya menyetujui BPJS Kesehatan untuk :</p>
    <p>a. membuka dan atau menggunakan informasi medis Pasien untuk keperluan administrasi, pembayaran asuransi atau jaminan pembiayaan kesehatan</p>
    <p>b. memberikan akses informasi medis atau riwayat pelayanan kepada dokter/tenaga medis pada {{ $rs_name }} untuk kepentingan pemeliharaan kesehatan, pengobatan, penyembuhan, dan perawatan Pasien</p>
    <p class="hd">*Saya mengetahui dan memahami :</p>
    <p>a. Rumah Sakit dapat melakukan koordinasi dengan PT Jasa Raharja / PT Taspen / PT ASABRI / BPJS Ketenagakerjaan atau Penjamin lainnya, jika Peserta merupakan pasien yang mengalami kecelakaan lalu lintas dan / atau kecelakaan kerja</p>
    <p>b. SEP bukan sebagai bukti penjaminan peserta</p>
    <p style="margin-top:6px;">** Dengan tampilnya luaran SEP elektronik ini merupakan hasil validasi terhadap eligibilitas Pasien secara elektronik (validasi finger print atau biometrik / sistem validasi lain) dan selanjutnya Pasien dapat mengakses pelayanan kesehatan rujukan sesuai ketentuan berlaku. Kebenaran dan keaslian atas informasi data Pasien menjadi tanggung jawab penuh FKRTL</p>
</div>

{{-- ── Tanda tangan / persetujuan pasien ── --}}
<table class="sign">
    <tr>
        <td style="width:55%;"></td>
        <td class="approve">
            <div class="lbl">Persetujuan<br>Pasien/Keluarga Pasien</div>
            @if($qr)
                <img src="{{ $qr }}" alt="QR No.SEP"/>
            @endif
            <div class="nm">{{ $nama }}</div>
            <div class="ct">Cetakan ke 1 {{ $printed_at }}</div>
        </td>
    </tr>
</table>

</body>
</html>
