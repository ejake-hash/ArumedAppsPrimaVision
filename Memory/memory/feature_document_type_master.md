---
name: feature-document-type-master
description: "Master Jenis Dokumen RM — UI CRUD lengkap untuk `document_types`. Fundamental karena tiap klinik beda struktur form RM. 4 endpoint + 1 view + sidebar entry."
metadata: 
  node_type: memory
  type: project
  originSessionId: 8fcf34a0-3abb-434b-89f2-c252f30ea7a0
---

Master "Jenis Dokumen RM" sekarang editable via UI di `/master-data/document-type`. Sebelumnya read-only (cuma seeder).

**Why:** setiap klinik punya kategori form RM yang beda. Hardcode di seeder tidak scalable — admin harus bisa tambah/edit jenis baru tanpa deploy. Implementasi 2026-05-30.

**How to apply:**

## Backend

4 endpoint di MasterDataController (semua di group `/master`, permission `form_template.read|write`):

- `GET /master/document-types?all=1` — list (param `all=1` include inactive untuk UI master; default cuma active untuk dropdown wizard)
- `POST /master/document-type` — create
- `PUT /master/document-type/{id}` — update + **anti-circular check** (parent tidak boleh diri sendiri atau descendant via BFS)
- `DELETE /master/document-type/{id}` — soft delete dengan **3 guard**:
  - `document_templates` count > 0 → refuse + sebutkan jumlah
  - `patient_documents` count > 0 → refuse
  - `children` count > 0 (jenis turunan) → refuse
  - Suggest "nonaktifkan saja (is_active=false)" supaya tidak break referential integrity

Validation rules:
- `code` unique (ignore current id saat update)
- `fill_frequency` in `ONCE_LIFETIME,PER_VISIT,PER_EPISODE`
- `generate_type` in `AUTO,MANUAL,HYBRID`
- `category` nullable in `ADMINISTRASI,KLINIS,PENUNJANG,BEDAH,FARMASI,BILLING`
- `parent_id` nullable uuid exists in `document_types,id`
- `required_signatures` nullable array of `{role, sign_type, is_required?}`

## Frontend

- `views/master-data/DocumentTypeView.vue` — list table + filter category/status + search + modal form (create/edit)
- API helper di `formTemplateApi`: `createDocumentType`, `updateDocumentType`, `deleteDocumentType`. `documentTypes` sekarang accept params (pass `{ all: 1 }`).
- Router: `/master-data/document-type` (name: `master-document-type`, permission `form_template.read`)
- Sidebar: section "Form Rekam Medis" → 2 item (Jenis Dokumen + Template Form RM), Jenis Dokumen di atas

Modal form fields:
- Code (required, format suggestion RM-X.Y)
- Name (required)
- Frekuensi pengisian (dropdown enum, label Indonesia: "1x seumur hidup"/"Per kunjungan"/"Per episode")
- Tipe Generate (MANUAL/AUTO/HYBRID)
- Kategori (dropdown 6 enum, optional)
- Parent (dropdown self-ref dari list active, current id di-disable supaya tidak bisa pilih diri)
- Sort Order (auto = max+1 saat create)
- Show in RME (checkbox)
- Is Active (checkbox)
- Required Signatures — sub-section dengan +/× row (role text + sign_type select digital/wet + is_required checkbox)

## Gotcha & catatan

- **Toggle is_active** dari list pakai PUT update dengan payload lengkap (bukan endpoint dedicated) — sederhana, idempoten
- **Hapus vs Nonaktifkan**: error message explicit suggest nonaktifkan supaya admin tidak frustasi
- **parent_id self-ref no FK** di DB (lihat migration: "no DB-level FK to avoid PostgreSQL constraint resolution issues") — anti-circular check fully app-level via `collectDescendantIds()` BFS
- **24 jenis dokumen seeded** (22 dari seeder + 2 manual entry sebelumnya di DB dev)
- Styling pakai pattern hitam-semua + tombol primary `#1763d4` ([[feedback-styling-visibility]])
- Permission `form_template.write` (sama dengan template form) — role `dokter` granted

Links: [[feature-form-registry]] (Template Form RM pakai document_type_id sebagai parent), [[feedback-styling-visibility]].
