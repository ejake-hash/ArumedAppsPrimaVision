---
name: feature-clinic-logo-kop-surat
description: "Logo klinik upload-able di Profil Klinik, otomatis render <img> di dokumen Form Registry via binding `clinic.logo_path` (type image_url). Level 1 implementation 2026-05-30."
metadata: 
  node_type: memory
  type: project
  originSessionId: 8fcf34a0-3abb-434b-89f2-c252f30ea7a0
---

Logo klinik bisa di-upload di UI Profil Klinik, lalu dipakai di template form RM via placeholder `{{clinic_logo}}` dengan binding `clinic.logo_path`. Renderer otomatis convert path → absolute URL → `<img>` tag.

**Why:** klinik butuh kop surat di dokumen formal (resume medis, surat rujukan, dst). Sebelumnya logo tidak bisa di-upload via UI (kolom `logo_path` ada di DB tapi field admin tidak ada). Saat dipakai di template, value cuma path string — tidak render sebagai gambar.

**How to apply:**

## Backend

### 2 endpoint baru (di group `/master`, permission `pengaturan.write` via parent guard)

- `POST /master/profil-klinik/logo` — multipart upload (max 2MB, mimes png/jpg/jpeg/svg/webp). Simpan ke `storage/app/public/clinic/logo_{YmdHis}.{ext}`, update kolom `logo_path` di clinic_profiles. Hapus file lama sebelum simpan baru.
- `DELETE /master/profil-klinik/logo` — hapus file + null-kan `logo_path`.

Disk: `public` (laravel `storage:link` symlink ke `public/storage/`). URL accessible via `/storage/clinic/...`.

### BindingResolver auto-convert *_path → base64 data URL inline

`resolveClinic()` di [BindingResolver.php](backend/app/Services/FormRegistry/BindingResolver.php): kalau kolom berakhir `_path` (logo_path, signature_path, stamp_path) dan value non-empty string, return `data:image/...;base64,...` via helper `encodeImageAsDataUrl()`. Field lain pakai `normalizeScalar()` seperti biasa.

**Why base64 inline bukan Storage::url():**
1. **Cross-origin safe** — Vite dev (:5173) vs Laravel (:8000) sering bermasalah saat browser request `<img src>` ke origin beda. Base64 hindari HTTP request sepenuhnya.
2. **Snapshot truly immutable** — dokumen FINALIZED tidak break kalau file fisik logo dihapus/diganti (Level 3 partial dari roadmap). Sesuai spirit PMK 24/2022.
3. **Print-friendly** — data URL konsisten di window print, tidak ada race condition image load.

**Trade-off**: `rendered_html` jadi ~30-40KB lebih besar per logo. Diterima karena `rendered_html_gz` (Gap #8) compress ~70%. File logo size kontrol ada di upload endpoint (max 2MB).

### DocumentRenderer wrap <img> untuk type image_url

[DocumentRenderer.php](backend/app/Services/FormRegistry/DocumentRenderer.php): saat resolve field dengan `type === 'image_url'` dan value string non-empty, wrap dengan:
```html
<img src="{URL}" alt="{label}" style="max-height:80px;height:auto;display:inline-block;"/>
```
`max_height_px` bisa di-override per field di field_schema (default 80px).

## Frontend

### ProfilKlinikView — section "Logo Klinik (Kop Surat)"

[ProfilKlinikView.vue](arumed-frontend/src/views/master-data/ProfilKlinikView.vue):
- Section baru di atas Identitas Klinik
- Preview box 200×100px dashed border
- Tombol "+ Upload Logo" / "Ganti Logo" + tombol "Hapus" (visible kalau ada logo)
- Hint: format PNG/JPG/SVG/WebP, max 2MB, rekomendasi 400×200px (rasio 2:1)
- File picker hidden, trigger via `ref="logoFileInput"` + `.click()`
- `logoUrl` computed: prefix `/storage/` ke `logo_path` (atau as-is kalau sudah absolute)
- Toast feedback success/error

### API helper

[api.js](arumed-frontend/src/services/api.js) `masterApi.profilKlinik`:
- `uploadLogo(file)` — FormData multipart, timeout 30s
- `deleteLogo()` — DELETE

## Cara pakai di template form

Di TipTap layout editor (wizard Step 2):

1. Tambah field baru di field list:
   - `key`: `clinic_logo` (atau bebas)
   - `type`: `image_url`
   - `binding.kind`: `clinic`
   - `binding.source`: `clinic.logo_path`
   - `max_height_px`: opsional, default 80
2. Insert placeholder di layout HTML:
   ```html
   <div style="text-align:center; margin-bottom:1rem;">
     {{clinic_logo}}
     <h2>{{clinic_name}}</h2>
     <p>{{clinic_address}} | Telp: {{clinic_phone}}</p>
   </div>
   <hr/>
   ```
3. Renderer akan substitute `{{clinic_logo}}` jadi `<img src="/storage/clinic/logo_*.png" .../>`

## Snapshot immutability

Dokumen yang sudah FINALIZED simpan `rendered_html` (atau `rendered_html_gz`) dengan `<img src="/storage/..."/>` baked in. **Hapus/ganti logo TIDAK mempengaruhi dokumen lama** — kecuali file fisik dihapus dari disk (img broken). Kalau perlu hard-snapshot, alternatif: encode base64 inline saat render. Belum diimplementasi (acceptable trade-off untuk Level 1).

## Gotcha

- **`logo_url` (BindingResolver) vs `logoUrl` (frontend computed)** — keduanya prefix `/storage/`. Backend pakai `Storage::url()` (yang mungkin prepend domain di production), frontend hard-code `/storage/...` (relative). Selama domain frontend = backend, OK. Beda domain → backend Storage::url() lebih akurat.
- **`pdf_engine` validation di updateProfilKlinik** masih cuma accept `puppeteer` — tidak include `chromium` dst. Bukan relevant untuk logo, tapi catat untuk audit.
- **Format SVG ok** — tapi inline SVG sebaiknya di-sanitize kalau klinik upload SVG dari sumber tidak trusted (XSS via embedded script). Saat ini cuma `htmlspecialchars` di alt — `src` URL aman.
- **Tidak ada sanitasi nama file** — backend pakai `now()->format('YmdHis').ext` jadi nama file deterministic, bukan dari client. Safe dari path traversal.

## Yang belum diimplementasi (Level 2/3 backlog)

- **Tombol "Insert Kop Surat" di TipTap** — quick action paste HTML block kop standar (logo + nama + alamat). Manual insert saat ini (Level 1).
- **Setting kop universal** (Level 3) — auto-prepend ke semua dokumen kecuali opt-out. Belum.
- **Signature_path & stamp_path** — kolom DB ada, BindingResolver sudah handle *_path conversion, tapi UI upload belum. Path sama: tambah endpoint upload + section UI mirror logo.
- **Base64 inline encoding** saat finalize untuk truly immutable snapshot.

Links: [[feature-form-registry]] (Form Registry & template binding), [[feedback-styling-visibility]] (tombol pakai #1763d4).
