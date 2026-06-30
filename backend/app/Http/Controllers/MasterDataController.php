<?php

namespace App\Http\Controllers;

use App\Models\DocumentTemplate;
use App\Models\MedicationTariff;
use App\Services\FormRegistry\FieldRegistry;
use App\Services\FormRegistry\FormParserService;
use App\Services\FormRegistry\FormRegistryAudit;
use App\Services\FormRegistry\FormRegistryService;
use App\Services\FormRegistry\SectionRegistry;
use App\Services\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MasterDataController extends Controller
{
    public function __construct(
        private readonly MasterDataService $service,
        private readonly FormRegistryService $formRegistry,
        private readonly FormParserService $formParser,
    ) {}

    // =========================================================================
    // CLINIC PROFILE
    // =========================================================================

    public function showProfilKlinik(): JsonResponse
    {
        $clinic = $this->service->getProfilKlinik();
        $data = $clinic->toArray();
        // Kop kanonik (sumber tunggal) — dipakai pratinjau & dokumen lain.
        $data['letterhead_html'] = $clinic->renderLetterheadHtml();
        return $this->ok($data);
    }

    public function updateProfilKlinik(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_name'       => 'sometimes|string|max:255',
            'subtitle'          => 'nullable|string|max:255',
            'tagline'           => 'nullable|string|max:255',
            'unit_line'         => 'nullable|string|max:500',
            'address'           => 'nullable|string|max:500',
            'phone'             => 'nullable|string|max:30',
            'emergency_hotline' => 'nullable|string|max:50',
            'email'             => 'nullable|email|max:255',
            'director_name'     => 'nullable|string|max:255',
            'director_sip'      => 'nullable|string|max:100',
            'rm_seq_length'     => 'nullable|integer|min:4|max:8',
            'pdf_engine'        => 'nullable|in:puppeteer',
            'watermark_enabled' => 'nullable|boolean',
            'watermark_type'    => 'nullable|required_if:watermark_enabled,true,1|in:ORIGINAL,COPY,DRAFT',
            'operating_rooms'   => 'nullable|array|min:1|max:20',
            'operating_rooms.*' => 'required|string|max:50|distinct',
        ]);

        return $this->ok($this->service->updateProfilKlinik($validated), 'Profil klinik diperbarui');
    }

    /**
     * POST /master/profil-klinik/logo — upload logo klinik.
     * File disimpan ke storage/app/public/clinic/logo.{ext}, path tersimpan
     * di `clinic_profiles.logo_path` sebagai relative path.
     */
    public function uploadProfilKlinikLogo(Request $request): JsonResponse
    {
        $request->validate([
            // SVG SENGAJA TIDAK diizinkan: file SVG bisa memuat <script>/handler event dan
            // disajikan same-origin via /storage → stored XSS. Raster (png/jpg/webp) cukup
            // untuk logo & aman. Lihat audit 30 Jun 2026.
            'file' => 'required|file|mimes:png,jpg,jpeg,webp|max:2048', // max 2MB
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $filename = 'clinic/logo_' . now()->format('YmdHis') . '.' . $ext;

        // Simpan ke disk public — accessible via /storage/clinic/...
        \Illuminate\Support\Facades\Storage::disk('public')->putFileAs(
            'clinic',
            $file,
            basename($filename),
        );

        $profile = \App\Models\ClinicProfile::query()->first();
        if (!$profile) {
            return $this->error('Profil klinik belum di-setup.', 422);
        }

        // Hapus logo lama kalau ada
        if ($profile->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($profile->logo_path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($profile->logo_path);
        }

        $profile->update(['logo_path' => $filename]);

        return $this->ok([
            'logo_path' => $filename,
            'logo_url'  => \Illuminate\Support\Facades\Storage::url($filename),
        ], 'Logo klinik di-upload.');
    }

    /**
     * DELETE /master/profil-klinik/logo — hapus logo klinik.
     */
    public function deleteProfilKlinikLogo(): JsonResponse
    {
        $profile = \App\Models\ClinicProfile::query()->first();
        if (!$profile) {
            return $this->error('Profil klinik belum di-setup.', 422);
        }
        if ($profile->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($profile->logo_path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($profile->logo_path);
        }
        $profile->update(['logo_path' => null]);
        return $this->ok(null, 'Logo klinik dihapus.');
    }

    // =========================================================================
    // ROLES
    // =========================================================================

    public function indexRoles(): JsonResponse
    {
        return $this->ok($this->service->indexRoles());
    }

    public function storeRole(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:100|unique:roles,name']);

        return $this->ok($this->service->storeRole($validated), 'Role dibuat', 201);
    }

    public function updateRole(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['name' => "required|string|max:100|unique:roles,name,{$id}"]);

        return $this->ok($this->service->updateRole($id, $validated), 'Role diperbarui');
    }

    public function deleteRole(string $id): JsonResponse
    {
        try {
            $this->service->deleteRole($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Role dihapus');
    }

    // =========================================================================
    // PEGAWAI
    // =========================================================================

    public function indexPegawai(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexPegawai($request->only(['search', 'per_page'])));
    }

    public function showPegawai(string $id): JsonResponse
    {
        return $this->ok($this->service->showPegawai($id));
    }

    public function storePegawai(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'nip'        => 'nullable|string|max:20|unique:employees,nip',
            'profession' => 'required|string|max:100',
            'sip'        => 'nullable|string|max:50',
            'str'        => 'nullable|string|max:50',
            'phone'      => 'nullable|string|max:20',
            'email'      => 'nullable|email|unique:users,email',
            'address'    => 'nullable|string|max:500',
            'role_id'    => 'nullable|uuid|exists:roles,id',
            'password'   => 'nullable|string|min:8',
            'pin'        => 'nullable|string|min:4|max:8',
        ]);

        try {
            $employee = $this->service->storePegawai($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($employee, 'Pegawai berhasil ditambahkan', 201);
    }

    public function updatePegawai(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'profession' => 'sometimes|string|max:100',
            'sip'        => 'nullable|string|max:50',
            'str'        => 'nullable|string|max:50',
            'phone'      => 'nullable|string|max:20',
            'address'    => 'nullable|string|max:500',
            'role_id'    => 'nullable|uuid|exists:roles,id',
            'is_active'  => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updatePegawai($id, $validated), 'Pegawai diperbarui');
    }

    public function deletePegawai(string $id): JsonResponse
    {
        $this->service->deletePegawai($id);

        return $this->ok(null, 'Pegawai dihapus');
    }

    public function resetPasswordPegawai(Request $request, string $id): JsonResponse
    {
        $request->validate(['password' => 'required|string|min:8|confirmed']);

        try {
            $this->service->resetPasswordPegawai($id, $request->password);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Password berhasil direset');
    }

    // =========================================================================
    // PENJAMIN
    // =========================================================================

    public function indexPenjamin(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexPenjamin($request->only(['type', 'parent_id', 'is_system', 'only_tpa_view', 'per_page', 'page', 'search'])));
    }

    public function storePenjamin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'type'      => 'required|in:UMUM,BPJS,ASURANSI,PERUSAHAAN,SOSIAL',
            'parent_id' => 'nullable|uuid|exists:insurers,id',
            'code'      => 'nullable|string|max:50',
            'phone'     => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:255',
            'address'   => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'is_tpa'    => 'nullable|boolean',
            // Kolom TPA — diisi untuk insurer tipe ASURANSI/PERUSAHAAN
            'portal_url'             => 'nullable|url|max:500',
            'pic_name'               => 'nullable|string|max:255',
            'pic_phone'              => 'nullable|string|max:30',
            'pic_email'              => 'nullable|email|max:255',
            'claim_submission_notes' => 'nullable|string',
            'sla_days'               => 'nullable|integer|min:1|max:365',
        ]);

        return $this->ok($this->service->storePenjamin($validated), 'Penjamin dibuat', 201);
    }

    public function updatePenjamin(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'type'      => 'sometimes|in:UMUM,BPJS,ASURANSI,PERUSAHAAN,SOSIAL',
            'parent_id' => 'nullable|uuid|exists:insurers,id',
            'code'      => 'nullable|string|max:50',
            'phone'     => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:255',
            'address'   => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'is_tpa'    => 'nullable|boolean',
            'portal_url'             => 'nullable|url|max:500',
            'pic_name'               => 'nullable|string|max:255',
            'pic_phone'              => 'nullable|string|max:30',
            'pic_email'              => 'nullable|email|max:255',
            'claim_submission_notes' => 'nullable|string',
            'sla_days'               => 'nullable|integer|min:1|max:365',
        ]);

        return $this->ok($this->service->updatePenjamin($id, $validated), 'Penjamin diperbarui');
    }

    public function deletePenjamin(string $id): JsonResponse
    {
        $this->service->deletePenjamin($id);

        return $this->ok(null, 'Penjamin dihapus');
    }

    // ─── Penjamin — TPA membership (kelola anggota dari sisi TPA induk) ───────

    public function candidateMembers(string $tpaId): JsonResponse
    {
        return $this->ok($this->service->candidateTpaMembers($tpaId));
    }

    public function addPenjaminMember(Request $request, string $tpaId): JsonResponse
    {
        // Terima SALAH SATU: insurer_id (kandidat existing) ATAU new_name (buat baru).
        $validated = $request->validate([
            'insurer_id' => 'nullable|uuid|exists:insurers,id',
            'new_name'   => 'nullable|string|max:255',
        ]);
        $newName = trim((string) ($validated['new_name'] ?? ''));
        if (empty($validated['insurer_id']) && $newName === '') {
            return $this->error('Pilih kandidat atau isi nama asuransi baru.', 422);
        }
        try {
            // insurer_id diprioritaskan bila keduanya terisi.
            $member = ! empty($validated['insurer_id'])
                ? $this->service->addTpaMember($tpaId, $validated['insurer_id'])
                : $this->service->addTpaMemberByName($tpaId, $newName);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($member, 'Anggota TPA ditambahkan');
    }

    public function removePenjaminMember(string $tpaId, string $memberId): JsonResponse
    {
        try {
            $member = $this->service->removeTpaMember($tpaId, $memberId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($member, 'Anggota dikeluarkan dari TPA');
    }

    // ─── Penjamin — CSV / Excel template / export / import ───────────────────

    public function templatePenjaminCsv(Request $request): Response
    {
        return $this->csvOrXlsx($request, $this->service->templatePenjaminCsv(), 'template-penjamin', 'Penjamin');
    }

    public function exportPenjaminCsv(Request $request): Response
    {
        return $this->csvOrXlsx($request, $this->service->exportPenjaminCsv(), 'penjamin-' . now()->format('Ymd'), 'Penjamin');
    }

    public function importPenjaminCsv(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120']);
        try {
            $csv    = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
            $result = $this->service->importPenjaminCsv($csv);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($result, "Import selesai: {$result['inserted']} baru, {$result['updated']} diperbarui, {$result['skipped']} dilewati.");
    }

    // =========================================================================
    // TINDAKAN
    // =========================================================================

    public function indexTindakan(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexTindakan($request->only(['search', 'category', 'active', 'per_page'])));
    }

    public function storeTindakan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'code'        => 'nullable|string|max:50',  // opsional — backend auto-generate dari kategori kalau kosong
            'category'    => 'required|string|max:100',
            'base_price'  => 'required|numeric|min:0',
            'icd9_code'   => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'keterangan'  => 'nullable|string|max:500',
            'is_active'   => 'nullable|boolean',
        ]);

        return $this->ok($this->service->storeTindakan($validated), 'Tindakan dibuat', 201);
    }

    public function updateTindakan(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'category'    => 'sometimes|string|max:100',
            'base_price'  => 'sometimes|numeric|min:0',
            'icd9_code'   => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'keterangan'  => 'nullable|string|max:500',
            'is_active'   => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updateTindakan($id, $validated), 'Tindakan diperbarui');
    }

    public function kategoriListTindakan(): JsonResponse
    {
        return $this->ok($this->service->kategoriListTindakan());
    }

    public function deleteTindakan(string $id): JsonResponse
    {
        $this->service->deleteTindakan($id);

        return $this->ok(null, 'Tindakan dihapus');
    }

    // =========================================================================
    // PROCEDURE CATEGORIES — master kategori tindakan (CRUD)
    // =========================================================================

    public function indexProcedureCategories(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexProcedureCategories($request->only(['active'])));
    }

    public function storeProcedureCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100|unique:procedure_categories,name',
            'code_prefix' => 'required|string|max:10|unique:procedure_categories,code_prefix',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'nullable|boolean',
        ]);
        return $this->ok($this->service->storeProcedureCategory($validated), 'Kategori dibuat', 201);
    }

    public function updateProcedureCategory(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'        => "sometimes|string|max:100|unique:procedure_categories,name,{$id}",
            'code_prefix' => "sometimes|string|max:10|unique:procedure_categories,code_prefix,{$id}",
            'description' => 'nullable|string|max:255',
            'is_active'   => 'nullable|boolean',
        ]);
        return $this->ok($this->service->updateProcedureCategory($id, $validated), 'Kategori diperbarui');
    }

    public function deleteProcedureCategory(string $id): JsonResponse
    {
        $this->service->deleteProcedureCategory($id);
        return $this->ok(null, 'Kategori dihapus');
    }

    // ─── Kategori Tindakan — CSV / Excel template / export / import ──────────

    public function templateKategoriCsv(Request $request): Response
    {
        return $this->csvOrXlsx($request, $this->service->templateKategoriCsv(), 'template-kategori-tindakan', 'Kategori');
    }

    public function exportKategoriCsv(Request $request): Response
    {
        return $this->csvOrXlsx($request, $this->service->exportKategoriCsv(), 'kategori-tindakan-' . now()->format('Ymd'), 'Kategori');
    }

    public function importKategoriCsv(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120']);
        try {
            $csv    = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
            $result = $this->service->importKategoriCsv($csv);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($result, "Import selesai: {$result['inserted']} baru, {$result['updated']} diperbarui, {$result['skipped']} dilewati.");
    }

    /** Kirim CSV string sbg file CSV (default) atau XLSX bila ?format=xlsx. */
    private function csvOrXlsx(Request $request, string $csv, string $baseName, string $sheetTitle): Response
    {
        if (strtolower((string) $request->query('format')) === 'xlsx') {
            $xlsx = \App\Support\SpreadsheetHelper::csvToXlsx($csv, $sheetTitle);
            return response($xlsx, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$baseName}.xlsx\"",
            ]);
        }
        // BOM UTF-8 (\xEF\xBB\xBF) WAJIB di depan: Excel (locale ID) tak menghormati
        // header charset, tanpa BOM ia membaca file sebagai ANSI/CP1252 → karakter
        // > 0x7F jadi mojibake ("tidak terbaca") & gagal auto-deteksi delimiter.
        // Sisi import sudah strip BOM (SpreadsheetHelper::normalizeCsvString) → roundtrip aman.
        return response("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$baseName}.csv\"",
        ]);
    }

    // =========================================================================
    // ICD-10 / ICD-9
    // =========================================================================

    public function indexIcd10(Request $request): JsonResponse
    {
        // Picker dokter (with_sub=1): daftar sub-diagnosa + kanonik tanpa-sub {code,name,is_sub}.
        if ($request->boolean('with_sub')) {
            return $this->ok($this->service->searchDiagnosesWithSub('icd10',
                $request->only(['search', 'eye_related', 'per_page'])));
        }
        return $this->ok($this->service->indexIcd10(
            $request->only(['search', 'category', 'eye_related', 'favorite', 'per_page'])
        ));
    }

    public function storeIcd10(Request $request): JsonResponse
    {
        // Admin BOLEH menambahkan code baru. Code mengikuti standar WHO,
        // sehingga setelah dibuat tidak boleh diubah (lihat updateIcd10).
        $validated = $request->validate([
            // unique HANYA atas baris aktif (whereNull deleted_at). Kode yang pernah
            // di-soft-delete boleh ditambah ulang → service akan restore (lihat upsertIcdRow).
            'code'                   => ['required', 'string', 'max:10', Rule::unique('icd10_codes', 'code')->whereNull('deleted_at')],
            'chapter'                => 'nullable|string|max:10',
            'chapter_label'          => 'nullable|string|max:255',
            'category'               => 'nullable|string|max:10',
            'description'            => 'required|string|max:500',
            'indonesian_description' => 'nullable|string|max:500',
            'is_eye_related'         => 'nullable|boolean',
            'is_favorite'            => 'nullable|boolean',
        ]);

        return $this->ok($this->service->storeIcd10($validated), 'ICD-10 dibuat', 201);
    }

    public function updateIcd10(Request $request, string $id): JsonResponse
    {
        // Catatan: kolom `code` SENGAJA tidak ada di validasi update.
        // Code ICD adalah standar WHO yang fix — kalau salah ketik, hapus lalu buat baru
        // supaya FK di RME/billing yang sudah memakai tidak rusak.
        $validated = $request->validate([
            'chapter'                => 'nullable|string|max:10',
            'chapter_label'          => 'nullable|string|max:255',
            'category'               => 'nullable|string|max:10',
            'description'            => 'sometimes|string|max:500',
            'indonesian_description' => 'nullable|string|max:500',
            'is_eye_related'         => 'nullable|boolean',
            'is_favorite'            => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updateIcd10($id, $validated), 'ICD-10 diperbarui');
    }

    public function deleteIcd10(string $id): JsonResponse
    {
        $this->service->deleteIcd10($id);

        return $this->ok(null, 'ICD-10 dihapus');
    }

    public function indexIcd9(Request $request): JsonResponse
    {
        if ($request->boolean('with_sub')) {
            return $this->ok($this->service->searchDiagnosesWithSub('icd9',
                $request->only(['search', 'eye_related', 'per_page'])));
        }
        return $this->ok($this->service->indexIcd9(
            $request->only(['search', 'category', 'eye_related', 'favorite', 'per_page'])
        ));
    }

    public function storeIcd9(Request $request): JsonResponse
    {
        // Admin BOLEH menambahkan code baru. Code mengikuti standar WHO ICD-9-CM,
        // sehingga setelah dibuat tidak boleh diubah (lihat updateIcd9).
        $validated = $request->validate([
            // unique HANYA atas baris aktif — kode soft-deleted boleh ditambah ulang (restore).
            'code'                   => ['required', 'string', 'max:10', Rule::unique('icd9_codes', 'code')->whereNull('deleted_at')],
            'category'               => 'nullable|string|max:10',
            'description'            => 'required|string|max:500',
            'indonesian_description' => 'nullable|string|max:500',
            'is_eye_related'         => 'nullable|boolean',
            'is_favorite'            => 'nullable|boolean',
        ]);

        return $this->ok($this->service->storeIcd9($validated), 'ICD-9 dibuat', 201);
    }

    public function updateIcd9(Request $request, string $id): JsonResponse
    {
        // Catatan: kolom `code` SENGAJA tidak ada di validasi update.
        // Code ICD adalah standar WHO yang fix — kalau salah ketik, hapus lalu buat baru
        // supaya FK di RME/billing yang sudah memakai tidak rusak.
        $validated = $request->validate([
            'category'               => 'nullable|string|max:10',
            'description'            => 'sometimes|string|max:500',
            'indonesian_description' => 'nullable|string|max:500',
            'is_eye_related'         => 'nullable|boolean',
            'is_favorite'            => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updateIcd9($id, $validated), 'ICD-9 diperbarui');
    }

    public function deleteIcd9(string $id): JsonResponse
    {
        $this->service->deleteIcd9($id);

        return $this->ok(null, 'ICD-9 dihapus');
    }

    // =========================================================================
    // JENIS PENUNJANG (diagnostic_test_types)
    // =========================================================================

    public function indexDiagnosticTestType(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexDiagnosticTestType(
            $request->only(['search', 'category', 'active', 'per_page'])
        ));
    }

    public function storeDiagnosticTestType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Kode opsional — backend auto-generate dari kategori Penunjang (prefix PNJ).
            // Master sebenarnya = procedure; cermin diagnostic_test_types ikut kode sama.
            'code'       => 'nullable|string|max:30|unique:diagnostic_test_types,code|unique:procedures,code',
            'name'       => 'required|string|max:150',
            'modality'   => 'nullable|in:OPT,US,OT',
            'is_active'  => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        return $this->ok($this->service->storeDiagnosticTestType($validated), 'Jenis penunjang dibuat', 201);
    }

    public function updateDiagnosticTestType(Request $request, string $id): JsonResponse
    {
        // `code` IMMUTABLE — kunci penghubung ke procedure pasangan & order lama.
        // Harga TIDAK diatur di sini — semua tarif berasal dari Buku Tarif (Tarif Tindakan).
        $validated = $request->validate([
            'name'       => 'sometimes|string|max:150',
            'modality'   => 'nullable|in:OPT,US,OT',
            'is_active'  => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        return $this->ok($this->service->updateDiagnosticTestType($id, $validated), 'Jenis penunjang diperbarui');
    }

    public function deleteDiagnosticTestType(string $id): JsonResponse
    {
        $this->service->deleteDiagnosticTestType($id);

        return $this->ok(null, 'Jenis penunjang dihapus');
    }

    // =========================================================================
    // OBAT / BHP / IOL
    // =========================================================================

    public function indexObat(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexObat(
            $request->only(['search', 'formularium', 'form_sediaan', 'golongan', 'active', 'low_stock', 'per_page'])
        ));
    }

    public function storeObat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'         => 'nullable|string|max:50|unique:medications,code',
            'kfa_code'     => 'nullable|string|max:32',
            'name'         => 'required|string|max:255',
            'generic_name' => 'nullable|string|max:255',
            'composition'  => 'nullable|string|max:500',
            'manufacturer' => 'nullable|string|max:255',
            'formularium'  => 'required|in:FORNAS,NON-FORNAS,FORMULARIUM GENERIK,BRANDED',
            'form_sediaan' => 'nullable|in:TABLET,KAPSUL,SIRUP,TETES_MATA,SALEP_MATA,INJEKSI,LAIN',
            'golongan'     => 'nullable|in:BEBAS,BEBAS_TERBATAS,KERAS,NARKOTIKA,PSIKOTROPIKA',
            'unit'         => 'nullable|string|max:50',
            'unit_besar'   => 'nullable|string|max:50',
            'unit_kecil'   => 'nullable|string|max:50',
            'konversi'     => 'nullable|integer|min:1',
            'stock'        => 'nullable|integer|min:0',
            'min_stock'    => 'nullable|integer|min:0',
            'price'        => 'nullable|numeric|min:0',
            'expiry_date'  => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:1000',
            'is_active'    => 'nullable|boolean',
        ]);

        return $this->ok($this->service->storeObat($validated), 'Obat dibuat', 201);
    }

    public function updateObat(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'kfa_code'     => 'sometimes|nullable|string|max:32',
            'name'         => 'sometimes|string|max:255',
            'generic_name' => 'nullable|string|max:255',
            'composition'  => 'nullable|string|max:500',
            'manufacturer' => 'nullable|string|max:255',
            'formularium'  => 'sometimes|in:FORNAS,NON-FORNAS,FORMULARIUM GENERIK,BRANDED',
            'form_sediaan' => 'nullable|in:TABLET,KAPSUL,SIRUP,TETES_MATA,SALEP_MATA,INJEKSI,LAIN',
            'golongan'     => 'nullable|in:BEBAS,BEBAS_TERBATAS,KERAS,NARKOTIKA,PSIKOTROPIKA',
            'unit'         => 'sometimes|nullable|string|max:50',
            'unit_besar'   => 'sometimes|nullable|string|max:50',
            'unit_kecil'   => 'sometimes|nullable|string|max:50',
            'konversi'     => 'sometimes|nullable|integer|min:1',
            'stock'        => 'sometimes|integer|min:0',
            'min_stock'    => 'sometimes|integer|min:0',
            'price'        => 'sometimes|numeric|min:0',
            'expiry_date'  => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:1000',
            'is_active'    => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updateObat($id, $validated), 'Obat diperbarui');
    }

    public function deleteObat(string $id): JsonResponse
    {
        $this->service->deleteObat($id);
        return $this->ok(null, 'Obat dihapus');
    }

    public function indexBhp(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexBhp(
            $request->only(['search', 'category', 'active', 'low_stock', 'per_page'])
        ));
    }

    public function storeBhp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'         => 'nullable|string|max:50|unique:bhp_items,code',
            'name'         => 'required|string|max:255',
            'category'     => 'nullable|in:MEDICAL_BHP,CSSD,INSTRUMENT_SET',
            'unit'         => 'nullable|string|max:50',
            'manufacturer' => 'nullable|string|max:255',
            'stock'        => 'nullable|integer|min:0',
            'min_stock'    => 'nullable|integer|min:0',
            'price'        => 'nullable|numeric|min:0',
            'expiry_date'  => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:500',
            'is_active'    => 'nullable|boolean',
        ]);

        return $this->ok($this->service->storeBhp($validated), 'BHP dibuat', 201);
    }

    public function updateBhp(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'category'     => 'nullable|in:MEDICAL_BHP,CSSD,INSTRUMENT_SET',
            'unit'         => 'sometimes|string|max:50',
            'manufacturer' => 'nullable|string|max:255',
            'stock'        => 'sometimes|integer|min:0',
            'min_stock'    => 'sometimes|integer|min:0',
            'price'        => 'sometimes|numeric|min:0',
            'expiry_date'  => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:500',
            'is_active'    => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updateBhp($id, $validated), 'BHP diperbarui');
    }

    public function deleteBhp(string $id): JsonResponse
    {
        $this->service->deleteBhp($id);
        return $this->ok(null, 'BHP dihapus');
    }

    public function indexIol(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexIol(
            $request->only(['search', 'iol_type', 'material', 'active', 'is_used', 'available_only', 'per_page'])
        ));
    }

    /**
     * POST /master/iol/scan
     * Body: { code: string } — string mentah hasil scan DataMatrix/UDI atau ketik manual.
     *
     * Parse GS1 → cari IolItem by gtin (fallback gs1_barcode / serial). Kembalikan
     * apakah cocok dengan master + hasil parse (gtin/lot/serial/expiry) untuk
     * auto-fill form penerimaan ATAU prefill master baru bila belum ada.
     */
    public function scanIol(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => 'required|string|max:512']);

        $parsed = \App\Support\Gs1Parser::parse($validated['code']);

        // Pilih kandidat TERBAIK bila >1 baris cocok: prioritaskan AKTIF & stok terbanyak.
        $pickBest = function ($query) {
            return (clone $query)->withOnHand()
                ->orderByDesc('iol_items.is_active')
                ->orderByDesc('on_hand')
                ->first();
        };

        $iol = null;
        $ambiguous = false;   // >1 POWER berbeda utk GTIN sama → JANGAN auto-pilih (bahaya klinis)
        if (! empty($parsed['gtin'])) {
            $base = \App\Models\IolItem::where('iol_items.gtin', $parsed['gtin'])->where('iol_items.is_active', true);
            // Bila GTIN cocok ke >1 POWER berbeda (data tak ideal), JANGAN tebak —
            // power salah = lensa salah tanam. Biarkan operator pilih manual.
            $distinctPowers = (clone $base)->distinct()->count('iol_items.power');
            if ($distinctPowers > 1) {
                $ambiguous = true;
            } else {
                $iol = $pickBest($base);
            }
        }
        // Fallback: gs1_barcode mengandung GTIN, atau serial cocok (data lama).
        if (! $iol && ! $ambiguous && ! empty($parsed['gtin'])) {
            $iol = $pickBest(\App\Models\IolItem::where('iol_items.gs1_barcode', 'ilike', '%' . $parsed['gtin'] . '%'));
        }
        if (! $iol && ! $ambiguous && ! empty($parsed['serial_number'])) {
            $iol = $pickBest(\App\Models\IolItem::where('iol_items.serial_number', $parsed['serial_number']));
        }

        $message = $ambiguous
            ? 'GTIN cocok ke beberapa power berbeda — pilih lensa yang benar secara manual.'
            : ($iol ? 'IOL ditemukan' : 'IOL belum terdaftar — lengkapi data master');

        return $this->ok([
            'matched'   => $iol !== null,
            'ambiguous' => $ambiguous,
            'iol_item'  => $iol,
            'on_hand'   => $iol ? $iol->onHandStock() : 0,
            'parsed'    => $parsed,
        ], $message);
    }

    public function storeIol(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand'         => 'required|string|max:100',
            'manufacturer'  => 'nullable|string|max:255',
            'model'         => 'required|string|max:100',
            'iol_type'      => 'required|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'material'      => 'nullable|in:Acrylic,Silicone,PMMA',
            'power'         => 'required|numeric|between:-20,40',
            'a_constant'    => 'nullable|numeric|between:90,130',
            'cylinder'      => 'nullable|required_if:iol_type,TORIC|numeric|between:0,10',
            'axis'          => 'nullable|required_if:iol_type,TORIC|integer|between:0,180',
            // Per-tipe: serial/lot adalah data operasi-time, BUKAN identitas master →
            // tidak lagi unique. Identitas = (brand,model,power).
            'lot_number'    => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'gtin'          => 'nullable|string|max:14',
            'gs1_barcode'   => 'nullable|string|max:255',
            'expiry_date'   => 'nullable|date',
            'stock'         => 'nullable|integer|min:0',
            'price'         => 'nullable|numeric|min:0',
            'is_active'     => 'nullable|boolean',
        ]);

        return $this->ok($this->service->storeIol($validated), 'IOL dibuat', 201);
    }

    public function updateIol(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'brand'         => 'sometimes|string|max:100',
            'manufacturer'  => 'nullable|string|max:255',
            'model'         => 'sometimes|string|max:100',
            'iol_type'      => 'sometimes|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'material'      => 'nullable|in:Acrylic,Silicone,PMMA',
            'power'         => 'sometimes|numeric|between:-20,40',
            'a_constant'    => 'nullable|numeric|between:90,130',
            'cylinder'      => 'nullable|required_if:iol_type,TORIC|numeric|between:0,10',
            'axis'          => 'nullable|required_if:iol_type,TORIC|integer|between:0,180',
            'lot_number'    => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'gtin'          => 'nullable|string|max:14',
            'gs1_barcode'   => 'nullable|string|max:255',
            'expiry_date'   => 'nullable|date',
            'stock'         => 'sometimes|integer|min:0',
            'is_used'       => 'sometimes|boolean',
            'price'         => 'sometimes|numeric|min:0',
            'is_active'     => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updateIol($id, $validated), 'IOL diperbarui');
    }

    public function deleteIol(string $id): JsonResponse
    {
        $this->service->deleteIol($id);
        return $this->ok(null, 'IOL dihapus');
    }

    // =========================================================================
    // PAKET BEDAH
    // =========================================================================

    public function indexPaketBedah(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'active', 'per_page', 'package_type']);
        // Opsional: visit_id → resolve penjamin pasien agar nama+harga paket tampil per-penjamin.
        if ($vid = $request->query('visit_id')) {
            $visit = \App\Models\Visit::find($vid);
            if ($visit) {
                $filters['guarantor_type'] = $visit->guarantor_type;
                $filters['insurer_id']     = $visit->insurer_id;
            }
        }
        return $this->ok($this->service->indexPaketBedah($filters));
    }

    public function storePaketBedah(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'               => 'required|string|max:255',
            'code'               => 'required|string|max:50|unique:surgery_packages,code',
            'package_type'       => 'nullable|in:BEDAH,PEMERIKSAAN',
            'surgery_type'       => 'nullable|in:KATARAK,VITREORETINA,GLAUKOMA,LAINNYA',
            'description'        => 'nullable|string|max:1000',
            'estimated_duration' => 'nullable|integer|min:1',
            'price'              => 'nullable|numeric|min:0',
            'is_active'          => 'nullable|boolean',
        ]);

        if (empty($validated['surgery_type'])) {
            $validated['surgery_type'] = \App\Models\SurgeryPackage::suggestSurgeryType($validated['name'] ?? null);
        }

        return $this->ok($this->service->storePaketBedah($validated), 'Paket bedah dibuat', 201);
    }

    public function updatePaketBedah(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'package_type'       => 'nullable|in:BEDAH,PEMERIKSAAN',
            'surgery_type'       => 'nullable|in:KATARAK,VITREORETINA,GLAUKOMA,LAINNYA',
            'description'        => 'nullable|string|max:1000',
            'estimated_duration' => 'nullable|integer|min:1',
            'price'              => 'nullable|numeric|min:0',
            'is_active'          => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updatePaketBedah($id, $validated), 'Paket bedah diperbarui');
    }

    public function deletePaketBedah(string $id): JsonResponse
    {
        $this->service->deletePaketBedah($id);
        return $this->ok(null, 'Paket bedah dihapus');
    }

    // =========================================================================
    // TARIF — CRUD + CSV
    // =========================================================================

    private function tarifRules(string $type): array
    {
        $fk = match ($type) {
            'tindakan' => 'procedure_id',
            'obat'     => 'medication_id',
            'bhp'      => 'bhp_item_id',
            'iol'      => 'iol_item_id',
        };

        $table = match ($type) {
            'tindakan' => 'procedures',
            'obat'     => 'medications',
            'bhp'      => 'bhp_items',
            'iol'      => 'iol_items',
        };

        return [
            $fk             => "required|uuid|exists:{$table},id",
            'insurer_id'    => 'nullable|uuid|exists:insurers,id',
            'classification' => 'required|in:UMUM,BPJS,ASURANSI,PERUSAHAAN,SOSIAL',
            'price'         => 'required|numeric|min:0',
            'is_active'     => 'nullable|boolean',
        ];
    }

    public function indexTarifTindakan(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexTarif('tindakan', $request->only(['classification', 'insurer_id', 'per_page'])));
    }

    public function storeTarifTindakan(Request $request): JsonResponse
    {
        return $this->ok($this->service->storeTarif('tindakan', $request->validate($this->tarifRules('tindakan'))), 'Tarif tindakan disimpan', 201);
    }

    public function updateTarifTindakan(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['price' => 'required|numeric|min:0', 'is_active' => 'nullable|boolean']);
        return $this->ok($this->service->updateTarif('tindakan', $id, $validated), 'Tarif diperbarui');
    }

    public function deleteTarifTindakan(string $id): JsonResponse
    {
        $this->service->deleteTarif('tindakan', $id);
        return $this->ok(null, 'Tarif dihapus');
    }

    /**
     * Buku Tarif terpadu: Tindakan+Obat+BHP+IOL satu daftar berkategori.
     * Param opsional insurer_id → mode HARGA EFEKTIF penjamin (penjamin→UMUM→0,
     * + kolom sumber PENJAMIN/UMUM/NONE) — dipakai detail Metode Bayar.
     */
    public function indexBukuTarif(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'kategori', 'tipe', 'aktif', 'per_page', 'insurer_id', 'harga_nol']);
        return $this->ok([
            'tarif'            => $this->service->indexBukuTarif($filters),
            'kategori_options' => $this->service->bukuTarifKategoriOptions(),
        ]);
    }

    /** Set harga jual UMUM satu item Buku Tarif (inline edit dari daftar terpadu). */
    public function setBukuTarifPrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipe'         => 'required|in:tindakan,obat,bhp,iol',
            'item_id'      => 'required|string',
            'price'        => 'required|numeric|min:0',
            'is_active'    => 'nullable|boolean',
            // pos kwitansi hanya relevan untuk obat (Obat Tindakan/Pulang/Injeksi).
            'pos_kwitansi' => 'nullable|in:' . implode(',', MedicationTariff::POS_VALUES),
        ]);
        $tariff = $this->service->setBukuTarifPrice(
            $data['tipe'], $data['item_id'], (float) $data['price'], $data['is_active'] ?? true, $data['pos_kwitansi'] ?? null
        );
        return $this->ok($tariff, 'Harga diperbarui');
    }

    /** GET /master/buku-tarif/template-csv  (?format=xlsx) */
    public function templateBukuTarifCsv(Request $request): Response
    {
        return $this->csvOrXlsx($request, $this->service->templateBukuTarifCsv(), 'template-buku-tarif', 'Buku Tarif');
    }

    /** GET /master/buku-tarif/export-csv  (?format=xlsx) — semua tipe (Tindakan+Obat+BHP+IOL). */
    public function exportBukuTarifCsv(Request $request): Response
    {
        $filters = $request->only(['search', 'kategori', 'tipe', 'harga_nol']);
        $csv     = $this->service->exportBukuTarifCsv($filters);
        return $this->csvOrXlsx($request, $csv, 'buku-tarif-' . now()->format('Ymd'), 'Buku Tarif');
    }

    /** POST /master/buku-tarif/import-csv — 1 file multi-tipe, set harga UMUM per item. */
    public function importBukuTarifCsv(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120']);

        try {
            $csvContent = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
            $result     = $this->service->importBukuTarifCsv($csvContent);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(
            $result,
            "Import selesai: {$result['inserted']} baru, {$result['updated']} diperbarui, {$result['skipped']} dilewati."
        );
    }

    public function indexTarifObat(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexTarif('obat', $request->only(['classification', 'insurer_id', 'per_page'])));
    }

    public function storeTarifObat(Request $request): JsonResponse
    {
        return $this->ok($this->service->storeTarif('obat', $request->validate($this->tarifRules('obat'))), 'Tarif obat disimpan', 201);
    }

    public function updateTarifObat(Request $request, string $id): JsonResponse
    {
        return $this->ok($this->service->updateTarif('obat', $id, $request->validate(['price' => 'required|numeric|min:0'])), 'Tarif diperbarui');
    }

    public function deleteTarifObat(string $id): JsonResponse
    {
        $this->service->deleteTarif('obat', $id);
        return $this->ok(null, 'Tarif dihapus');
    }

    // ─── Kemasan jual obat (varian per Strip/Box, harga independen) ──────────

    public function indexKemasanObat(string $medicationId): JsonResponse
    {
        return $this->ok($this->service->indexKemasanObat($medicationId));
    }

    public function storeKemasanObat(Request $request, string $medicationId): JsonResponse
    {
        $data = $request->validate([
            'label'      => 'required|string|max:50',
            'isi'        => 'required|integer|min:1',
            'price'      => 'required|numeric|min:0',
            'insurer_id' => 'nullable|uuid|exists:insurers,id',
            'is_active'  => 'nullable|boolean',
        ]);
        return $this->ok($this->service->storeKemasanObat($medicationId, $data), 'Kemasan jual disimpan', 201);
    }

    public function updateKemasanObat(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'label'     => 'sometimes|string|max:50',
            'isi'       => 'sometimes|integer|min:1',
            'price'     => 'sometimes|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);
        return $this->ok($this->service->updateKemasanObat($id, $data), 'Kemasan jual diperbarui');
    }

    public function deleteKemasanObat(string $id): JsonResponse
    {
        $this->service->deleteKemasanObat($id);
        return $this->ok(null, 'Kemasan jual dihapus');
    }

    public function indexTarifBhp(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexTarif('bhp', $request->only(['classification', 'insurer_id', 'per_page'])));
    }

    public function storeTarifBhp(Request $request): JsonResponse
    {
        return $this->ok($this->service->storeTarif('bhp', $request->validate($this->tarifRules('bhp'))), 'Tarif BHP disimpan', 201);
    }

    public function updateTarifBhp(Request $request, string $id): JsonResponse
    {
        return $this->ok($this->service->updateTarif('bhp', $id, $request->validate(['price' => 'required|numeric|min:0'])), 'Tarif diperbarui');
    }

    public function deleteTarifBhp(string $id): JsonResponse
    {
        $this->service->deleteTarif('bhp', $id);
        return $this->ok(null, 'Tarif dihapus');
    }

    public function indexTarifIol(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexTarif('iol', $request->only(['classification', 'insurer_id', 'per_page'])));
    }

    public function storeTarifIol(Request $request): JsonResponse
    {
        return $this->ok($this->service->storeTarif('iol', $request->validate($this->tarifRules('iol'))), 'Tarif IOL disimpan', 201);
    }

    public function updateTarifIol(Request $request, string $id): JsonResponse
    {
        return $this->ok($this->service->updateTarif('iol', $id, $request->validate(['price' => 'required|numeric|min:0'])), 'Tarif diperbarui');
    }

    public function deleteTarifIol(string $id): JsonResponse
    {
        $this->service->deleteTarif('iol', $id);
        return $this->ok(null, 'Tarif dihapus');
    }

    /**
     * GET /master/tarif/{type}/export-csv
     * type: tindakan | obat | bhp | iol
     */
    public function exportTarifCsv(string $type): Response
    {
        try {
            $csv = $this->service->exportTarifCsv($type);
        } catch (\Exception $e) {
            return response($e->getMessage(), 422);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"tarif-{$type}-" . now()->format('Ymd') . ".csv\"",
        ]);
    }

    /**
     * POST /master/tarif/{type}/import-csv
     * Body: multipart/form-data, field: file (CSV)
     */
    public function importTarifCsv(Request $request, string $type): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120']);

        try {
            $csvContent = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
            $result     = $this->service->importTarifCsv($type, $csvContent);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, "Import selesai: {$result['imported']} berhasil, {$result['skipped']} dilewati.");
    }

    // =========================================================================
    // RESOURCE CSV — generic untuk obat / bhp / iol / icd10 / icd9
    // =========================================================================

    private const CSV_TYPES = ['tindakan', 'obat', 'bhp', 'iol', 'icd10', 'icd9', 'alat-medis', 'supplier'];

    private function assertCsvType(string $type): void
    {
        if (! in_array($type, self::CSV_TYPES, true)) {
            abort(404);
        }
    }

    /**
     * GET /master/{type}/template-csv  (?format=xlsx untuk Excel)
     */
    public function templateCsv(Request $request, string $type): Response
    {
        $this->assertCsvType($type);

        try {
            $csv = $this->service->csvTemplate($type);
        } catch (\Exception $e) {
            return response($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->csvOrXlsx($request, $csv, "template-{$type}", $this->csvSheetTitle($type));
    }

    /**
     * GET /master/{type}/export-csv  (?format=xlsx untuk Excel)
     */
    public function exportCsv(Request $request, string $type): Response
    {
        $this->assertCsvType($type);

        try {
            $csv = $this->service->exportResourceCsv($type);
        } catch (\Exception $e) {
            return response($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->csvOrXlsx($request, $csv, "{$type}-" . now()->format('Ymd'), $this->csvSheetTitle($type));
    }

    /** Judul sheet Excel yang manusiawi per tipe resource (fallback: uppercase). */
    private function csvSheetTitle(string $type): string
    {
        return [
            'icd10'      => 'ICD-10',
            'icd9'       => 'ICD-9',
            'obat'       => 'Obat',
            'bhp'        => 'BHP',
            'iol'        => 'IOL',
            'alat-medis' => 'Alat Medis',
            'tindakan'   => 'Tarif Tindakan',
            'supplier'   => 'Supplier',
        ][$type] ?? strtoupper($type);
    }

    /**
     * POST /master/{type}/import-csv
     * Body: multipart/form-data, field: file (CSV)
     */
    public function importCsv(Request $request, string $type): JsonResponse
    {
        $this->assertCsvType($type);

        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120']);

        try {
            // Terima CSV/TXT & Excel; helper normalisasi BOM + delimiter ';' → koma.
            $csvContent = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
            $result     = $this->service->importResourceCsv($type, $csvContent);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(
            $result,
            "Import selesai: {$result['inserted']} baru, {$result['updated']} diperbarui, {$result['skipped']} dilewati."
        );
    }

    // =========================================================================
    // JENIS DOKUMEN
    // =========================================================================

    public function indexJenisDokumen(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexJenisDokumen($request->only(['category', 'show_in_rme'])));
    }

    public function storeJenisDokumen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'                  => 'required|string|max:20|unique:document_types,code',
            'name'                  => 'required|string|max:255',
            'fill_frequency'        => 'required|in:ONCE_LIFETIME,PER_VISIT,PER_EPISODE',
            'generate_type'         => 'required|in:AUTO,MANUAL,HYBRID',
            'category'              => 'required|in:ADMINISTRASI,KLINIS,PENUNJANG,BEDAH,FARMASI,BILLING',
            'parent_id'             => 'nullable|uuid|exists:document_types,id',
            'required_signatures'   => 'nullable|array',
            'required_signatures.*.role'        => 'required|string',
            'required_signatures.*.sign_type'   => 'required|in:PIN,DRAW',
            'required_signatures.*.is_required' => 'nullable|boolean',
            'show_in_rme'           => 'nullable|boolean',
            'sort_order'            => 'nullable|integer|min:0',
            'is_active'             => 'nullable|boolean',
        ]);

        try {
            $docType = $this->service->storeJenisDokumen($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($docType, 'Jenis dokumen dibuat', 201);
    }

    public function updateJenisDokumen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'sometimes|string|max:255',
            'fill_frequency'        => 'sometimes|in:ONCE_LIFETIME,PER_VISIT,PER_EPISODE',
            'category'              => 'sometimes|in:ADMINISTRASI,KLINIS,PENUNJANG,BEDAH,FARMASI,BILLING',
            'required_signatures'   => 'nullable|array',
            'show_in_rme'           => 'nullable|boolean',
            'sort_order'            => 'nullable|integer|min:0',
            'is_active'             => 'nullable|boolean',
        ]);

        try {
            $docType = $this->service->updateJenisDokumen($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($docType, 'Jenis dokumen diperbarui');
    }

    public function deleteJenisDokumen(string $id): JsonResponse
    {
        try {
            $this->service->deleteJenisDokumen($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Jenis dokumen dihapus');
    }

    // =========================================================================
    // TEMPLATE DOKUMEN
    // =========================================================================

    public function indexTemplateDokumen(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexTemplateDokumen($request->only(['document_type_id', 'per_page'])));
    }

    public function showTemplateDokumen(string $id): JsonResponse
    {
        return $this->ok($this->service->showTemplateDokumen($id));
    }

    public function storeTemplateDokumen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type_id' => 'required|uuid|exists:document_types,id',
            'name'             => 'required|string|max:255',
            'header_html'      => 'nullable|string',
            'body_html'        => 'required|string',
            'footer_html'      => 'nullable|string',
            'page_size'        => 'nullable|in:A4,A5,Letter',
            'orientation'      => 'nullable|in:portrait,landscape',
            'is_active'        => 'nullable|boolean',
        ]);

        return $this->ok($this->service->storeTemplateDokumen($validated), 'Template dibuat', 201);
    }

    public function updateTemplateDokumen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'header_html'  => 'nullable|string',
            'body_html'    => 'sometimes|string',
            'footer_html'  => 'nullable|string',
            'page_size'    => 'nullable|in:A4,A5,Letter',
            'orientation'  => 'nullable|in:portrait,landscape',
            'is_active'    => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updateTemplateDokumen($id, $validated), 'Template diperbarui');
    }

    public function deleteTemplateDokumen(string $id): JsonResponse
    {
        $this->service->deleteTemplateDokumen($id);
        return $this->ok(null, 'Template dihapus');
    }

    // =========================================================================
    // STATION DOCUMENT MAPPINGS
    // =========================================================================

    public function indexStasiunDokumen(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexStasiunDokumen($request->only(['station'])));
    }

    public function updateStasiunDokumen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'is_available' => 'nullable|boolean',
            'can_create'   => 'nullable|boolean',
            'can_submit'   => 'nullable|boolean',
            'can_print'    => 'nullable|boolean',
        ]);

        return $this->ok($this->service->updateStasiunDokumen($id, $validated), 'Mapping stasiun diperbarui');
    }

    // =========================================================================
    // DOCUMENT NUMBER CONFIGS
    // =========================================================================

    public function indexNomorDokumen(): JsonResponse
    {
        return $this->ok($this->service->indexNomorDokumen());
    }

    public function storeNomorDokumen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Unik hanya terhadap baris aktif (soft-delete diabaikan) agar kode
            // yang pernah dihapus bisa dipakai ulang.
            'document_type_code' => [
                'required', 'string', 'max:20',
                \Illuminate\Validation\Rule::unique('document_number_configs', 'document_type_code')->whereNull('deleted_at'),
            ],
            'format'             => 'required|string|max:255',
            'prefix'             => 'nullable|string|max:50',
            'reset_period'       => 'required|in:DAILY,MONTHLY,YEARLY,NEVER',
            'seq_length'         => 'required|integer|min:3|max:10',
        ]);

        return $this->ok($this->service->storeNomorDokumen($validated), 'Konfigurasi nomor dokumen dibuat', 201);
    }

    public function updateNomorDokumen(Request $request, string $id): JsonResponse
    {
        // format, reset_period & seq_length WAJIB: inti generator nomor — jangan
        // biarkan ter-null-kan diam-diam saat update (pola "field hilang").
        $validated = $request->validate([
            'format'       => 'required|string|max:255',
            'prefix'       => 'nullable|string|max:50',
            'reset_period' => 'required|in:DAILY,MONTHLY,YEARLY,NEVER',
            'seq_length'   => 'required|integer|min:3|max:10',
        ]);

        return $this->ok($this->service->updateNomorDokumen($id, $validated), 'Konfigurasi nomor dokumen diperbarui');
    }

    public function destroyNomorDokumen(string $id): JsonResponse
    {
        $this->service->destroyNomorDokumen($id);

        return $this->ok(null, 'Konfigurasi nomor dokumen dihapus');
    }

    // =========================================================================
    // FORM REGISTRY — Master Form Template (Fase 1)
    // =========================================================================

    /**
     * GET /master/form-template
     * Query: is_active (bool), kind, complexity_kind, search (by name/code).
     */
    public function indexFormTemplate(Request $request): JsonResponse
    {
        $query = DocumentTemplate::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->input('kind'));
        }
        if ($request->filled('complexity_kind')) {
            $query->where('complexity_kind', $request->input('complexity_kind'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Hanya yang punya `code` (form registry templates) — exclude template lama
        // yang belum di-migrate ke Form Registry.
        $query->whereNotNull('code');

        return $this->ok($query->orderBy('name')->get());
    }

    public function showFormTemplate(string $id): JsonResponse
    {
        $template = DocumentTemplate::query()->findOrFail($id);
        return $this->ok($template);
    }

    public function storeFormTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type_id'      => 'required|uuid|exists:document_types,id',
            'name'                  => 'required|string|max:255',
            'code'                  => 'required|string|max:50|regex:/^[A-Z0-9_]+$/|unique:document_templates,code',
            'kind'                  => 'required|in:INPUT,OUTPUT,HYBRID',
            'complexity_kind'       => 'required|in:SIMPLE_BINDING,SCORED_FORM,CUSTOM_COMPONENT',
            'custom_component_name' => 'nullable|string|max:100',
            'source_file_path'      => 'nullable|string|max:500',
            'layout_html'           => 'nullable|string',
            'field_schema'          => 'nullable|array',
            'station_assignments'   => 'nullable|array',
            'page_size'             => 'nullable|string|max:20',
            'orientation'           => 'nullable|string|max:20',
        ]);

        $this->validateStationAssignments($validated['station_assignments'] ?? []);

        $template = DocumentTemplate::create(array_merge($validated, [
            'is_active' => false,   // selalu DRAFT — diaktifkan eksplisit via /activate
            'version'   => 1,
        ]));

        FormRegistryAudit::record(
            'FORM_TEMPLATE_CREATED',
            model: 'DocumentTemplate',
            modelId: $template->id,
            description: "Template code={$template->code} name={$template->name}",
            context: ['kind' => $template->kind, 'complexity_kind' => $template->complexity_kind],
        );

        return $this->ok($template, 'Form template dibuat', 201);
    }

    public function updateFormTemplate(Request $request, string $id): JsonResponse
    {
        /** @var DocumentTemplate $template */
        $template = DocumentTemplate::query()->findOrFail($id);

        $validated = $request->validate([
            'document_type_id'      => 'sometimes|uuid|exists:document_types,id',
            'name'                  => 'sometimes|string|max:255',
            'code'                  => 'sometimes|string|max:50|regex:/^[A-Z0-9_]+$/|unique:document_templates,code,' . $id,
            'kind'                  => 'sometimes|in:INPUT,OUTPUT,HYBRID',
            'complexity_kind'       => 'sometimes|in:SIMPLE_BINDING,SCORED_FORM,CUSTOM_COMPONENT',
            'custom_component_name' => 'nullable|string|max:100',
            'source_file_path'      => 'nullable|string|max:500',
            'layout_html'           => 'nullable|string',
            'field_schema'          => 'nullable|array',
            'station_assignments'   => 'nullable|array',
            'page_size'             => 'sometimes|string|max:20',
            'orientation'           => 'sometimes|string|max:20',
        ]);

        if (isset($validated['code']) && $validated['code'] !== $template->code && $template->isLocked()) {
            return $this->error('Code template tidak bisa diubah setelah aktif (code_locked_at sudah di-set).', 422);
        }

        if (array_key_exists('station_assignments', $validated)) {
            $this->validateStationAssignments($validated['station_assignments'] ?? []);
        }

        // Bump version kalau layout_html atau field_schema berubah.
        if (array_key_exists('layout_html', $validated) || array_key_exists('field_schema', $validated)) {
            $validated['version'] = ($template->version ?? 1) + 1;
        }

        $template->update($validated);

        FormRegistryAudit::record(
            'FORM_TEMPLATE_UPDATED',
            model: 'DocumentTemplate',
            modelId: $template->id,
            description: "Template code={$template->code} v{$template->version}",
            context: [
                'fields_changed' => array_keys($validated),
                'new_version'    => $template->version,
            ],
        );

        return $this->ok($template->fresh(), 'Form template diperbarui');
    }

    public function activateFormTemplate(string $id): JsonResponse
    {
        /** @var DocumentTemplate $template */
        $template = DocumentTemplate::query()->findOrFail($id);
        $template->activate();

        FormRegistryAudit::record(
            'FORM_TEMPLATE_ACTIVATED',
            model: 'DocumentTemplate',
            modelId: $template->id,
            description: "Template {$template->code} activated (code_locked_at={$template->code_locked_at})",
        );

        return $this->ok($template->fresh(), 'Form template diaktifkan');
    }

    public function deactivateFormTemplate(string $id): JsonResponse
    {
        /** @var DocumentTemplate $template */
        $template = DocumentTemplate::query()->findOrFail($id);
        $template->deactivate();

        FormRegistryAudit::record(
            'FORM_TEMPLATE_DEACTIVATED',
            model: 'DocumentTemplate',
            modelId: $template->id,
            description: "Template {$template->code} deactivated",
        );

        return $this->ok($template->fresh(), 'Form template dinonaktifkan');
    }

    public function fieldRegistry(): JsonResponse
    {
        return $this->ok([
            'columns'    => FieldRegistry::columns(),
            'aggregates' => FieldRegistry::aggregates(),
        ]);
    }

    /**
     * GET /master/document-types — daftar kategori parent untuk dropdown Form Template.
     * Filter `?all=1` untuk include inactive (UI master). Default: only active.
     */
    public function indexDocumentTypes(Request $request): JsonResponse
    {
        $q = \App\Models\DocumentType::query()->orderBy('sort_order');
        if (!$request->boolean('all')) {
            $q->where('is_active', true);
        }
        $list = $q->get([
            'id', 'code', 'name', 'category', 'sort_order',
            'fill_frequency', 'generate_type', 'parent_id',
            'required_signatures', 'show_in_rme', 'is_active',
        ]);
        return $this->ok($list);
    }

    /**
     * POST /master/document-type — bikin jenis dokumen baru.
     */
    public function storeDocumentType(Request $request): JsonResponse
    {
        $validated = $this->validateDocumentType($request);

        try {
            $dt = \App\Models\DocumentType::create($validated);
        } catch (\Throwable $e) {
            return $this->error('Gagal simpan: ' . $e->getMessage(), 422);
        }
        return $this->ok($dt, 'Jenis dokumen dibuat.', 201);
    }

    /**
     * PUT /master/document-type/{id}
     */
    public function updateDocumentType(Request $request, string $id): JsonResponse
    {
        $dt = \App\Models\DocumentType::query()->findOrFail($id);
        $validated = $this->validateDocumentType($request, $id);

        // Anti-circular: parent_id tidak boleh diri sendiri atau descendant
        if (!empty($validated['parent_id'])) {
            if ($validated['parent_id'] === $id) {
                return $this->error('Parent tidak boleh diri sendiri.', 422);
            }
            $descendants = $this->collectDescendantIds($id);
            if (in_array($validated['parent_id'], $descendants, true)) {
                return $this->error('Parent membuat siklus (descendant).', 422);
            }
        }

        $dt->update($validated);
        return $this->ok($dt->fresh(), 'Jenis dokumen diupdate.');
    }

    /**
     * DELETE /master/document-type/{id} — soft delete dengan guard.
     */
    public function destroyDocumentType(string $id): JsonResponse
    {
        $dt = \App\Models\DocumentType::query()->findOrFail($id);

        $templateCount = \App\Models\DocumentTemplate::query()->where('document_type_id', $id)->count();
        $patientDocCount = \App\Models\PatientDocument::query()->where('document_type_id', $id)->count();
        $childCount = \App\Models\DocumentType::query()->where('parent_id', $id)->count();

        if ($templateCount > 0 || $patientDocCount > 0 || $childCount > 0) {
            return $this->error(
                "Tidak bisa dihapus — masih dipakai: {$templateCount} template form, {$patientDocCount} dokumen pasien, {$childCount} jenis turunan. " .
                "Saran: nonaktifkan saja (is_active=false) supaya tidak muncul di dropdown wizard.",
                422,
            );
        }

        $dt->delete();
        return $this->ok(null, 'Jenis dokumen dihapus.');
    }

    /**
     * Validation rules — dipakai store & update. Field code unique kecuali current id.
     */
    private function validateDocumentType(Request $request, ?string $ignoreId = null): array
    {
        $codeRule = ['required', 'string', 'max:20'];
        $codeRule[] = $ignoreId
            ? \Illuminate\Validation\Rule::unique('document_types', 'code')->ignore($ignoreId)
            : \Illuminate\Validation\Rule::unique('document_types', 'code');

        return $request->validate([
            'code'                => $codeRule,
            'name'                => ['required', 'string', 'max:255'],
            'fill_frequency'      => ['required', 'in:ONCE_LIFETIME,PER_VISIT,PER_EPISODE'],
            'generate_type'       => ['required', 'in:AUTO,MANUAL,HYBRID'],
            'category'            => ['nullable', 'in:ADMINISTRASI,KLINIS,PENUNJANG,BEDAH,FARMASI,BILLING'],
            'parent_id'           => ['nullable', 'uuid', 'exists:document_types,id'],
            'required_signatures' => ['nullable', 'array'],
            'required_signatures.*.role'        => ['required_with:required_signatures.*', 'string'],
            'required_signatures.*.sign_type'   => ['required_with:required_signatures.*', 'in:PIN,DRAW'],
            'required_signatures.*.is_required' => ['nullable', 'boolean'],
            'show_in_rme'         => ['nullable', 'boolean'],
            'sort_order'          => ['nullable', 'integer'],
            'is_active'           => ['nullable', 'boolean'],
        ]);
    }

    /**
     * BFS collect descendant ids dari node $rootId (untuk anti-circular parent check).
     */
    private function collectDescendantIds(string $rootId): array
    {
        $descendants = [];
        $queue = [$rootId];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $children = \App\Models\DocumentType::query()
                ->where('parent_id', $current)
                ->pluck('id')
                ->all();
            foreach ($children as $c) {
                $descendants[] = $c;
                $queue[] = $c;
            }
        }
        return $descendants;
    }

    public function stationSections(): JsonResponse
    {
        return $this->ok([
            'map'      => SectionRegistry::map(),
            'stations' => SectionRegistry::stations(),
        ]);
    }

    /**
     * POST /master/form-template/upload
     * Upload .docx → parse sync → return parse_id (cache 1 jam).
     * Frontend bisa langsung pakai response.draft, atau poll /parse-result.
     */
    public function uploadFormTemplate(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:docx,pdf|max:5120',   // max 5MB; .docx native, .pdf via PdfParser (text extraction terbatas)
        ]);

        $upload = $request->file('file');
        $original = $upload->getClientOriginalName();

        // Simpan ke storage/app/private/form-template-uploads/ (laravel default disk = local = private).
        $stored = $upload->store('form-template-uploads');
        $absolute = Storage::disk('local')->path($stored);

        try {
            $parseId = $this->formParser->parse($absolute, $original);
        } catch (\Throwable $e) {
            return $this->error('Parser gagal: ' . $e->getMessage(), 422);
        }

        $result = $this->formParser->getResult($parseId);

        return $this->ok(array_merge(
            $result ?? ['parse_id' => $parseId],
            ['source_file_path' => $stored],
        ), 'File berhasil di-parse', 202);
    }

    /**
     * GET /master/form-template/parse-result/{parseId}
     * Poll hasil parsing dari cache. Return 404 kalau parse_id expired/invalid.
     */
    public function parseResultFormTemplate(string $parseId): JsonResponse
    {
        $result = $this->formParser->getResult($parseId);
        if ($result === null) {
            return $this->error('Parse result tidak ditemukan atau sudah expired (cache 1 jam).', 404);
        }
        return $this->ok($result);
    }

    /**
     * Validasi struktur JSON station_assignments — setiap entry harus punya
     * station+section yang terdaftar di SectionRegistry, dan mode yang valid.
     */
    private function validateStationAssignments(?array $assignments): void
    {
        foreach ($assignments ?? [] as $i => $assign) {
            $station = $assign['station'] ?? null;
            $section = $assign['section'] ?? null;
            $mode    = $assign['mode']    ?? null;

            if (!is_string($station) || !is_string($section) || !is_string($mode)) {
                abort(422, "station_assignments[{$i}]: field station/section/mode wajib string.");
            }
            if (!SectionRegistry::isValid($station, $section)) {
                abort(422, "station_assignments[{$i}]: kombinasi station='{$station}' & section='{$section}' tidak valid.");
            }
            if (!in_array($mode, ['INPUT', 'OUTPUT', 'HYBRID'], true)) {
                abort(422, "station_assignments[{$i}]: mode '{$mode}' tidak valid.");
            }
        }
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    // =========================================================================
    // BILLING CATEGORIES (kategori grouping rincian tagihan Kasir)
    // =========================================================================

    public function indexBillingCategory(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexBillingCategory($request->only(['active'])));
    }

    public function storeBillingCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:100|unique:billing_categories,name',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active'  => 'nullable|boolean',
        ]);
        return $this->ok($this->service->storeBillingCategory($validated), 'Kategori tagihan dibuat', 201);
    }

    public function updateBillingCategory(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'sometimes|string|max:100|unique:billing_categories,name,' . $id,
            'sort_order' => 'sometimes|integer|min:0|max:9999',
            'is_active'  => 'sometimes|boolean',
        ]);
        return $this->ok($this->service->updateBillingCategory($id, $validated), 'Kategori tagihan diperbarui');
    }

    public function deleteBillingCategory(string $id): JsonResponse
    {
        $this->service->deleteBillingCategory($id);
        return $this->ok(null, 'Kategori tagihan dihapus');
    }

    public function reorderBillingCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rows'              => 'required|array|min:1',
            'rows.*.id'         => 'required|uuid|exists:billing_categories,id',
            'rows.*.sort_order' => 'required|integer|min:0|max:9999',
        ]);
        $this->service->reorderBillingCategory($validated['rows']);
        return $this->ok(null, 'Urutan kategori disimpan');
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int|string $status = 500): JsonResponse
    {
        // Coerce non-int status (e.g. PDO SQLSTATE string from QueryException) to a valid HTTP code.
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 500;
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
