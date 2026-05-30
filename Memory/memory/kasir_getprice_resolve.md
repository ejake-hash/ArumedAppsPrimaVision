---
name: kasir-getprice-resolve
description: KasirService::getPrice() — sentral tariff resolver per insurer untuk procedure/medication/bhp/iol/equipment setelah classification di-drop. TPA-aware + fallback ke insurer sistem. Penunjang resolve via 'procedure' (arm 'diagnostic_test' sudah dihapus 2026-05-30).
metadata: 
  node_type: memory
  type: project
  originSessionId: 230db285-f854-4b32-a7a8-4da15c725131
---

[KasirService::getPrice()](backend/app/Services/KasirService.php#L217) — **sentral** untuk resolve harga 6 tipe item (`procedure`/`medication`/`bhp`/`iol`/`equipment`/`diagnostic_test`) per visit. Dipanggil oleh DokterService (tarif tindakan), KasirService internal (billing), dan modul lain yang butuh harga per-penjamin.

**Setelah migration `2026_05_26_000011_drop_classification_from_tariffs`** (lihat [[feature-tarif-paket-bedah]]), kolom `classification` hilang dari 4 tabel tariff. Lookup sekarang murni `(item_id, insurer_id)`.

**Resolve order (refactor 2026-05-28):**
1. Bila `visit.insurer_id` ada → load `Insurer` model → pakai `tariffInsurerId()` (resolve TPA: child → parent_id).
2. Bila NULL → resolve insurer sistem dari `guarantor_type` (UMUM/BPJS/SOSIAL via `is_system=true` + `type=…`).
3. Query `(item_id, resolved_insurer_id)` di tabel tariff.
4. Fallback: insurer sistem UMUM.
5. Terakhir: return 0.

**Cache**: id insurer sistem di-cache per-request via property `$systemInsurerCache` agar hindari N+1.

**Why penting:** Sebelum refactor, kode masih query `where('classification', $guarantorType)` walau kolomnya sudah hilang → query gagal silent (return null) → SEMUA harga di sistem jadi Rp 0. Bug ini fatal & senyap karena tidak throw exception (Laravel query builder tolerant terhadap kolom yang difilter tidak ada di SELECT — tapi WHERE column not exist akan throw QueryException; bug-nya: caller-nya pakai `try/catch` di DokterService yang menelan exception → tariff list muncul kosong tanpa error UI).

**How to apply:** 
- Saat tambah tipe item baru ke tariff → tambah ke `match` di `getPrice`.
- Saat butuh harga manual di service lain → panggil `app(KasirService::class)->getPrice(...)`, jangan query langsung ke tabel tariff.
- Saat Admisi create visit → sebaiknya auto-set `insurer_id` ke insurer sistem (UMUM/BPJS/SOSIAL) sesuai `guarantor_type` agar lookup tidak rely on fallback.

**PENUNJANG — arsitektur FINAL 2026-05-30 (procedure-based):** Penunjang = `procedures` kategori "Penunjang". `diagnostic_orders.test_type` simpan **KODE procedure** (mis. PNJ-001/BIOM). `buildPenunjangLines` ([[feature-kasir-view]]) & `DokterService::getPenunjangBilling` resolve `code → Procedure::whereIn('code')` → **`getPrice('procedure', $proc->id, ...)`** (batch, no N+1). Tagihan tetap label item_type PENUNJANG tapi harga via procedure_tariffs. Detail [[feature-penunjang-tarif-wiring]].

**JANGAN bingung dgn arm lama yang sudah DIHAPUS:** arm `'diagnostic_test'` → `diagnostic_test_type_tariffs` (wiring 2026-05-29) sudah **dibongkar total** — tabel di-drop, arm getPrice dihapus. getPrice sekarang hanya: procedure/medication/bhp/iol/equipment. Sejarah bug: dokter dulu kirim **nama** (bukan kode) → silent tak tertagih; sudah fix (kirim kode = kode procedure).
