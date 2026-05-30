---
name: feedback-php-ssl-cacert-windows
description: "cURL error 60 (self-signed cert in chain) saat HTTP ke API eksternal = PHP Windows tanpa CA bundle, bukan bug kode. Fix: pasang cacert.pem + php.ini."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8398aec9-3f53-4042-a6c0-14a31040adf6
---

Di mesin dev user (Windows, PHP `C:\php`, php.ini `C:\php\php.ini`), HTTP keluar via cURL/Laravel `Http` gagal dengan **cURL error 60: SSL certificate ... self-signed certificate in certificate chain (19)**. Ini muncul saat tes koneksi Satu Sehat (2026-05-30) ke `api-satusehat-stg.dto.kemkes.go.id`.

**Penyebab:** PHP tidak punya CA bundle. `php -r "ini_get('curl.cainfo')"` & `openssl.cafile` KOSONG, default `C:\Program Files\Common Files\SSL\cert.pem` tidak ada. Sertifikat server-nya SAH — cURL lokal tak bisa verifikasi.

**Bukan bug kode.** Jangan matikan verify SSL (`verify=>false`) — ini integrasi data kesehatan ke Kemenkes.

**Fix permanen (sudah diterapkan):**
1. Download CA bundle resmi: `https://curl.se/ca/cacert.pem` → `C:\php\extras\ssl\cacert.pem`.
2. Edit `C:\php\php.ini`: uncomment + set `curl.cainfo = "C:\php\extras\ssl\cacert.pem"` dan `openssl.cafile="C:\php\extras\ssl\cacert.pem"`.
3. **Restart PHP server** (php.ini hanya terbaca saat start — `composer dev`/`php artisan serve` harus di-restart).

**Verifikasi:** `curl_errno`=0 + HTTP 401 (kredensial palsu) = SSL handshake sukses, request sampai server. Laravel `Http::post(...)` juga 401 (bukan exception).

**Untuk PRODUKSI:** ulangi pemasangan cacert.pem di server produksi — bukan bagian repo. Terkait [[feature-bridging-satusehat]].
