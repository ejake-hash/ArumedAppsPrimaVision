---
name: gotcha-notification-recipient-not-null
description: "notifications.recipient_id NOT NULL — JANGAN insert Notification::create dengan recipient_id=null (memicu 23502 → 500 / rollback transaksi). Resolve penerima per-role lalu 1 notif/penerima."
metadata:
  node_type: memory
  type: feedback
---

# `notifications.recipient_id` NOT NULL — jangan broadcast dengan recipient_id=null

Di DB `arumed_primavision` (pgsql), kolom `notifications.recipient_id` bertipe `uuid **NOT NULL**`. Memanggil `Notification::create(['recipient_id' => null, ...])` melempar `SQLSTATE[23502] Not null violation`.

## Why
Ditemukan 2026-06-01 saat E2E modul Asuransi. Dua service punya komentar "broadcast — implementasi recipient resolver via Reverb nanti" dan insert `recipient_id => null`:
- **`AsuransiService::notifySupervisor`** (status verif NEEDS_CLARIFICATION/REJECTED). Dipanggil **DI DALAM `DB::transaction`** `createVerifikasi`/`updateVerifikasi` → insert gagal → **seluruh transaksi rollback, verifikasi ISSUE tak tersimpan, user kena 500**. Alur "tandai bermasalah" rusak total.
- **`DokterService::rejectDocument`** (SIGNATURE_REJECTED). Tak ber-transaksi, TAPI status dokumen sudah ter-`update('REJECTED')` sebelum insert notif → crash 500 meninggalkan **state inkonsisten** (dokumen REJECTED tapi request gagal).

Reverb broadcast tak pernah jadi → kolom tetap NOT NULL → kode "placeholder null" ini bom waktu di tiap call-site.

## How to apply
JANGAN pernah insert Notification dengan `recipient_id` null. Resolve penerima dulu, buat **1 notifikasi per penerima**, dan **skip diam-diam** kalau tak ada penerima (jangan gagalkan operasi induk). Pola kanonik = `RekamMedisService::notifySigners`:
```php
$recipients = User::whereHas('role', fn($q) => $q->whereIn('name', [...roles...]))
    ->where('is_active', true)->pluck('id');
foreach ($recipients as $rid) {
    Notification::create(['recipient_id' => $rid, 'type' => ..., 'is_read' => false, 'resend_count' => 0, ...]);
}
```
Pemetaan penerima yang dipakai: Asuransi → role `kasir` + `superadmin` aktif (audience modul = `kasir.*`). Dokter reject → `patient_documents.created_by_station` (mis. `DOKTER`) di-`strtolower()` → cocokkan ke `roles.name`. Audit kolom (`verified_by`/`submitted_by`/`performed_by`) tetap loose via `auth('api')->id()`.

Saat audit modul lain yang kirim notifikasi internal, grep `'recipient_id'\s*=>\s*null` dulu — kemungkinan masih ada call-site lain dengan cacat sama. Terkait: [[feature-insurance-tpa]], [[feedback-controller-ok-error-helper]].
