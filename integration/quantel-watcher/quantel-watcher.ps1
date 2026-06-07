<#
  Quantel Compact Touch -> Arumed watcher (Fase 4).

  Memantau folder export Compact Touch ("c:\Compact Touch\Data\"). Tiap pemeriksaan
  baru = 1 folder GUID berisi 1 file .jpg (gambar) + 1 file .xml (data biometri/IOL).
  Saat folder BARU muncul & stabil, kirim .jpg + .xml ke endpoint Arumed:
      POST {ArumedBase}/api/v1/integrasi/penunjang/ingest
      (multipart: file=<jpg>, xml=<xml>, source=QUANTEL_WATCHER)
  Server-lah yang parse XML -> No.RM + ExamKey + biometri (watcher cuma forwarder).
  Idempoten via ExamKey: kalaupun file terkirim 2x, server tak menggandakan.

  Tanpa dependensi: pakai .NET HttpClient (jalan di Windows PowerShell 5.1 maupun 7+).

  Jalankan (lihat README.md):
      powershell -ExecutionPolicy Bypass -File quantel-watcher.ps1
  Run pertama membuat "baseline": SEMUA folder lama ditandai sudah-diproses (TIDAK
  dikirim) supaya 11rb+ data historis tak membanjiri server. Untuk mengirim ulang
  data lama, jalankan dengan parameter -Backfill.
#>

param(
  [switch]$Backfill,          # proses juga semua folder lama (default: hanya baseline)
  [switch]$Once               # satu kali sapu lalu keluar (untuk uji), bukan loop terus
)

# ======================= KONFIGURASI (sesuaikan) =======================
$ArumedBase = $env:ARUMED_BASE      ; if (-not $ArumedBase) { $ArumedBase = "http://192.168.100.20:8000" }
$Token      = $env:PENUNJANG_BRIDGE_TOKEN ; if (-not $Token) { $Token = "GANTI-DENGAN-TOKEN-SAMA-DENGAN-.ENV-SERVER" }
$DataDir    = $env:QUANTEL_DATA_DIR ; if (-not $DataDir) { $DataDir = "C:\Compact Touch\Data" }
$WorkDir    = "C:\ArumedWatcher"    # tempat state + log
$PollSeconds      = 15              # jeda antar-sapuan
$StabilizeSeconds = 10             # tunggu file selesai ditulis sebelum kirim
# =======================================================================

$IngestUrl = "$ArumedBase/api/v1/integrasi/penunjang/ingest"
$StateFile = Join-Path $WorkDir "processed.txt"
$LogFile   = Join-Path $WorkDir "watcher.log"

if (-not (Test-Path $WorkDir)) { New-Item -ItemType Directory -Path $WorkDir -Force | Out-Null }

function Write-Log($msg) {
  $line = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $msg
  Write-Host $line
  Add-Content -Path $LogFile -Value $line
}

# ---- state: himpunan nama folder yang sudah diproses ----
$Processed = New-Object System.Collections.Generic.HashSet[string]
if (Test-Path $StateFile) {
  Get-Content $StateFile | ForEach-Object { if ($_ -ne "") { [void]$Processed.Add($_) } }
}

function Save-Processed($name) {
  [void]$Processed.Add($name)
  Add-Content -Path $StateFile -Value $name
}

# ---- HttpClient sekali pakai ulang ----
# PS 5.1 perlu Add-Type; PS 7 sudah memuatnya (abaikan error bila sudah ada).
Add-Type -AssemblyName System.Net.Http -ErrorAction SilentlyContinue
$client = New-Object System.Net.Http.HttpClient
$client.Timeout = [TimeSpan]::FromSeconds(60)
$client.DefaultRequestHeaders.Authorization =
  New-Object System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", $Token)

function Send-Exam($jpgPath, $xmlPath) {
  $form = New-Object System.Net.Http.MultipartFormDataContent

  $jpgBytes   = [System.IO.File]::ReadAllBytes($jpgPath)
  $jpgContent = New-Object System.Net.Http.ByteArrayContent -ArgumentList (,$jpgBytes)
  $jpgContent.Headers.ContentType = New-Object System.Net.Http.Headers.MediaTypeHeaderValue("image/jpeg")
  $form.Add($jpgContent, "file", [System.IO.Path]::GetFileName($jpgPath))

  if ($xmlPath) {
    $xmlBytes   = [System.IO.File]::ReadAllBytes($xmlPath)
    $xmlContent = New-Object System.Net.Http.ByteArrayContent -ArgumentList (,$xmlBytes)
    $xmlContent.Headers.ContentType = New-Object System.Net.Http.Headers.MediaTypeHeaderValue("application/xml")
    $form.Add($xmlContent, "xml", [System.IO.Path]::GetFileName($xmlPath))
  }
  $form.Add((New-Object System.Net.Http.StringContent("QUANTEL_WATCHER")), "source")

  $resp = $client.PostAsync($IngestUrl, $form).Result
  $body = $resp.Content.ReadAsStringAsync().Result
  return [pscustomobject]@{ ok = $resp.IsSuccessStatusCode; status = [int]$resp.StatusCode; body = $body }
}

function Process-Folder($dir) {
  $name = $dir.Name

  $jpg = Get-ChildItem -Path $dir.FullName -Filter *.jpg -File -ErrorAction SilentlyContinue | Select-Object -First 1
  if (-not $jpg) { $jpg = Get-ChildItem -Path $dir.FullName -Filter *.jpeg -File -ErrorAction SilentlyContinue | Select-Object -First 1 }
  $xml = Get-ChildItem -Path $dir.FullName -Filter *.xml -File -ErrorAction SilentlyContinue | Select-Object -First 1

  if (-not $jpg) { return $false }   # belum ada gambar -> coba lagi nanti (jangan tandai)

  # Stabil? file terakhir ditulis > StabilizeSeconds lalu.
  $newest = (Get-ChildItem -Path $dir.FullName -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1).LastWriteTime
  if ($newest -gt (Get-Date).AddSeconds(-$StabilizeSeconds)) { return $false }

  try {
    $r = Send-Exam $jpg.FullName ($(if ($xml) { $xml.FullName } else { $null }))
    if ($r.ok) {
      Write-Log ("KIRIM OK  [{0}]  HTTP {1}  {2}" -f $name, $r.status, $r.body)
      Save-Processed $name
      return $true
    } else {
      Write-Log ("KIRIM GAGAL [{0}]  HTTP {1}  {2}  -> retry nanti" -f $name, $r.status, $r.body)
      return $false   # jangan tandai -> retry sapuan berikut
    }
  } catch {
    Write-Log ("ERROR [{0}]  {1}  -> retry nanti" -f $name, $_.Exception.Message)
    return $false
  }
}

# ---- baseline (run pertama): tandai semua folder lama tanpa mengirim ----
if (-not (Test-Path $StateFile) -and -not $Backfill) {
  $existing = Get-ChildItem -Path $DataDir -Directory -ErrorAction SilentlyContinue
  foreach ($d in $existing) { Save-Processed $d.Name }
  Write-Log ("BASELINE: {0} folder lama ditandai sudah-diproses (TIDAK dikirim). Pakai -Backfill utk kirim historis." -f $existing.Count)
}

Write-Log ("MULAI watcher. Data='{0}'  ->  '{1}'  (poll {2}s, Once={3}, Backfill={4})" -f $DataDir, $IngestUrl, $PollSeconds, $Once, $Backfill)

do {
  if (-not (Test-Path $DataDir)) {
    Write-Log ("PERINGATAN: folder data tidak ditemukan: {0}" -f $DataDir)
  } else {
    $dirs = Get-ChildItem -Path $DataDir -Directory -ErrorAction SilentlyContinue
    foreach ($d in $dirs) {
      if ($Processed.Contains($d.Name)) { continue }
      [void](Process-Folder $d)
    }
  }
  if (-not $Once) { Start-Sleep -Seconds $PollSeconds }
} while (-not $Once)
