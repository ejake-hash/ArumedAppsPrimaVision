<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 13px; }
    h2 { margin: 0 0 4px; }
    .sub { color: #6b7280; margin: 0 0 16px; }
    .summary { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; }
    .summary b { font-size: 16px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eef0f3; font-size: 12px; }
    th { background: #f3f4f6; }
    td.num { text-align: right; white-space: nowrap; }
    .age { font-weight: 700; }
    .age.warn { color: #b45309; }
    .age.crit { color: #b91c1c; }
    .foot { color: #9ca3af; font-size: 11px; margin-top: 16px; }
  </style>
</head>
<body>
  <h2>Tunggakan Kasir — {{ $reportDate }}</h2>
  <p class="sub">Kunjungan yang belum tutup kasir (billing belum dikunci) beserta umurnya.</p>

  <div class="summary">
    Total tagihan tertunda: <b>{{ count($rows) }}</b> &nbsp;|&nbsp;
    Lewat {{ $threshold }} hari: <b style="color:#b91c1c">{{ $overdueCount }}</b> &nbsp;|&nbsp;
    Nilai: <b>Rp {{ number_format($sumTotal, 0, ',', '.') }}</b>
  </div>

  <table>
    <thead>
      <tr>
        <th>Umur</th>
        <th>Pasien</th>
        <th>No. RM</th>
        <th>Status Invoice</th>
        <th style="text-align:right">Nilai</th>
        <th>Sejak</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($rows as $r)
        <tr>
          <td class="age {{ $r['age'] > $threshold ? 'crit' : ($r['age'] >= 3 ? 'warn' : '') }}">H+{{ $r['age'] }}</td>
          <td>{{ $r['name'] }}</td>
          <td>{{ $r['no_rm'] }}</td>
          <td>{{ $r['status'] }}</td>
          <td class="num">Rp {{ number_format($r['total'], 0, ',', '.') }}</td>
          <td>{{ $r['since'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <p class="foot">Email otomatis dari sistem. Mohon tutup/selesaikan tagihan di atas pada hari yang sama atau maksimal {{ $threshold }} hari.</p>
</body>
</html>
