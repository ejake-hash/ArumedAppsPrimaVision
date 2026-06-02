# =============================================================================
# E2E RBAC gate — 3 stasiun klinis (penunjang / perawat / refraksi)
# Memverifikasi: GET butuh <modul>.read, mutasi butuh <modul>.write.
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

# Kembalikan status code HTTP dari sebuah request (tanpa melempar pada 4xx/5xx).
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

Write-Host "== Login semua role ==" -ForegroundColor Cyan
$tok = @{}
foreach ($u in 'superadmin','penunjang','perawat','refraksionis','dokter','kasir','admisi') {
    $pwd = if ($u -eq 'superadmin') { 'Superadmin@123' } else { '888888' }
    $tok[$u] = Login $u $pwd
    if ($tok[$u]) { Write-Host "  ok $u" -ForegroundColor Green } else { $fail++ }
}

Write-Host "`n== PENUNJANG ==" -ForegroundColor Cyan
# GET read: penunjang(R+W), dokter(R), admisi(R) -> allow ; (tak ada role tanpa read yg relevan diuji di sini)
Check "penunjang GET /antrian (penunjang)" $tok.penunjang 'GET' '/penunjang/antrian' $null 'allow'
Check "penunjang GET /antrian (dokter, R)"  $tok.dokter    'GET' '/penunjang/antrian' $null 'allow'
Check "penunjang GET /antrian (kasir, no-read)" $tok.kasir 'GET' '/penunjang/antrian' $null 'deny'
# Mutasi write: penunjang(W) allow ; dokter(R only) deny ; admisi(R only) deny
Check "penunjang PUT panggil (penunjang, W)" $tok.penunjang 'PUT' "/penunjang/antrian/$dummy/panggil" $null 'allow'
Check "penunjang PUT panggil (dokter, R only)" $tok.dokter   'PUT' "/penunjang/antrian/$dummy/panggil" $null 'deny'
Check "penunjang POST /hasil (admisi, R only)" $tok.admisi   'POST' '/penunjang/hasil' @{ diagnostic_order_id = $dummy } 'deny'
Check "penunjang PUT panggil (superadmin bypass)" $tok.superadmin 'PUT' "/penunjang/antrian/$dummy/panggil" $null 'allow'

Write-Host "`n== PERAWAT ==" -ForegroundColor Cyan
Check "perawat GET /antrian (perawat)"        $tok.perawat 'GET' '/perawat/antrian' $null 'allow'
Check "perawat GET /antrian (kasir, no-read)" $tok.kasir   'GET' '/perawat/antrian' $null 'deny'
Check "perawat PUT panggil (perawat, W)"      $tok.perawat 'PUT' "/perawat/antrian/$dummy/panggil" $null 'allow'
Check "perawat PUT panggil (dokter, R only)"  $tok.dokter  'PUT' "/perawat/antrian/$dummy/panggil" $null 'deny'
Check "perawat POST /asesmen (refraksionis, R only)" $tok.refraksionis 'POST' '/perawat/asesmen' @{ visit_id = $dummy } 'deny'

Write-Host "`n== REFRAKSI (key=refraksionis) ==" -ForegroundColor Cyan
Check "refraksi GET /antrian (refraksionis)"   $tok.refraksionis 'GET' '/refraksi/antrian' $null 'allow'
Check "refraksi GET /antrian (perawat, R)"      $tok.perawat     'GET' '/refraksi/antrian' $null 'allow'
Check "refraksi GET /antrian (kasir, no-read)"  $tok.kasir       'GET' '/refraksi/antrian' $null 'deny'
Check "refraksi PUT panggil (refraksionis, W)"  $tok.refraksionis 'PUT' "/refraksi/antrian/$dummy/panggil" $null 'allow'
Check "refraksi PUT panggil (dokter, R only)"   $tok.dokter      'PUT' "/refraksi/antrian/$dummy/panggil" $null 'deny'
Check "refraksi POST /pemeriksaan (perawat, R only)" $tok.perawat 'POST' '/refraksi/pemeriksaan' @{ visit_id = $dummy } 'deny'

Write-Host ("`n== HASIL: {0} PASS / {1} FAIL ==" -f $pass, $fail) -ForegroundColor Cyan
if ($fail -gt 0) { exit 1 } else { exit 0 }
