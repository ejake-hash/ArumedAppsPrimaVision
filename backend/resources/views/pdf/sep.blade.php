<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; }
    .wrap { position: relative; padding: 6px 4px; }
    .watermark {
        position: fixed; top: 42%; left: 0; right: 0; text-align: center;
        font-size: 64px; font-weight: bold; color: #000; opacity: 0.06;
        transform: rotate(-24deg); letter-spacing: 6px; z-index: 0;
    }
    .title { text-align: center; font-size: 15px; font-weight: bold; margin: 10px 0 1px; letter-spacing: 1px; color: #0E3A66; }
    .subtitle { text-align: center; font-size: 11px; color: #555; margin-bottom: 12px; }
    .nosep { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 1px; margin-bottom: 12px; }
    table.sep { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 4px; }
    table.sep td { padding: 3px 4px; vertical-align: top; }
    table.sep td.k { width: 110px; color: #555; }
    table.sep td.s { width: 8px; }
    table.sep td.v { font-weight: bold; }
    .colwrap { width: 100%; border-collapse: collapse; }
    .colwrap > td { width: 50%; vertical-align: top; padding: 0 8px; }
    .section { margin-top: 14px; }
    .section-head {
        background: #eef3f8; border: 1px solid #cfd9e4; font-weight: bold;
        font-size: 11px; padding: 4px 8px; color: #0E3A66;
    }
    .box { border: 1px solid #dce6f0; border-top: 0; padding: 6px 4px; }
    .diag { font-size: 12px; font-weight: bold; padding: 4px; }
    .sign { margin-top: 26px; width: 100%; }
    .sign td { width: 50%; vertical-align: top; font-size: 10.5px; text-align: center; }
    .sign-space { height: 44px; }
    .note { margin-top: 18px; font-size: 8.5px; color: #777; border-top: 1px solid #ddd; padding-top: 5px; }
    .meta-foot { margin-top: 6px; font-size: 8.5px; color: #999; text-align: right; }
</style>
</head>
<body>
<div class="wrap">
    @if(!empty($clinic['watermark_type']))
        <div class="watermark">{{ $clinic['watermark_type'] }}</div>
    @endif

    {{-- Kop kanonik (identik kwitansi & pratinjau Profil Institusi) --}}
    @if(!empty($clinic['letterhead_html']))
        {!! $clinic['letterhead_html'] !!}
    @endif

    <div class="title">SURAT ELIGIBILITAS PESERTA (SEP)</div>
    <div class="subtitle">BPJS Kesehatan</div>
    <div class="nosep">No. SEP: {{ $sep['no_sep'] }}</div>

    <table class="colwrap">
        <tr>
            <td>
                <table class="sep">
                    <tr><td class="k">No. Kartu</td><td class="s">:</td><td class="v">{{ $patient['no_kartu'] }}</td></tr>
                    <tr><td class="k">Nama Peserta</td><td class="s">:</td><td class="v">{{ $patient['nama'] }}</td></tr>
                    <tr><td class="k">Tgl Lahir</td><td class="s">:</td><td class="v">{{ $patient['tgl_lahir'] }}</td></tr>
                    <tr><td class="k">Jenis Kelamin</td><td class="s">:</td><td class="v">{{ $patient['gender'] }}</td></tr>
                    <tr><td class="k">No. Rekam Medis</td><td class="s">:</td><td class="v">{{ $patient['no_rm'] }}</td></tr>
                    <tr><td class="k">NIK</td><td class="s">:</td><td class="v">{{ $patient['nik'] }}</td></tr>
                    <tr><td class="k">No. Telepon</td><td class="s">:</td><td class="v">{{ $patient['phone'] }}</td></tr>
                </table>
            </td>
            <td>
                <table class="sep">
                    <tr><td class="k">Tgl SEP</td><td class="s">:</td><td class="v">{{ $sep['tgl_sep'] }}</td></tr>
                    <tr><td class="k">Jenis Rawat</td><td class="s">:</td><td class="v">{{ $sep['jenis_rawat'] }}</td></tr>
                    <tr><td class="k">Kelas Rawat</td><td class="s">:</td><td class="v">{{ $sep['kelas_rawat'] }}</td></tr>
                    <tr><td class="k">Poli Tujuan</td><td class="s">:</td><td class="v">{{ $sep['poli'] }}</td></tr>
                    <tr><td class="k">DPJP</td><td class="s">:</td><td class="v">{{ $sep['dpjp'] }}</td></tr>
                    <tr><td class="k">No. Rujukan</td><td class="s">:</td><td class="v">{{ $sep['no_rujukan'] }}</td></tr>
                    <tr><td class="k">Penjamin</td><td class="s">:</td><td class="v">{{ $sep['penjamin'] }}</td></tr>
                    <tr><td class="k">COB</td><td class="s">:</td><td class="v">{{ $sep['cob'] }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="section-head">Diagnosa Awal</div>
        <div class="box"><div class="diag">{{ $sep['diagnosa'] }}</div></div>
    </div>

    <div class="section">
        <div class="section-head">Catatan</div>
        <div class="box" style="min-height:28px;">{{ $sep['catatan'] }}</div>
    </div>

    <table class="sign">
        <tr>
            <td>
                <div>Peserta / Keluarga</div>
                <div class="sign-space"></div>
                <div>( ......................................... )</div>
            </td>
            <td>
                <div>Petugas</div>
                <div class="sign-space"></div>
                <div>( {{ $printed_by }} )</div>
            </td>
        </tr>
    </table>

    <div class="note">
        SEP ini sah sebagai bukti penjaminan pelayanan BPJS Kesehatan sesuai data yang tercatat saat penerbitan.
        Perubahan data hanya melalui mekanisme update/pembatalan SEP di aplikasi VClaim.
    </div>
    <div class="meta-foot">Dicetak: {{ $printed_at }} oleh {{ $printed_by }} · Arumed Apps</div>
</div>
</body>
</html>
