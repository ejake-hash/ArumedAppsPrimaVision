<?php

namespace App\Http\Controllers;

use App\Services\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MasterDataController extends Controller
{
    public function __construct(private readonly MasterDataService $service) {}

    // =========================================================================
    // CLINIC PROFILE
    // =========================================================================

    public function showProfilKlinik(): JsonResponse
    {
        return $this->ok($this->service->getProfilKlinik());
    }

    public function updateProfilKlinik(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_name'       => 'sometimes|string|max:255',
            'address'           => 'nullable|string|max:500',
            'phone'             => 'nullable|string|max:20',
            'email'             => 'nullable|email|max:255',
            'director_name'     => 'nullable|string|max:255',
            'director_sip'      => 'nullable|string|max:100',
            'rm_seq_length'     => 'nullable|integer|min:4|max:8',
            'pdf_engine'        => 'nullable|in:puppeteer',
            'watermark_enabled' => 'nullable|boolean',
            'watermark_type'    => 'nullable|in:ORIGINAL,COPY,DRAFT',
        ]);

        return $this->ok($this->service->updateProfilKlinik($validated), 'Profil klinik diperbarui');
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
        return $this->ok($this->service->indexPenjamin($request->only(['type', 'parent_id', 'is_system', 'per_page'])));
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
        ]);

        return $this->ok($this->service->updatePenjamin($id, $validated), 'Penjamin diperbarui');
    }

    public function deletePenjamin(string $id): JsonResponse
    {
        $this->service->deletePenjamin($id);

        return $this->ok(null, 'Penjamin dihapus');
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

    // =========================================================================
    // ICD-10 / ICD-9
    // =========================================================================

    public function indexIcd10(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexIcd10(
            $request->only(['search', 'category', 'eye_related', 'favorite', 'per_page'])
        ));
    }

    public function storeIcd10(Request $request): JsonResponse
    {
        // Admin BOLEH menambahkan code baru. Code mengikuti standar WHO,
        // sehingga setelah dibuat tidak boleh diubah (lihat updateIcd10).
        $validated = $request->validate([
            'code'                   => 'required|string|max:10|unique:icd10_codes,code',
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
        return $this->ok($this->service->indexIcd9(
            $request->only(['search', 'category', 'eye_related', 'favorite', 'per_page'])
        ));
    }

    public function storeIcd9(Request $request): JsonResponse
    {
        // Admin BOLEH menambahkan code baru. Code mengikuti standar WHO ICD-9-CM,
        // sehingga setelah dibuat tidak boleh diubah (lihat updateIcd9).
        $validated = $request->validate([
            'code'                   => 'required|string|max:10|unique:icd9_codes,code',
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
            'code'         => 'required|string|max:50|unique:medications,code',
            'name'         => 'required|string|max:255',
            'generic_name' => 'nullable|string|max:255',
            'composition'  => 'nullable|string|max:500',
            'manufacturer' => 'nullable|string|max:255',
            'formularium'  => 'required|in:FORNAS,FORMULARIUM GENERIK,BRANDED',
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
            'name'         => 'sometimes|string|max:255',
            'generic_name' => 'nullable|string|max:255',
            'composition'  => 'nullable|string|max:500',
            'manufacturer' => 'nullable|string|max:255',
            'formularium'  => 'sometimes|in:FORNAS,FORMULARIUM GENERIK,BRANDED',
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
            'category'     => 'nullable|string|max:100',
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
            'category'     => 'nullable|string|max:100',
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

    public function storeIol(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand'         => 'required|string|max:100',
            'manufacturer'  => 'nullable|string|max:255',
            'model'         => 'required|string|max:100',
            'iol_type'      => 'required|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'material'      => 'nullable|in:Acrylic,Silicone,PMMA',
            'power'         => 'required|numeric|between:-20,40',
            'cylinder'      => 'nullable|required_if:iol_type,TORIC|numeric|between:0,10',
            'axis'          => 'nullable|required_if:iol_type,TORIC|integer|between:0,180',
            'lot_number'    => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100|unique:iol_items,serial_number',
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
            'cylinder'      => 'nullable|required_if:iol_type,TORIC|numeric|between:0,10',
            'axis'          => 'nullable|required_if:iol_type,TORIC|integer|between:0,180',
            'lot_number'    => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100|unique:iol_items,serial_number,' . $id,
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
        return $this->ok($this->service->indexPaketBedah($request->only(['search', 'active', 'per_page'])));
    }

    public function storePaketBedah(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'               => 'required|string|max:255',
            'code'               => 'required|string|max:50|unique:surgery_packages,code',
            'description'        => 'nullable|string|max:1000',
            'estimated_duration' => 'nullable|integer|min:1',
            'price'              => 'nullable|numeric|min:0',
            'is_active'          => 'nullable|boolean',
        ]);

        return $this->ok($this->service->storePaketBedah($validated), 'Paket bedah dibuat', 201);
    }

    public function updatePaketBedah(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'               => 'sometimes|string|max:255',
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
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);

        try {
            $csvContent = file_get_contents($request->file('file')->getRealPath());
            $result     = $this->service->importTarifCsv($type, $csvContent);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, "Import selesai: {$result['imported']} berhasil, {$result['skipped']} dilewati.");
    }

    // =========================================================================
    // RESOURCE CSV — generic untuk obat / bhp / iol / icd10 / icd9
    // =========================================================================

    private const CSV_TYPES = ['tindakan', 'obat', 'bhp', 'iol', 'icd10', 'icd9'];

    private function assertCsvType(string $type): void
    {
        if (! in_array($type, self::CSV_TYPES, true)) {
            abort(404);
        }
    }

    /**
     * GET /master/{type}/template-csv
     */
    public function templateCsv(string $type): Response
    {
        $this->assertCsvType($type);

        try {
            $csv = $this->service->csvTemplate($type);
        } catch (\Exception $e) {
            return response($e->getMessage(), $e->getCode() ?: 422);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"template-{$type}.csv\"",
        ]);
    }

    /**
     * GET /master/{type}/export-csv
     */
    public function exportCsv(string $type): Response
    {
        $this->assertCsvType($type);

        try {
            $csv = $this->service->exportResourceCsv($type);
        } catch (\Exception $e) {
            return response($e->getMessage(), $e->getCode() ?: 422);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$type}-" . now()->format('Ymd') . ".csv\"",
        ]);
    }

    /**
     * POST /master/{type}/import-csv
     * Body: multipart/form-data, field: file (CSV)
     */
    public function importCsv(Request $request, string $type): JsonResponse
    {
        $this->assertCsvType($type);

        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);

        try {
            $csvContent = file_get_contents($request->file('file')->getRealPath());
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

    public function updateNomorDokumen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'format'       => 'sometimes|string|max:255',
            'prefix'       => 'nullable|string|max:50',
            'reset_period' => 'nullable|in:DAILY,MONTHLY,YEARLY,NEVER',
            'seq_length'   => 'nullable|integer|min:3|max:10',
        ]);

        return $this->ok($this->service->updateNomorDokumen($id, $validated), 'Konfigurasi nomor dokumen diperbarui');
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

    private function error(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
