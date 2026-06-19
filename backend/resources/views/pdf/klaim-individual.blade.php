{{--
  Berkas Klaim Individual Pasien — replika luaran resmi cetak E-Klaim INA-CBG.
  Dibangun ulang dari data get_claim_data WS (E-Klaim tak punya API cetak PDF
  yang bisa kita tarik langsung tanpa sesi). Dirancang untuk dompdf: layout
  <table>, satuan px, tanpa flexbox. Ringkas → 1 halaman.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; }
    @page { margin: 12mm 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1a1a1a; margin: 0; }

    .head { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: middle; }
    .head .logo { width: 46px; }
    .head .logo img { width: 42px; height: auto; display: block; }
    .head .t1 { font-size: 12px; font-weight: bold; color: #111; }
    .head .t2 { font-size: 10px; font-style: italic; color: #333; }
    .head .right { text-align: right; font-size: 10px; }

    hr.sep { border: 0; border-top: 1.5px solid #222; margin: 6px 0; }

    table.kv { width: 100%; border-collapse: collapse; margin-top: 2px; }
    table.kv td { padding: 1.6px 0; vertical-align: top; font-size: 9.3px; line-height: 1.25; }
    table.kv td.k { width: 130px; white-space: nowrap; }
    table.kv td.s { width: 8px; }

    .sectitle { font-weight: bold; font-size: 10px; margin: 8px 0 2px; }

    table.grp { width: 100%; border-collapse: collapse; margin-top: 3px; }
    table.grp td { padding: 2px 0; font-size: 9.3px; }
    table.grp td.amt { text-align: right; }
    table.grp tr.total td { border-top: 1px solid #222; font-weight: bold; padding-top: 4px; }

    .foot { margin-top: 14px; border-top: 1px solid #999; padding-top: 4px; font-size: 8px; color: #555; }
    .foot td { font-size: 8px; color: #555; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            @if($logo)
            <td class="logo"><img src="{{ $logo }}" alt="Kemenkes"></td>
            @endif
            <td>
                <div class="t1">KEMENTERIAN KESEHATAN REPUBLIK INDONESIA</div>
                <div class="t2">Berkas Klaim Individual Pasien</div>
            </td>
            <td class="right">
                {{ $jenis_tarif ? 'JKN' : '' }}<br>
                {{ $tgl_masuk ?? '' }}
            </td>
        </tr>
    </table>
    <hr class="sep">

    <table class="kv">
        <tr>
            <td class="k">Kode Rumah Sakit</td><td class="s">:</td><td>{{ $kode_rs ?? '-' }}</td>
            <td class="k">Kelas Rumah Sakit</td><td class="s">:</td><td>{{ $kelas_rs ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Nama RS</td><td class="s">:</td><td>{{ $nama_rs }}</td>
            <td class="k">Jenis Tarif</td><td class="s">:</td><td>{{ $jenis_tarif ?? '-' }}</td>
        </tr>
    </table>
    <hr class="sep">

    <table class="kv">
        <tr>
            <td class="k">Nomor Peserta</td><td class="s">:</td><td>{{ $no_kartu ?? '-' }}</td>
            <td class="k">Nomor SEP</td><td class="s">:</td><td>{{ $no_sep ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Nomor Rekam Medis</td><td class="s">:</td><td>{{ $no_rm ?? '-' }}</td>
            <td class="k">Tanggal Masuk</td><td class="s">:</td><td>{{ $tgl_masuk ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Nama Pasien</td><td class="s">:</td><td>{{ $nama_pasien ?? '-' }}</td>
            <td class="k">Tanggal Keluar</td><td class="s">:</td><td>{{ $tgl_pulang ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Umur Tahun</td><td class="s">:</td><td>{{ $umur_tahun ?? '-' }}</td>
            <td class="k">Jenis Perawatan</td><td class="s">:</td><td>{{ $jenis_rawat }}</td>
        </tr>
        <tr>
            <td class="k">Umur Hari</td><td class="s">:</td><td>{{ $umur_hari ?? '-' }}</td>
            <td class="k">Cara Pulang</td><td class="s">:</td><td>{{ $cara_pulang ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Tanggal Lahir</td><td class="s">:</td><td>{{ $tgl_lahir ?? '-' }}</td>
            <td class="k">LOS</td><td class="s">:</td><td>{{ $los ?? '-' }} hari</td>
        </tr>
        <tr>
            <td class="k">Jenis Kelamin</td><td class="s">:</td><td>{{ $gender }}</td>
            <td class="k">Kelas Perawatan</td><td class="s">:</td><td>{{ $kelas_rawat ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Berat Lahir</td><td class="s">:</td><td>{{ $berat_lahir }}</td>
            <td class="k"></td><td></td><td></td>
        </tr>
    </table>
    <hr class="sep">

    <table class="kv">
        <tr>
            <td class="k">Diagnosa Utama</td><td class="s">:</td>
            <td colspan="4">{{ $diagnosa[0] ?? '-' }}</td>
        </tr>
        @if(count($diagnosa) > 1)
        <tr>
            <td class="k">Diagnosa Sekunder</td><td class="s">:</td>
            <td colspan="4">{{ implode(', ', array_slice($diagnosa, 1)) }}</td>
        </tr>
        @endif
        <tr>
            <td class="k">Prosedur</td><td class="s">:</td>
            <td colspan="4">{{ count($procedure) ? implode(', ', $procedure) : '-' }}</td>
        </tr>
        <tr>
            <td class="k">ADL Sub Acute</td><td class="s">:</td><td>{{ $adl_sub_acute }}</td>
            <td class="k">ADL Chronic</td><td class="s">:</td><td>{{ $adl_chronic }}</td>
        </tr>
    </table>

    <div class="sectitle">Hasil Grouping</div>
    <table class="grp">
        <tr>
            <td style="width:120px;">INA-CBG</td>
            <td>: {{ $inacbg_code ?? '-' }}</td>
            <td>{{ $inacbg_desc ?? '-' }}</td>
            <td class="amt">Rp {{ number_format((float) ($tarif ?? 0), 2, ',', '.') }}</td>
        </tr>
        <tr class="total">
            <td colspan="3">Total Tarif</td>
            <td class="amt">Rp {{ number_format((float) ($tarif ?? 0), 2, ',', '.') }}</td>
        </tr>
    </table>

    @if($klaim_status || $dc_status)
    <table class="kv" style="margin-top:10px;">
        <tr>
            <td class="k">Status Klaim</td><td class="s">:</td>
            <td>{{ $klaim_status === 'final' ? 'Final' : ($klaim_status ?? '-') }}</td>
            <td class="k">Status DC Kemkes</td><td class="s">:</td>
            <td>{{ $dc_status === 'sent' ? 'Terkirim' : ($dc_status ?? '-') }}</td>
        </tr>
    </table>
    @endif

    <table class="foot">
        <tr>
            <td>Generated : E-Klaim (replika SIMRS) @ {{ $generated_at }}</td>
            <td style="text-align:right;">Lembar 1 / 1</td>
        </tr>
    </table>
</body>
</html>
