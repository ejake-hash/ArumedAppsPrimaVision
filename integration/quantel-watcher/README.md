# Quantel Compact Touch → Arumed (Watcher)

Watcher folder untuk alat **Quantel Compact Touch** (USG/Biometry, `192.168.100.23`)
yang **tidak punya DICOM** — alat ini hanya meng-export tiap pemeriksaan ke folder
`C:\Compact Touch\Data\` (1 folder GUID berisi `.jpg` + `.xml`).

Watcher memantau folder itu, dan setiap pemeriksaan **baru** dikirim ke Arumed:

```
POST http://192.168.100.20:8000/api/v1/integrasi/penunjang/ingest
multipart: file=<jpg>, xml=<xml>, source=QUANTEL_WATCHER
header:    Authorization: Bearer <PENUNJANG_BRIDGE_TOKEN>
```

Server yang mem-parse XML → No.RM + ExamKey + nilai biometri (AL/K/ACD + tabel IOL).
Hasil tertaut otomatis ke order penunjang pasien (match No.RM). Bila tak ketemu → masuk
**Inbox Hasil** di stasiun Penunjang untuk ditautkan manual. **Idempoten** (ExamKey):
file yang sama terkirim 2× tidak menggandakan data.

---

## DI MANA dijalankan?

**Di PC alat Quantel (`192.168.100.23`)** — karena watcher butuh akses langsung ke
`C:\Compact Touch\Data\`. (Alternatif: share folder itu ke server lalu jalankan di
server — tapi paling sederhana & andal dijalankan di PC alat itu sendiri.)

Tidak perlu install apa pun: pakai **PowerShell bawaan Windows**.

---

## Langkah setup (sekali)

### 1) Set token di SERVER (wajib, kalau belum)
Di server Arumed (`192.168.100.20`), file `backend/.env`:

```
PENUNJANG_BRIDGE_TOKEN=<string-acak-panjang-rahasia>
```

Lalu: `php artisan config:clear`. (Kalau kosong, endpoint balas 401.)

> Buat token acak, mis. di server: `php -r "echo bin2hex(random_bytes(24));"`

### 2) Salin watcher ke PC alat
Salin folder ini ke PC Quantel, mis. ke `C:\ArumedWatcher\`:
- `quantel-watcher.ps1`

### 3) Isi token di watcher
Buka `quantel-watcher.ps1`, bagian **KONFIGURASI**, ganti:
```powershell
$Token = "GANTI-DENGAN-TOKEN-SAMA-DENGAN-.ENV-SERVER"
```
dengan token **sama persis** seperti di `.env` server. (Atau set environment
variable `PENUNJANG_BRIDGE_TOKEN` di PC itu — script akan memakainya.)

Pastikan juga `$ArumedBase` & `$DataDir` benar (default sudah sesuai).

### 4) Uji koneksi & satu pengiriman
Buka **PowerShell** (cukup user biasa), lalu:

```powershell
cd C:\ArumedWatcher
powershell -ExecutionPolicy Bypass -File .\quantel-watcher.ps1 -Once
```

- Run **pertama** otomatis melakukan **baseline**: semua folder lama ditandai
  "sudah-diproses" (TIDAK dikirim) supaya 11rb+ data historis tak membanjiri server.
- `-Once` = satu kali sapu lalu berhenti (untuk tes). Karena baru baseline, belum ada
  yang dikirim. **Lakukan 1 pemeriksaan biometri baru** di alat, lalu jalankan lagi
  `-Once` → folder baru itu akan terkirim. Cek log di `C:\ArumedWatcher\watcher.log`
  dan cek di Arumed (order penunjang pasien / tab **Inbox Hasil** Penunjang).

### 5) Jalankan terus-menerus
Untuk produksi, jalankan tanpa `-Once` (loop tiap 15 detik):

```powershell
powershell -ExecutionPolicy Bypass -File C:\ArumedWatcher\quantel-watcher.ps1
```

Biar otomatis hidup saat PC menyala & restart bila mati, daftarkan ke
**Task Scheduler** (lihat bawah).

---

## Auto-start via Task Scheduler (disarankan)

1. Buka **Task Scheduler** → *Create Task…*
2. **General**: nama `Arumed Quantel Watcher`; centang *Run whether user is logged on or
   not*; *Run with highest privileges* (opsional).
3. **Triggers**: New → *At startup* (dan/atau *At log on*). Centang *Repeat task* tidak
   perlu (script sudah loop sendiri).
4. **Actions**: New → *Start a program*:
   - Program: `powershell.exe`
   - Arguments: `-ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\ArumedWatcher\quantel-watcher.ps1"`
5. **Settings**: centang *If the task fails, restart every 1 minute* (maks 3×), dan
   *Do not start a new instance* bila sudah jalan.
6. OK. Klik kanan task → **Run** untuk mulai sekarang.

---

## Parameter

| Parameter | Arti |
|---|---|
| (tanpa) | Loop terus tiap 15 detik. Run pertama = baseline (historis tidak dikirim). |
| `-Once` | Satu kali sapu lalu keluar (untuk uji). |
| `-Backfill` | Proses & kirim JUGA semua folder lama (historis). **Hati-hati**: ribuan file. |

## File kerja (di PC alat)
- `C:\ArumedWatcher\processed.txt` — daftar folder yang sudah diproses (state).
  Hapus file ini untuk "lupa" & baseline ulang.
- `C:\ArumedWatcher\watcher.log` — log pengiriman (OK/GAGAL + balasan server).

## Troubleshooting
- **HTTP 401** → token watcher ≠ `.env` server, atau server belum `config:clear`.
- **Tidak terkirim** → cek `$DataDir` benar; pastikan pemeriksaan menghasilkan `.jpg`
  (watcher butuh gambar; XML opsional tapi disarankan untuk match No.RM).
- **Masuk Inbox, bukan ter-tautkan** → No.RM di alat (`PatientIdNumber`) tidak sama
  dengan No.RM Arumed, atau order penunjang pasien hari itu belum dibuat dokter.
- **Connection refused/timeout** → cek jaringan ke `192.168.100.20:8000` (firewall).

## Catatan
- Alat **OCT Maestro** (`192.168.100.21`) BUKAN pakai watcher ini — ia DICOM penuh
  (Worklist + Storage) dan butuh **Orthanc** di server (setup terpisah).
