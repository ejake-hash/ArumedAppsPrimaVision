# Realtime Antrean — Aktivasi WebSocket (Laravel Reverb)

Status saat ini: **kode siap, WebSocket belum aktif.** Antrean berjalan dengan
**polling 8 detik** (otomatis dipakai selama kredensial Reverb kosong). Untuk
membuat antrean hampir-instan (<1 detik) di produksi, aktifkan Reverb dengan
langkah di bawah — **tanpa mengubah kode**, hanya `.env` + 1 proses server.

## Yang SUDAH disiapkan (tidak perlu disentuh lagi)
- Package `laravel/reverb` terinstall.
- `config/reverb.php` + `config/broadcasting.php` ter-publish (koneksi `reverb` ada).
- Event broadcast sudah ter-fire dari `QueueService` (`AntreanTvUpdated`,
  `TriaseQueueUpdated`) di SEMUA aksi antrean (panggil/mulai/lewati/advance).
- Channel semuanya **PUBLIC** → tidak perlu broadcasting-auth route / token.
- Frontend store sudah punya `connectWs()` + fallback polling otomatis.
- `pusher-js` (client protokol Reverb) sudah ada di frontend.

## Langkah aktivasi di PRODUKSI

### 1. Backend `.env`
```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=arumed
REVERB_APP_KEY=<acak-panjang>           # ⚠️ harus SAMA dengan VITE_REVERB_APP_KEY frontend
REVERB_APP_SECRET=<acak-panjang>
REVERB_HOST="ws.domain-klinik.id"       # host WS yang dijangkau BROWSER (publik)
REVERB_PORT=443                          # 443 di balik reverse-proxy WSS
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0               # tempat proses reverb MENDENGAR
REVERB_SERVER_PORT=8080
```
Lalu: `php artisan config:clear`

### 2. Frontend `.env` (lalu **rebuild**)
```env
VITE_REVERB_APP_KEY=<sama-dgn-REVERB_APP_KEY-backend>
VITE_REVERB_HOST=ws.domain-klinik.id
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```
Lalu: `npm run build` (env Vite di-bake saat build — wajib rebuild).

### 3. Jalankan proses WebSocket server
```bash
php artisan reverb:start
```
Di produksi, jalankan sebagai **daemon** (seperti queue worker). Contoh systemd:
```ini
# /etc/systemd/system/arumed-reverb.service
[Unit]
Description=Arumed Reverb WebSocket
After=network.target
[Service]
User=www-data
WorkingDirectory=/var/www/arumed/backend
ExecStart=/usr/bin/php artisan reverb:start
Restart=always
[Install]
WantedBy=multi-user.target
```
`sudo systemctl enable --now arumed-reverb`

### 4. Reverse proxy WSS (Nginx) — upgrade ws di balik HTTPS
```nginx
location /app {                       # path default pusher-protocol
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
}
```
Buka port firewall bila tidak di-proxy.

## Verifikasi setelah aktif
1. `php artisan reverb:start` jalan tanpa error, log "Starting server on 0.0.0.0:8080".
2. Buka DevTools → Network → WS: ada koneksi `wss://...` status 101, frame masuk
   saat ada perubahan antrean.
3. Buka 2 tab (mis. Admisi + Triase): daftarkan pasien di Admisi → muncul di
   Triase **tanpa nunggu 8 detik**.

## Rollback / matikan WebSocket
Kembalikan `BROADCAST_CONNECTION=log` (backend) + kosongkan `VITE_REVERB_APP_KEY`
(frontend, rebuild) + stop proses reverb. Otomatis balik ke polling 8 detik.
Tidak ada perubahan kode yang perlu di-revert.

## Catatan
- **KEY backend & frontend WAJIB identik** — kalau beda, client gagal subscribe
  (diam-diam fallback polling tidak, malah error koneksi). Cek paling sering salah di sini.
- Channel public: tidak ada `routes/channels.php`. Kalau nanti butuh private
  channel (mis. notifikasi per-user), baru tambah `withBroadcasting()` di
  `bootstrap/app.php` + `routes/channels.php`.
- Reverb butuh ekstensi PHP `pcntl` & `posix` di server Linux produksi.
