@php
    use Illuminate\Support\Arr;
    use Illuminate\Support\Carbon;

    $g  = fn ($arr, $key, $def = '') => trim((string) (Arr::get($arr ?? [], $key) ?? $def));
    $od = fn ($arr, $key) => trim((string) (Arr::get($arr ?? [], "$key.od") ?? ''));
    $os = fn ($arr, $key) => trim((string) (Arr::get($arr ?? [], "$key.os") ?? ''));
    $dash = fn ($v) => ($v === '' || $v === null) ? '—' : e($v);

    $ana = $assessment->anamnesa    ?? [];
    $ps  = $assessment->psikososial ?? [];
    $pe  = $assessment->perilaku    ?? [];
    $fi  = $assessment->fisik       ?? [];
    $mt  = $assessment->mata_od_os  ?? [];
    $pn  = $assessment->penunjang   ?? [];
    $pl  = $assessment->planning    ?? [];

    $atsLabel = [
        '1' => 'Kategori 1 — Segera (Resusitasi)',
        '2' => 'Kategori 2 — ≤ 10 menit (Emergency)',
        '3' => 'Kategori 3 — ≤ 30 menit (Urgent)',
        '4' => 'Kategori 4 — ≤ 60 menit (Semi-urgent)',
        '5' => 'Kategori 5 — ≤ 120 menit (Non-urgent)',
    ];
    $arrivalLabel = ['KELUARGA' => 'Keluarga', 'SENDIRI' => 'Datang sendiri', 'POLISI' => 'Polisi', 'LAINNYA' => 'Lain-lain'];
    $painType = ['NRS' => 'Numeric Rating Scale', 'WONG_BAKER' => 'Wong-Baker Faces', 'FLACC' => 'FLACC'];
    $painInterp = function ($s) {
        if ($s === null || $s === '') return '';
        $s = (int) $s;
        if ($s === 0) return 'Tidak nyeri';
        if ($s <= 3) return 'Nyeri ringan';
        if ($s <= 6) return 'Nyeri sedang';
        return 'Nyeri berat';
    };

    $dob = $patient?->date_of_birth ? Carbon::parse($patient->date_of_birth) : null;
    $umur = $dob ? $dob->age . ' th' : '';
    $arrival = $visit->igd_arrival_at ? Carbon::parse($visit->igd_arrival_at)->timezone('Asia/Jakarta')->format('d-m-Y H:i') : '';

    $cellL = 'padding:3px 6px;border:1px solid #999;vertical-align:top;';
    $thBox = 'padding:3px 6px;border:1px solid #999;background:#f1f1f1;font-weight:bold;text-align:center;';
@endphp

{{-- IDENTITAS PASIEN (kop surat ada di layout_html template) --}}
@php $isBpjs = ($visit->guarantor_type ?? '') === 'BPJS'; @endphp
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr>
    <td style="{{ $cellL }}width:25%;">Nama</td><td style="{{ $cellL }}width:25%;">{{ $dash($patient?->name) }}</td>
    <td style="{{ $cellL }}width:25%;">No. RM</td><td style="{{ $cellL }}width:25%;">{{ $dash($patient?->no_rm) }}</td>
  </tr>
  <tr>
    <td style="{{ $cellL }}">Tgl Lahir / Umur</td><td style="{{ $cellL }}">{{ $dob ? $dob->format('d-m-Y') : '—' }}{{ $umur ? ' / '.$umur : '' }}</td>
    <td style="{{ $cellL }}">Jenis Kelamin</td><td style="{{ $cellL }}">{{ ($patient?->gender ?? '') === 'L' ? 'Laki-laki' : (($patient?->gender ?? '') === 'P' ? 'Perempuan' : '—') }}</td>
  </tr>
  <tr>
    <td style="{{ $cellL }}">NIK</td><td style="{{ $cellL }}">{{ $dash($patient?->nik) }}</td>
    <td style="{{ $cellL }}">Penjamin</td><td style="{{ $cellL }}">{{ $dash($visit->guarantor_type) }}</td>
  </tr>
  @if($isBpjs)
  <tr>
    <td style="{{ $cellL }}">No. Kartu BPJS</td><td style="{{ $cellL }}">{{ $dash($patient?->bpjs_number) }}</td>
    <td style="{{ $cellL }}">No. SEP</td><td style="{{ $cellL }}">{{ $dash($visit->no_sep) }}</td>
  </tr>
  @endif
  <tr>
    <td style="{{ $cellL }}">Tgl/Jam Masuk IGD</td><td style="{{ $cellL }}">{{ $dash($arrival) }}</td>
    <td style="{{ $cellL }}">Cara Datang</td><td style="{{ $cellL }}">{{ $dash($arrivalLabel[$triase->arrival_mode ?? ''] ?? '') }}</td>
  </tr>
</table>

{{-- TRIASE ATS --}}
<div style="{{ $thBox }}text-align:left;">A. TRIASE (Australasian Triage Scale)</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr>
    <td style="{{ $cellL }}width:25%;">Kategori ATS</td>
    <td style="{{ $cellL }}" colspan="3">{{ $dash($atsLabel[(string)($triase->triage_level ?? '')] ?? ($triase->triage_color ?? '')) }}</td>
  </tr>
  <tr>
    <td style="{{ $cellL }}">Keluhan Utama</td>
    <td style="{{ $cellL }}" colspan="3">{{ $dash($triase->chief_complaint ?? '') }}</td>
  </tr>
</table>

{{-- SKALA NYERI --}}
<div style="{{ $thBox }}text-align:left;">B. SKALA NYERI</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr>
    <td style="{{ $cellL }}width:25%;">Skor / Skala</td>
    <td style="{{ $cellL }}width:25%;">{{ $triase->pain_score !== null ? $triase->pain_score.'/10' : '—' }} {{ ($painInterp($triase->pain_score ?? null)) ? '('.$painInterp($triase->pain_score).')' : '' }}</td>
    <td style="{{ $cellL }}width:25%;">Metode</td>
    <td style="{{ $cellL }}width:25%;">{{ $dash($painType[$triase->pain_scale_type ?? ''] ?? '') }}</td>
  </tr>
  <tr>
    <td style="{{ $cellL }}">Lokasi Nyeri</td>
    <td style="{{ $cellL }}" colspan="3">{{ $dash($triase->pain_location ?? '') }}</td>
  </tr>
</table>

{{-- SUBJECTIVE / ANAMNESE --}}
<div style="{{ $thBox }}text-align:left;">C. ANAMNESE (Subjective)</div>
@php $allo = (array) Arr::get($ana, 'allo_source', []); @endphp
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr><td style="{{ $cellL }}width:25%;">Jenis Anamnese</td><td style="{{ $cellL }}" colspan="3">{{ $dash($g($ana,'type') === 'ALLO' ? 'Alloanamnesa'.(count($allo) ? ' ('.e(implode(', ',$allo)).')' : '') : ($g($ana,'type') === 'AUTO' ? 'Autoanamnesa' : '')) }}</td></tr>
  <tr><td style="{{ $cellL }}">Keluhan Utama</td><td style="{{ $cellL }}" colspan="3">{{ $dash($g($ana,'keluhan_utama')) }}</td></tr>
  <tr><td style="{{ $cellL }}">Riwayat Penyakit Terdahulu</td><td style="{{ $cellL }}" colspan="3">{{ $dash($g($ana,'rpd')) }}</td></tr>
  <tr><td style="{{ $cellL }}">Riwayat Alergi</td><td style="{{ $cellL }}" colspan="3">{{ $dash($g($ana,'alergi')) }}</td></tr>
  <tr><td style="{{ $cellL }}">Anamnesa / Heteroanamnesa</td><td style="{{ $cellL }}" colspan="3">{!! nl2br(e($g($ana,'anamnesa_narasi'))) ?: '—' !!}</td></tr>
  <tr><td style="{{ $cellL }}">Riwayat Pemakaian Obat</td><td style="{{ $cellL }}" colspan="3">{{ $dash($g($ana,'rpo')) }}</td></tr>
</table>

{{-- PSIKOSOSIAL --}}
<div style="{{ $thBox }}text-align:left;">D. RIWAYAT PSIKOLOGIS, SOSIAL, SPIRITUAL & EKONOMI</div>
@php
  $psik = (array) Arr::get($ps, 'psikologis', []);
  $bd = (array) Arr::get($ps, 'bunuh_diri', []);
  $tt = $g($ps,'tempat_tinggal');
  if ($tt === 'Lainnya' && $g($ps,'tempat_tinggal_lainnya')) $tt .= ' — '.$g($ps,'tempat_tinggal_lainnya');
@endphp
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr><td style="{{ $cellL }}width:25%;">Status Psikologis</td><td style="{{ $cellL }}width:25%;">{{ count($psik) ? e(implode(', ',$psik)) : '—' }}</td>
      <td style="{{ $cellL }}width:25%;">Kecenderungan Bunuh Diri</td><td style="{{ $cellL }}width:25%;">{{ Arr::get($bd,'ada') ? 'Ya'.(Arr::get($bd,'laporan') ? ' — dilaporkan ke '.e(Arr::get($bd,'laporan')) : '') : 'Tidak' }}</td></tr>
  <tr><td style="{{ $cellL }}">Status Pernikahan</td><td style="{{ $cellL }}">{{ $dash($g($ps,'pernikahan')) }}</td>
      <td style="{{ $cellL }}">Tempat Tinggal</td><td style="{{ $cellL }}">{{ $dash($tt) }}</td></tr>
  <tr><td style="{{ $cellL }}">Hubungan dgn Keluarga</td><td style="{{ $cellL }}">{{ $dash($g($ps,'keluarga')) }}</td>
      <td style="{{ $cellL }}">Agama</td><td style="{{ $cellL }}">{{ $dash($g($ps,'agama')) }}</td></tr>
  <tr><td style="{{ $cellL }}">Perlu Pelayanan Spiritual</td><td style="{{ $cellL }}">{{ Arr::get($ps,'spiritual_perlu') ? 'Ya' : 'Tidak' }}</td>
      <td style="{{ $cellL }}">Pekerjaan</td><td style="{{ $cellL }}">{{ $dash($g($ps,'pekerjaan')) }}</td></tr>
  <tr><td style="{{ $cellL }}">Gangguan Perilaku</td><td style="{{ $cellL }}" colspan="3">{{ $dash(trim($g($pe,'status').(($g($pe,'bahaya')) ? ' — '.$g($pe,'bahaya') : ''))) }}</td></tr>
</table>

{{-- OBJECTIVE / PEMERIKSAAN FISIK --}}
<div style="{{ $thBox }}text-align:left;">E. PEMERIKSAAN FISIK (Objective)</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:6px;">
  <tr>
    <td style="{{ $cellL }}width:25%;">Keadaan Umum</td><td style="{{ $cellL }}width:25%;">{{ $dash($triase->keadaan_umum ?? '') }}</td>
    <td style="{{ $cellL }}width:25%;">Kesadaran</td><td style="{{ $cellL }}width:25%;">{{ $dash($triase->kesadaran ?? '') }} {{ ($triase->gcs_e || $triase->gcs_v || $triase->gcs_m) ? '(GCS E'.$triase->gcs_e.'V'.$triase->gcs_v.'M'.$triase->gcs_m.')' : '' }}</td>
  </tr>
  <tr>
    <td style="{{ $cellL }}">Tekanan Darah</td><td style="{{ $cellL }}">{{ ($triase->td_sistol || $triase->td_diastol) ? $triase->td_sistol.'/'.$triase->td_diastol.' mmHg' : '—' }}</td>
    <td style="{{ $cellL }}">Nadi</td><td style="{{ $cellL }}">{{ $triase->nadi ? $triase->nadi.' x/mnt' : '—' }}</td>
  </tr>
  <tr>
    <td style="{{ $cellL }}">Respirasi</td><td style="{{ $cellL }}">{{ $triase->respirasi ? $triase->respirasi.' x/mnt' : '—' }}</td>
    <td style="{{ $cellL }}">Suhu</td><td style="{{ $cellL }}">{{ $triase->suhu ? $triase->suhu.' °C' : '—' }}</td>
  </tr>
  <tr>
    <td style="{{ $cellL }}">SpO₂</td><td style="{{ $cellL }}">{{ $triase->spo2 ? $triase->spo2.' %' : '—' }}</td>
    <td style="{{ $cellL }}">Akral</td><td style="{{ $cellL }}">{{ $dash($triase->akral ?? '') }}</td>
  </tr>
  <tr>
    <td style="{{ $cellL }}">Refleks Cahaya</td><td style="{{ $cellL }}" colspan="3">{{ $dash($triase->reflex_cahaya ?? '') }}</td>
  </tr>
</table>

{{-- Pemeriksaan per-region --}}
@php
  $regions = ['kepala'=>'Kepala','leher'=>'Leher','jantung'=>'Jantung','paru'=>'Paru','abdomen'=>'Abdomen','punggung'=>'Punggung','genitalia'=>'Genitalia','ekstremitas'=>'Ekstremitas'];
@endphp
<table style="width:100%;border-collapse:collapse;margin-bottom:6px;">
  @foreach($regions as $key => $lbl)
    @php $r = (array) Arr::get($fi, $key, []); $normal = Arr::get($r,'normal'); $cat = trim((string) Arr::get($r,'catatan','')); @endphp
    <tr>
      <td style="{{ $cellL }}width:25%;">{{ $lbl }}</td>
      <td style="{{ $cellL }}">{{ $normal === false ? 'Abnormal' : ($normal === true ? 'Normal' : '—') }}{{ $cat ? ' — '.e($cat) : '' }}</td>
    </tr>
  @endforeach
</table>

{{-- MATA OD/OS --}}
<div style="{{ $thBox }}text-align:left;">F. PEMERIKSAAN MATA</div>
@php
  $eyeRows = [
    'visus'=>'Visus','pergerakan'=>'Pergerakan Bola Mata','palpebra_sup'=>'Palpebra Superior','palpebra_inf'=>'Palpebra Inferior',
    'kornea'=>'Kornea','iris'=>'Iris','konjungtiva'=>'Konjungtiva Bulbi','sekret'=>'Sekret',
    'tio'=>'Tekanan Bola Mata (TIO)','pupil_reflek'=>'Pupil — Refleks','pupil_ukuran'=>'Pupil — Ukuran','isokor'=>'Isokor',
  ];
@endphp
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr><td style="{{ $thBox }}width:34%;">Pemeriksaan</td><td style="{{ $thBox }}width:33%;">OD (Kanan)</td><td style="{{ $thBox }}width:33%;">OS (Kiri)</td></tr>
  @foreach($eyeRows as $key => $lbl)
    <tr>
      <td style="{{ $cellL }}">{{ $lbl }}</td>
      <td style="{{ $cellL }}">{{ $dash($od($mt,$key)) }}</td>
      <td style="{{ $cellL }}">{{ $dash($os($mt,$key)) }}</td>
    </tr>
  @endforeach
</table>

{{-- PENUNJANG --}}
<div style="{{ $thBox }}text-align:left;">G. PEMERIKSAAN PENUNJANG</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr><td style="{{ $cellL }}width:25%;">EKG</td><td style="{{ $cellL }}">{{ $dash($g($pn,'ekg')) }}</td></tr>
  <tr><td style="{{ $cellL }}">Radiologi</td><td style="{{ $cellL }}">{{ $dash($g($pn,'radiologi')) }}</td></tr>
  <tr><td style="{{ $cellL }}">Laboratorium</td><td style="{{ $cellL }}">{{ $dash($g($pn,'lab')) }}</td></tr>
</table>

{{-- ASSESSMENT --}}
<div style="{{ $thBox }}text-align:left;">H. ASSESSMENT</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr><td style="{{ $cellL }}width:25%;">Diagnosa Kerja</td><td style="{{ $cellL }}">{{ $dash(trim(($assessment->diagnosa_kerja ? $assessment->diagnosa_kerja.' — ' : '').($assessment->diagnosa_kerja_name ?? ''))) }}</td></tr>
  <tr><td style="{{ $cellL }}">Diagnosa Banding</td><td style="{{ $cellL }}">{!! nl2br(e($assessment->diagnosa_banding ?? '')) ?: '—' !!}</td></tr>
</table>

{{-- PLANNING --}}
<div style="{{ $thBox }}text-align:left;">I. PLANNING</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr><td style="{{ $cellL }}width:25%;">Therapi</td><td style="{{ $cellL }}">{!! nl2br(e($g($pl,'therapi'))) ?: '—' !!}</td></tr>
  <tr><td style="{{ $cellL }}">Anjuran</td><td style="{{ $cellL }}">{!! nl2br(e($g($pl,'anjuran'))) ?: '—' !!}</td></tr>
  <tr><td style="{{ $cellL }}">Pengobatan</td><td style="{{ $cellL }}">{!! nl2br(e($g($pl,'pengobatan'))) ?: '—' !!}</td></tr>
  <tr><td style="{{ $cellL }}">Diteruskan ke DPJP</td><td style="{{ $cellL }}">{{ $dash($g($pl,'dpjp')) }}</td></tr>
  <tr><td style="{{ $cellL }}">Instruksi ke Penderita/Keluarga</td><td style="{{ $cellL }}">{!! nl2br(e($g($pl,'instruksi_keluarga'))) ?: '—' !!}</td></tr>
</table>

{{-- KONDISI PULANG --}}
<div style="{{ $thBox }}text-align:left;">J. KEADAAN SAAT PULANG / PINDAH / RUJUK</div>
@php
  $kpLabel = ['BAIK'=>'Baik','SEDANG'=>'Sedang','BURUK'=>'Buruk','PERDARAHAN'=>'Perdarahan','KOMA'=>'Koma','MENINGGAL'=>'Meninggal'];
  $plLabel = ['RAWAT_JALAN'=>'Rawat Jalan','RAWAT_INAP'=>'Rawat Inap','RAWAT_INTENSIF'=>'Rawat Intensif','DIRUJUK'=>'Dirujuk'];
  $wk = $assessment->waktu_keluar ? Carbon::parse($assessment->waktu_keluar)->timezone('Asia/Jakarta')->format('d-m-Y H:i') : '';
@endphp
<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
  <tr>
    <td style="{{ $cellL }}width:25%;">Keadaan Pasien</td><td style="{{ $cellL }}width:25%;">{{ $dash($kpLabel[$assessment->keadaan_pulang ?? ''] ?? '') }}</td>
    <td style="{{ $cellL }}width:25%;">Perawatan Lanjutan</td><td style="{{ $cellL }}width:25%;">{{ $dash($plLabel[$assessment->perawatan_lanjutan ?? ''] ?? '') }}</td>
  </tr>
  <tr><td style="{{ $cellL }}">Tgl/Jam Keluar</td><td style="{{ $cellL }}" colspan="3">{{ $dash($wk) }}</td></tr>
</table>
