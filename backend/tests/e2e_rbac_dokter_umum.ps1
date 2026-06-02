# =============================================================================
# E2E RBAC - role dokter_umum (bertugas di TRIASE, bukan Pemeriksaan Dokter).
# Memverifikasi gate backend stasiun klinis untuk role baru ini:
#   - perawat (Triase): read + write  -> allow GET & mutasi (lolos gate)
#   - penunjang        : read saja     -> allow GET, DENY mutasi (403)
#   - refraksi         : read saja     -> allow GET, DENY mutasi (403)
#   - kontrol: kasir (tanpa perawat.read) -> DENY GET perawat (403)
# Catatan: grup /dokter/* belum digate permission di backend (frontend yg
# sembunyikan menu Pemeriksaan Dokter via router guard), jadi TIDAK diuji di sini.
# Lolos gate = status BUKAN 403. Ditolak gate = 403.
# =============================================================================
$ErrorActionPreference = 'Stop'
$base = 'http://127.0.0.1:8000/api/v1'
$pass = 0; $fail = 0
$dummy = '00000000-0000-0000-0000-000000000000'

function Login($user, $pwd) {
    $body = @{ username = $user; password = $pwd } | ConvertTo-Json
    try {
        $r = Invoke-RestMethod -Uri "$base/auth/login" -Method Post -Body $body -ContentType 'application/json'
        return $r.data.token
    } catch {
        Write-Host "  ! Login GAGAL untuk $user" -ForegroundColor Red
        return $null
    }
}

function StatusOf($token, $method, $path, $body) {
    $headers = @{ Authorization = "Bearer $token" }
    try {
        $params = @{ Uri = "$base$path"; Method = $method; Headers = $headers; ContentType = 'application/json' }
        if ($body) { $params.Body = ($body | ConvertTo-Json) }
        $resp = Invoke-WebRequest @params -UseBasicParsing
        return [int]$resp.StatusCode
    } catch {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode.value__ }
        return -1
    }
}

# expectGate: 'allow' (status != 403) atau 'deny' (status == 403)
function Check($desc, $token, $method, $path, $body, $expectGate) {
    $code = StatusOf $token $method $path $body
    $is403 = ($code -eq 403)
    $ok = if ($expectGate -eq 'deny') { $is403 } else { -not $is403 }
    if ($ok) {
        $script:pass++
        Write-Host ("  PASS [{0}] {1} -> HTTP {2}" -f $expectGate, $desc, $code) -ForegroundColor Green
    } else {
        $script:fail++
        Write-Host ("  FAIL [{0}] {1} -> HTTP {2} (tak sesuai harapan)" -f $expectGate, $desc, $code) -ForegroundColor Red
    }
}

Write-Host "== Login ==" -ForegroundColor Cyan
$tok = @{}
$tok['dokter_umum'] = Login 'dokter_umum' '888888'
$tok['kasir']       = Login 'kasir' '888888'
$tok['superadmin']  = Login 'superadmin' 'Superadmin@123'
foreach ($u in 'dokter_umum','kasir','superadmin') {
    if ($tok[$u]) { Write-Host "  ok $u" -ForegroundColor Green } else { $fail++ }
}

Write-Host "`n== dokter_umum @ PERAWAT (Triase) - read+write ==" -ForegroundColor Cyan
Check "GET /perawat/antrian"            $tok.dokter_umum 'GET' '/perawat/antrian' $null 'allow'
Check "PUT mutasi /perawat (write)"     $tok.dokter_umum 'PUT' "/perawat/antrian/$dummy/mulai" @{ x = 1 } 'allow'

Write-Host "`n== dokter_umum @ PENUNJANG - read saja ==" -ForegroundColor Cyan
Check "GET /penunjang/antrian"          $tok.dokter_umum 'GET' '/penunjang/antrian' $null 'allow'
Check "PUT mutasi /penunjang (write)"   $tok.dokter_umum 'PUT' "/penunjang/order/$dummy/proses" @{ x = 1 } 'deny'

Write-Host "`n== dokter_umum @ REFRAKSI - read saja (key refraksionis) ==" -ForegroundColor Cyan
Check "GET /refraksi/antrian"           $tok.dokter_umum 'GET' '/refraksi/antrian' $null 'allow'
Check "PUT mutasi /refraksi (write)"    $tok.dokter_umum 'PUT' "/refraksi/antrian/$dummy/mulai" @{ x = 1 } 'deny'

Write-Host "`n== Kontrol: kasir TANPA perawat.read -> DENY ==" -ForegroundColor Cyan
Check "GET /perawat/antrian (kasir)"    $tok.kasir 'GET' '/perawat/antrian' $null 'deny'

Write-Host "`n== Kontrol: superadmin bypass -> ALLOW ==" -ForegroundColor Cyan
Check "GET /perawat/antrian (superadmin)" $tok.superadmin 'GET' '/perawat/antrian' $null 'allow'

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host ("  HASIL: {0} PASS / {1} FAIL" -f $pass, $fail) -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Red' })
if ($fail -gt 0) { exit 1 }
