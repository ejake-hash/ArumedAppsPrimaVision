---
name: feature-request-unit-notif
description: Notifikasi status request/retur untuk unit pemohon (Farmasi) + tab Pending Pengiriman di sisi admin Inventori. Polling fallback karena Reverb tidak aktif.
metadata: 
  node_type: memory
  type: project
  originSessionId: 1d2d50c0-6401-450f-ae76-f0793eb2c8c2
---

# Notifikasi Request/Retur untuk Unit Pemohon

## Sisi Admin Inventori — RequestUnitView

Tab di [arumed-frontend/src/views/inventori-farmasi/RequestUnitView.vue](arumed-frontend/src/views/inventori-farmasi/RequestUnitView.vue):

- **Permintaan** — status SUBMITTED (butuh approve/reject)
- **Pending Pengiriman** — status APPROVED (sudah disetujui, belum dikirim) — tab BARU 2026-05-28
- **Retur** — status SUBMITTED
- **History** — hanya status final (DELIVERED/CLOSED/REJECTED). APPROVED **tidak** masuk history (sudah dipindah ke Pending).

Modal Detail Permintaan punya footer kontekstual:
- SUBMITTED → tombol Setujui + Tolak
- APPROVED → tombol Kirim (buka deliver modal) + Tolak
- DELIVERED → tombol Tutup Permintaan

`HIST_REQ_STATUSES = ['DELIVERED','CLOSED','REJECTED']` (sengaja exclude APPROVED).

## Sisi Unit Pemohon — FarmasiView Tab "Manajemen Stok"

Bell notifikasi + panel dropdown di toolbar (kanan atas, satu group dengan search "Cari obat", "Minta Barang", "Retur Obat") di [arumed-frontend/src/views/FarmasiView.vue](arumed-frontend/src/views/FarmasiView.vue).

Buffer: `stokNotifs` (cap 50, in-memory, hilang saat refresh).

### Sumber data: WS + polling fallback

**Why polling**: env lokal user tidak setup Reverb. Backend `.env`: `BROADCAST_CONNECTION=log` (event hanya ditulis ke `storage/logs/laravel.log`, tidak ke WS). Frontend `.env`: `VITE_REVERB_APP_KEY` kosong → `connectInventoriWs()` early-return. Event `unit-notified` tidak pernah sampai browser.

**How to apply**:
1. WS handler (`inventori-farmasi-FARMASI` channel, event `unit-notified`) tetap dipasang — kalau Reverb diaktifkan, dia realtime.
2. `pollNotifs()` jalan tiap 10 detik (`setInterval`):
   - `unitRequestApi.list({station:'FARMASI', per_page:50})` + `unitReturnApi.list({station:'FARMASI', per_page:50})`
   - Diff status row vs snapshot `_reqStatusSnap` / `_retStatusSnap` (Map id→status)
   - Status berubah → `pushStokNotif({kind, action, number, status, message})`
   - First sync (`_notifInit=false`) → isi snapshot **tanpa** push notif (cegah banjir saat halaman dibuka)
   - DELIVERED tambahan trigger `fetchStok()` supaya angka stok update
3. Helper `_extractRows(res)` fleksibel handle `res.data.data` (array) ATAU `res.data.data.data` (paginator). Service balikin `LengthAwarePaginator` via `$this->ok(...)` → struktur `{success, data: {current_page, data: [...], ...}}`.

### Status mapping yang di-notify

```
REQ_ACTION_BY_STATUS = { APPROVED, DELIVERED, REJECTED, CLOSED }
RET_ACTION_BY_STATUS = { RECEIVED, REJECTED }
```

Status diluar map (mis. masih SUBMITTED) tidak push notif walaupun ada di list.

### Debug

Console DEV mode log:
- `[stok-notif] poll {req, ret, init, snapReq}` tiap interval
- `[stok-notif] req status change <nomor> <prev> -> <new>` saat ada perubahan
- `[stok-notif] poll error <status> <body>` saat HTTP error

Tombol "Refresh" di header panel = trigger `pollNotifs()` manual.

## Gotcha

- **Role `farmasi` punya `request_unit.read/write/delete`** (RolePermissionSeeder.php:48-61) — bukan 403.
- Nilai `requesting_station` saat Farmasi minta: hardcoded `'FARMASI'` uppercase di [RequestObatModal.vue:85](arumed-frontend/src/components/farmasi/RequestObatModal.vue#L85). Backend filter exact match.
- Event broadcast: `App\Events\InventoriUnitNotified` dengan channel `inventori-farmasi-{STATION}`, payload `{kind, action, number, status, message}`. Di-dispatch dari `UnitRequestService::notifyUnit()` dan `UnitReturnService::notifyUnit()`.

Terkait: [[feature-pembelian-penerimaan]]
