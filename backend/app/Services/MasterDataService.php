<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\BhpTariff;
use App\Models\BillingCategory;
use App\Models\ClinicProfile;
use App\Models\DocumentNumberConfig;
use App\Models\DocumentTemplate;
use App\Models\DiagnosticTestType;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Icd10Code;
use App\Models\Icd9Code;
use App\Models\Insurer;
use App\Models\IolItem;
use App\Models\IolTariff;
use App\Models\Medication;
use App\Models\MedicationTariff;
use App\Models\MedicalEquipment;
use App\Models\Procedure;
use App\Models\ProcedureCategory;
use App\Models\ProcedureTariff;
use App\Models\Role;
use App\Models\StationDocumentMapping;
use App\Models\SurgeryPackage;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MasterDataService
{
    public function __construct(private readonly Request $request) {}

    // =========================================================================
    // CLINIC PROFILE
    // =========================================================================

    public function getProfilKlinik(): ClinicProfile
    {
        return ClinicProfile::firstOrFail();
    }

    public function updateProfilKlinik(array $data): ClinicProfile
    {
        $clinic = ClinicProfile::firstOrFail();
        $clinic->update(array_intersect_key($data, array_flip($clinic->getFillable())));

        $this->log(auth('api')->id(), 'UPDATE_CLINIC_PROFILE');

        return $clinic->fresh();
    }

    // =========================================================================
    // ROLES
    // =========================================================================

    public function indexRoles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::orderBy('name')->get();
    }

    public function storeRole(array $data): Role
    {
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'api']);
        $this->log(auth('api')->id(), 'CREATE_ROLE', Role::class, $role->id);
        return $role;
    }

    public function updateRole(string $id, array $data): Role
    {
        $role = Role::findOrFail($id);
        $role->update(['name' => $data['name']]);
        $this->log(auth('api')->id(), 'UPDATE_ROLE', Role::class, $id);
        return $role->fresh();
    }

    public function deleteRole(string $id): void
    {
        $role = Role::findOrFail($id);
        if (User::where('role_id', $id)->exists()) {
            throw new \Exception('Role tidak bisa dihapus — masih digunakan oleh user.', 422);
        }
        $role->delete();
        $this->log(auth('api')->id(), 'DELETE_ROLE', Role::class, $id);
    }

    // =========================================================================
    // PEGAWAI
    // =========================================================================

    public function indexPegawai(array $filters = []): LengthAwarePaginator
    {
        $query = Employee::with('user.role');

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$keyword}%")
                ->orWhere('nip', 'like', "%{$keyword}%")
                ->orWhere('profession', 'ilike', "%{$keyword}%")
            );
        }

        return $query->orderBy('name')->paginate($filters['per_page'] ?? 20);
    }

    public function showPegawai(string $id): Employee
    {
        return Employee::with('user.role')->findOrFail($id);
    }

    public function storePegawai(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $employee = Employee::create([
                'name'       => $data['name'],
                'nip'        => $data['nip'] ?? null,
                'profession' => $data['profession'],
                'sip'        => $data['sip'] ?? null,
                'str'        => $data['str'] ?? null,
                'phone'      => $data['phone'] ?? null,
                'email'      => $data['email'] ?? null,
                'address'    => $data['address'] ?? null,
                'is_active'  => true,
            ]);

            if (! empty($data['email']) && ! empty($data['password'])) {
                User::create([
                    'employee_id' => $employee->id,
                    'role_id'     => $data['role_id'],
                    'name'        => $employee->name,
                    'email'       => $data['email'],
                    'password'    => Hash::make($data['password']),
                    'pin'         => Hash::make($data['pin'] ?? '123456'),
                    'is_active'   => true,
                ]);
            }

            $this->log(auth('api')->id(), 'CREATE_PEGAWAI', Employee::class, $employee->id);

            return $employee->load('user.role');
        });
    }

    public function updatePegawai(string $id, array $data): Employee
    {
        $employee = Employee::findOrFail($id);
        $employee->update(array_intersect_key($data, array_flip($employee->getFillable())));

        if (! empty($data['role_id']) && $employee->user) {
            $employee->user->update(['role_id' => $data['role_id']]);
        }

        $this->log(auth('api')->id(), 'UPDATE_PEGAWAI', Employee::class, $id);

        return $employee->fresh('user.role');
    }

    public function deletePegawai(string $id): void
    {
        $employee = Employee::findOrFail($id);
        $employee->user?->delete();
        $employee->delete();
        $this->log(auth('api')->id(), 'DELETE_PEGAWAI', Employee::class, $id);
    }

    public function resetPasswordPegawai(string $id, string $password): void
    {
        $employee = Employee::with('user')->findOrFail($id);

        if (! $employee->user) {
            throw new \Exception('Pegawai ini tidak memiliki akun user.', 422);
        }

        $employee->user->update(['password' => Hash::make($password)]);
        $this->log(auth('api')->id(), 'RESET_PASSWORD', Employee::class, $id);
    }

    // =========================================================================
    // PENJAMIN
    // =========================================================================

    public function indexPenjamin(array $filters = []): LengthAwarePaginator
    {
        $query = Insurer::query()->withCount('children');
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['parent_id'])) {
            $query->where('parent_id', $filters['parent_id']);
        }
        if (array_key_exists('is_system', $filters) && $filters['is_system'] !== null && $filters['is_system'] !== '') {
            $query->where('is_system', (bool) $filters['is_system']);
        }
        return $query->orderBy('name')->paginate($filters['per_page'] ?? 20);
    }

    public function storePenjamin(array $data): Insurer
    {
        // is_system tidak bisa di-set via API (hanya seeder)
        unset($data['is_system']);
        $insurer = Insurer::create($data);
        $this->log(auth('api')->id(), 'CREATE_PENJAMIN', Insurer::class, $insurer->id);
        return $insurer;
    }

    public function updatePenjamin(string $id, array $data): Insurer
    {
        $insurer = Insurer::findOrFail($id);

        // is_system tidak bisa di-set via API
        unset($data['is_system']);

        // Insurer sistem: hanya boleh ubah address/phone/email/is_active
        if ($insurer->is_system) {
            $data = array_intersect_key($data, array_flip(['address', 'phone', 'email', 'is_active']));
        }

        // Cegah set parent_id ke diri sendiri
        if (! empty($data['parent_id']) && $data['parent_id'] === $insurer->id) {
            throw new \Exception('parent_id tidak boleh sama dengan id sendiri.', 422);
        }

        $insurer->update($data);
        $this->log(auth('api')->id(), 'UPDATE_PENJAMIN', Insurer::class, $id);
        return $insurer->fresh();
    }

    public function deletePenjamin(string $id): void
    {
        $insurer = Insurer::withCount('children')->findOrFail($id);

        if ($insurer->is_system) {
            throw new \Exception('Insurer sistem tidak boleh dihapus.', 422);
        }

        if ($insurer->children_count > 0) {
            throw new \Exception('Insurer ini punya child (TPA). Hapus child dulu.', 422);
        }

        $insurer->delete();
        $this->log(auth('api')->id(), 'DELETE_PENJAMIN', Insurer::class, $id);
    }

    /**
     * Detail insurer + parent + children + jumlah tarif per type.
     * Untuk halaman MetodeBayarDetailView.
     */
    public function showMetodeBayar(string $id): array
    {
        $insurer = Insurer::with(['parent', 'children'])->findOrFail($id);

        // Lookup tarif via parent_id kalau child (inheritance murni)
        $tariffInsurerId = $insurer->tariffInsurerId();

        $counts = [
            'tindakan' => ProcedureTariff::where('insurer_id', $tariffInsurerId)->count(),
            'obat'     => MedicationTariff::where('insurer_id', $tariffInsurerId)->count(),
            'bhp'      => BhpTariff::where('insurer_id', $tariffInsurerId)->count(),
            'iol'      => IolTariff::where('insurer_id', $tariffInsurerId)->count(),
        ];

        return [
            'insurer'           => $insurer,
            'is_child_tpa'      => $insurer->isChildTpa(),
            'tariff_insurer_id' => $tariffInsurerId,
            'counts'            => $counts,
        ];
    }

    // =========================================================================
    // TINDAKAN (PROCEDURES)
    // =========================================================================

    public function indexTindakan(array $filters = []): LengthAwarePaginator
    {
        $query = Procedure::query();
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$keyword}%")
                ->orWhere('code', 'ilike', "%{$keyword}%")
                ->orWhere('category', 'ilike', "%{$keyword}%")
                ->orWhere('keterangan', 'ilike', "%{$keyword}%")
            );
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }
        return $query->orderBy('name')->paginate($filters['per_page'] ?? 25);
    }

    public function storeTindakan(array $data): Procedure
    {
        // Auto-generate code dari kategori kalau tidak diisi
        if (empty($data['code'])) {
            $data['code'] = $this->generateProcedureCode($data['category'] ?? '');
        }
        $proc = Procedure::create($data);
        $this->log(auth('api')->id(), 'CREATE_TINDAKAN', Procedure::class, $proc->id);
        return $proc;
    }

    public function updateTindakan(string $id, array $data): Procedure
    {
        $proc = Procedure::findOrFail($id);
        // Code tidak boleh diubah via update (immutable identifier)
        unset($data['code']);
        $proc->update($data);
        $this->log(auth('api')->id(), 'UPDATE_TINDAKAN', Procedure::class, $id);
        return $proc->fresh();
    }

    public function deleteTindakan(string $id): void
    {
        Procedure::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_TINDAKAN', Procedure::class, $id);
    }

    public function kategoriListTindakan(): array
    {
        // Sumber: tabel master procedure_categories (kategori aktif saja, ordered by name)
        return ProcedureCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code_prefix'])
            ->map(fn ($c) => [
                'id'          => $c->id,
                'name'        => $c->name,
                'code_prefix' => $c->code_prefix,
            ])
            ->all();
    }

    /**
     * Generate kode tindakan: {PREFIX}-{NNN} berdasarkan kategori.
     * Prefix dari procedure_categories. NNN auto-increment per prefix per row existing.
     *
     * @throws \Exception kalau kategori tidak ditemukan di master.
     */
    public function generateProcedureCode(string $categoryName): string
    {
        if ($categoryName === '') {
            throw new \Exception('Kategori wajib diisi untuk generate kode tindakan.', 422);
        }

        $cat = ProcedureCategory::where('name', $categoryName)->first();
        if (! $cat) {
            throw new \Exception("Kategori '{$categoryName}' tidak ditemukan di master kategori. Tambah dulu di Kelola Kategori.", 422);
        }

        $prefix = $cat->code_prefix;

        // Cari max suffix existing untuk prefix ini
        $max = Procedure::withTrashed()
            ->where('code', 'like', $prefix . '-%')
            ->get(['code'])
            ->map(function ($p) use ($prefix) {
                $suffix = substr($p->code, strlen($prefix) + 1);
                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;

        return sprintf('%s-%03d', $prefix, $max + 1);
    }

    // =========================================================================
    // PROCEDURE CATEGORIES (master kategori tindakan) — CRUD
    // =========================================================================

    public function indexProcedureCategories(array $filters = []): array
    {
        $query = ProcedureCategory::query();
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }
        return $query->orderBy('name')->get()->all();
    }

    public function storeProcedureCategory(array $data): ProcedureCategory
    {
        // Normalisasi prefix: uppercase, trim
        if (! empty($data['code_prefix'])) {
            $data['code_prefix'] = strtoupper(trim($data['code_prefix']));
        }
        $cat = ProcedureCategory::create($data);
        $this->log(auth('api')->id(), 'CREATE_PROC_CATEGORY', ProcedureCategory::class, $cat->id);
        return $cat;
    }

    public function updateProcedureCategory(string $id, array $data): ProcedureCategory
    {
        $cat = ProcedureCategory::findOrFail($id);
        // code_prefix immutable kalau sudah dipakai (ada procedure dengan code prefix ini)
        if (isset($data['code_prefix']) && $data['code_prefix'] !== $cat->code_prefix) {
            $usedCount = Procedure::where('code', 'like', $cat->code_prefix . '-%')->count();
            if ($usedCount > 0) {
                throw new \Exception(
                    "Prefix kategori tidak bisa diubah karena sudah dipakai oleh {$usedCount} tindakan.",
                    422
                );
            }
            $data['code_prefix'] = strtoupper(trim($data['code_prefix']));
        }
        $cat->update($data);
        $this->log(auth('api')->id(), 'UPDATE_PROC_CATEGORY', ProcedureCategory::class, $id);
        return $cat->fresh();
    }

    public function deleteProcedureCategory(string $id): void
    {
        $cat = ProcedureCategory::findOrFail($id);
        $usedCount = Procedure::where('code', 'like', $cat->code_prefix . '-%')->count();
        if ($usedCount > 0) {
            throw new \Exception(
                "Kategori tidak bisa dihapus: ada {$usedCount} tindakan yang masih memakai prefix ini.",
                422
            );
        }
        $cat->delete();
        $this->log(auth('api')->id(), 'DELETE_PROC_CATEGORY', ProcedureCategory::class, $id);
    }

    // =========================================================================
    // ICD-10
    // =========================================================================

    public function indexIcd10(array $filters = []): LengthAwarePaginator
    {
        $query = Icd10Code::query();
        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('code', 'ilike', "%{$kw}%")
                ->orWhere('description', 'ilike', "%{$kw}%")
                ->orWhere('indonesian_description', 'ilike', "%{$kw}%")
            );
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['eye_related'])) {
            $query->where('is_eye_related', (bool) $filters['eye_related']);
        }
        if (isset($filters['favorite'])) {
            $query->where('is_favorite', (bool) $filters['favorite']);
        }
        return $query->orderBy('code')->paginate($filters['per_page'] ?? 25);
    }

    public function storeIcd10(array $data): Icd10Code
    {
        $code = Icd10Code::create($data);
        $this->log(auth('api')->id(), 'CREATE_ICD10', Icd10Code::class, $code->id);
        return $code;
    }

    public function updateIcd10(string $id, array $data): Icd10Code
    {
        $code = Icd10Code::findOrFail($id);
        $code->update($data);
        $this->log(auth('api')->id(), 'UPDATE_ICD10', Icd10Code::class, $id);
        return $code->fresh();
    }

    public function deleteIcd10(string $id): void
    {
        Icd10Code::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_ICD10', Icd10Code::class, $id);
    }

    // =========================================================================
    // ICD-9
    // =========================================================================

    public function indexIcd9(array $filters = []): LengthAwarePaginator
    {
        $query = Icd9Code::query();
        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('code', 'ilike', "%{$kw}%")
                ->orWhere('description', 'ilike', "%{$kw}%")
                ->orWhere('indonesian_description', 'ilike', "%{$kw}%")
            );
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['eye_related'])) {
            $query->where('is_eye_related', (bool) $filters['eye_related']);
        }
        if (isset($filters['favorite'])) {
            $query->where('is_favorite', (bool) $filters['favorite']);
        }
        return $query->orderBy('code')->paginate($filters['per_page'] ?? 25);
    }

    public function storeIcd9(array $data): Icd9Code
    {
        $code = Icd9Code::create($data);
        $this->log(auth('api')->id(), 'CREATE_ICD9', Icd9Code::class, $code->id);
        return $code;
    }

    public function updateIcd9(string $id, array $data): Icd9Code
    {
        $code = Icd9Code::findOrFail($id);
        $code->update($data);
        $this->log(auth('api')->id(), 'UPDATE_ICD9', Icd9Code::class, $id);
        return $code->fresh();
    }

    public function deleteIcd9(string $id): void
    {
        Icd9Code::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_ICD9', Icd9Code::class, $id);
    }

    // =========================================================================
    // JENIS PENUNJANG (diagnostic_test_types) — master
    // =========================================================================

    public function indexDiagnosticTestType(array $filters = []): LengthAwarePaginator
    {
        $query = DiagnosticTestType::query();
        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('code', 'ilike', "%{$kw}%")
                ->orWhere('name', 'ilike', "%{$kw}%")
                ->orWhere('category', 'ilike', "%{$kw}%")
            );
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }
        return $query->orderBy('sort_order')->orderBy('name')->paginate($filters['per_page'] ?? 25);
    }

    public function storeDiagnosticTestType(array $data): DiagnosticTestType
    {
        // Urutan tidak diatur admin — auto-append ke akhir (No. mengikuti urutan ini).
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = (int) DiagnosticTestType::max('sort_order') + 1;
        }
        $row = DiagnosticTestType::create($data);
        $this->log(auth('api')->id(), 'CREATE_DIAGNOSTIC_TEST_TYPE', DiagnosticTestType::class, $row->id);
        return $row;
    }

    public function updateDiagnosticTestType(string $id, array $data): DiagnosticTestType
    {
        $row = DiagnosticTestType::findOrFail($id);
        $row->update($data);
        $this->log(auth('api')->id(), 'UPDATE_DIAGNOSTIC_TEST_TYPE', DiagnosticTestType::class, $id);
        return $row->fresh();
    }

    public function deleteDiagnosticTestType(string $id): void
    {
        DiagnosticTestType::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_DIAGNOSTIC_TEST_TYPE', DiagnosticTestType::class, $id);
    }

    // =========================================================================
    // OBAT (MEDICATIONS) — master
    // =========================================================================

    public function indexObat(array $filters = []): LengthAwarePaginator
    {
        $query = Medication::query();
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$keyword}%")
                ->orWhere('code', 'ilike', "%{$keyword}%")
                ->orWhere('generic_name', 'ilike', "%{$keyword}%")
                ->orWhere('composition', 'ilike', "%{$keyword}%")
                ->orWhere('manufacturer', 'ilike', "%{$keyword}%")
            );
        }
        if (! empty($filters['formularium'])) {
            $query->where('formularium', $filters['formularium']);
        }
        if (! empty($filters['form_sediaan'])) {
            $query->where('form_sediaan', $filters['form_sediaan']);
        }
        if (! empty($filters['golongan'])) {
            $query->where('golongan', $filters['golongan']);
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }
        if (! empty($filters['low_stock'])) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }
        return $query->orderBy('name')->paginate($filters['per_page'] ?? 25);
    }

    public function storeObat(array $data): Medication
    {
        if (empty($data['code'])) {
            $data['code'] = $this->generateObatCode();
        }
        $med = Medication::create($data);
        $this->log(auth('api')->id(), 'CREATE_OBAT', Medication::class, $med->id);
        return $med;
    }

    private function generateObatCode(): string
    {
        $last = Medication::withTrashed()
            ->where('code', 'like', 'MED-%')
            ->orderByDesc('code')
            ->value('code');
        $next = 1;
        if ($last && preg_match('/^MED-(\d+)$/', $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }
        return sprintf('MED-%03d', $next);
    }

    public function updateObat(string $id, array $data): Medication
    {
        $med = Medication::findOrFail($id);
        $med->update($data);
        $this->log(auth('api')->id(), 'UPDATE_OBAT', Medication::class, $id);
        return $med->fresh();
    }

    public function deleteObat(string $id): void
    {
        Medication::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_OBAT', Medication::class, $id);
    }

    // =========================================================================
    // BHP
    // =========================================================================

    public function indexBhp(array $filters = []): LengthAwarePaginator
    {
        $query = BhpItem::query();
        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$kw}%")
                ->orWhere('code', 'ilike', "%{$kw}%")
                ->orWhere('category', 'ilike', "%{$kw}%")
                ->orWhere('manufacturer', 'ilike', "%{$kw}%")
            );
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }
        if (! empty($filters['low_stock'])) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }
        return $query->orderBy('name')->paginate($filters['per_page'] ?? 25);
    }

    public function storeBhp(array $data): BhpItem
    {
        if (empty($data['code'])) {
            $data['code'] = $this->generateBhpCode();
        }
        $bhp = BhpItem::create($data);
        $this->log(auth('api')->id(), 'CREATE_BHP', BhpItem::class, $bhp->id);
        return $bhp;
    }

    private function generateBhpCode(): string
    {
        $last = BhpItem::withTrashed()
            ->where('code', 'like', 'BHP-%')
            ->orderByDesc('code')
            ->value('code');
        $next = 1;
        if ($last && preg_match('/^BHP-(\d+)$/', $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }
        return sprintf('BHP-%03d', $next);
    }

    private function generateAlatMedisCode(): string
    {
        $last = MedicalEquipment::withTrashed()
            ->where('code', 'like', 'MEQ-%')
            ->orderByDesc('code')
            ->value('code');
        $next = 1;
        if ($last && preg_match('/^MEQ-(\d+)$/', $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }
        return sprintf('MEQ-%03d', $next);
    }

    public function updateBhp(string $id, array $data): BhpItem
    {
        $bhp = BhpItem::findOrFail($id);
        $bhp->update($data);
        $this->log(auth('api')->id(), 'UPDATE_BHP', BhpItem::class, $id);
        return $bhp->fresh();
    }

    public function deleteBhp(string $id): void
    {
        BhpItem::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_BHP', BhpItem::class, $id);
    }

    // =========================================================================
    // IOL
    // =========================================================================

    public function indexIol(array $filters = []): LengthAwarePaginator
    {
        $query = IolItem::query();
        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('brand', 'ilike', "%{$kw}%")
                ->orWhere('model', 'ilike', "%{$kw}%")
                ->orWhere('manufacturer', 'ilike', "%{$kw}%")
                ->orWhere('serial_number', 'ilike', "%{$kw}%")
                ->orWhere('lot_number', 'ilike', "%{$kw}%")
            );
        }
        if (! empty($filters['iol_type'])) {
            $query->where('iol_type', $filters['iol_type']);
        }
        if (! empty($filters['material'])) {
            $query->where('material', $filters['material']);
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }
        if (isset($filters['is_used'])) {
            $query->where('is_used', (bool) $filters['is_used']);
        }
        // available_only: belum dipakai (is_used=false), stok > 0, dan aktif
        if (! empty($filters['available_only'])) {
            $query->where('is_used', false)->where('stock', '>', 0)->where('is_active', true);
        }
        return $query->orderBy('brand')->paginate($filters['per_page'] ?? 25);
    }

    public function storeIol(array $data): IolItem
    {
        $iol = IolItem::create($data);
        $this->log(auth('api')->id(), 'CREATE_IOL', IolItem::class, $iol->id);
        return $iol;
    }

    public function updateIol(string $id, array $data): IolItem
    {
        $iol = IolItem::findOrFail($id);
        $iol->update($data);
        $this->log(auth('api')->id(), 'UPDATE_IOL', IolItem::class, $id);
        return $iol->fresh();
    }

    public function deleteIol(string $id): void
    {
        IolItem::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_IOL', IolItem::class, $id);
    }

    // =========================================================================
    // PAKET BEDAH
    // =========================================================================

    public function indexPaketBedah(array $filters = []): LengthAwarePaginator
    {
        $query = SurgeryPackage::query();
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$filters['search']}%")
                ->orWhere('code', 'ilike', "%{$filters['search']}%")
            );
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }
        return $query->orderBy('name')->paginate($filters['per_page'] ?? 20);
    }

    public function storePaketBedah(array $data): SurgeryPackage
    {
        $pkg = SurgeryPackage::create($data);
        $this->log(auth('api')->id(), 'CREATE_PAKET_BEDAH', SurgeryPackage::class, $pkg->id);
        return $pkg;
    }

    public function updatePaketBedah(string $id, array $data): SurgeryPackage
    {
        $pkg = SurgeryPackage::findOrFail($id);
        $pkg->update($data);
        $this->log(auth('api')->id(), 'UPDATE_PAKET_BEDAH', SurgeryPackage::class, $id);
        return $pkg->fresh();
    }

    public function deletePaketBedah(string $id): void
    {
        SurgeryPackage::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_PAKET_BEDAH', SurgeryPackage::class, $id);
    }

    // =========================================================================
    // TARIF — generic CRUD + CSV import/export
    // =========================================================================

    private function tariffModel(string $type): string
    {
        return match ($type) {
            'tindakan' => ProcedureTariff::class,
            'obat'     => MedicationTariff::class,
            'bhp'      => BhpTariff::class,
            'iol'      => IolTariff::class,
            default    => throw new \Exception("Tipe tarif tidak dikenal: {$type}", 422),
        };
    }

    private function tariffFk(string $type): string
    {
        return match ($type) {
            'tindakan' => 'procedure_id',
            'obat'     => 'medication_id',
            'bhp'      => 'bhp_item_id',
            'iol'      => 'iol_item_id',
            default    => throw new \InvalidArgumentException(),
        };
    }

    public function indexTarif(string $type, array $filters = []): LengthAwarePaginator
    {
        $model = $this->tariffModel($type);

        $itemRel = match ($type) {
            'tindakan' => 'procedure',
            'obat'     => 'medication',
            'bhp'      => 'bhpItem',
            'iol'      => 'iolItem',
        };

        $query = $model::with(['insurer', $itemRel]);

        if (! empty($filters['insurer_id'])) {
            $query->where('insurer_id', $filters['insurer_id']);
        }

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function storeTarif(string $type, array $data): mixed
    {
        $model = $this->tariffModel($type);
        $fk    = $this->tariffFk($type);

        $tariff = $model::updateOrCreate(
            [
                $fk            => $data[$fk],
                'insurer_id'   => $data['insurer_id'],
            ],
            ['price' => $data['price'], 'is_active' => $data['is_active'] ?? true]
        );

        $this->log(auth('api')->id(), 'UPSERT_TARIF', $model, $tariff->id, "type:{$type}");

        return $tariff;
    }

    public function updateTarif(string $type, string $id, array $data): mixed
    {
        $model  = $this->tariffModel($type);
        $tariff = $model::findOrFail($id);
        $tariff->update(['price' => $data['price'], 'is_active' => $data['is_active'] ?? $tariff->is_active]);
        $this->log(auth('api')->id(), 'UPDATE_TARIF', $model, $id);
        return $tariff->fresh('insurer');
    }

    public function deleteTarif(string $type, string $id): void
    {
        $model = $this->tariffModel($type);
        $model::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_TARIF', $model, $id);
    }

    /**
     * Generate template CSV (header only) untuk tarif per insurer per type.
     * Format: no, nama, kategori, harga_master, harga_jual (TANPA kode)
     *
     * - `no`: auto-increment row number, di-ignore saat import
     * - `nama` + `kategori`: identifier lookup ke master item
     * - `harga_master`: info read-only (di-ignore saat import)
     * - `harga_jual`: kolom override price yang admin isi
     */
    public function templateTarifCsv(string $type): string
    {
        $this->tariffModel($type);

        $itemLabel = match ($type) {
            'tindakan' => 'tindakan (master Tarif Tindakan)',
            'obat'     => 'obat',
            'bhp'      => 'BHP',
            'iol'      => 'IOL',
            default    => $type,
        };
        $notes = [
            'PETUNJUK PENGISIAN — baris diawali "#" diabaikan saat import (boleh dibiarkan/dihapus).',
            'Tarif jual penjamin ini per ' . $itemLabel . '. Kolom WAJIB: nama, kategori, harga_jual.',
            'nama + kategori dicocokkan (case-insensitive) ke master — item harus SUDAH ada di master.',
            'kolom "no" & "harga_master" hanya info, DIABAIKAN saat import (harga_master = harga master, read-only).',
            'harga_jual = tarif yang ditagih ke penjamin ini (angka >= 0).',
            'Item sama (nama+kategori) yang sudah punya tarif → harga_jual di-update; belum ada → ditambah.',
        ];

        $output = fopen('php://temp', 'r+');
        foreach ($notes as $note) {
            fwrite($output, '# ' . $note . "\n");
        }
        fputcsv($output, ['no', 'nama', 'kategori', 'harga_master', 'harga_jual'], ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Export tarif insurer ke CSV.
     * Header: no, nama, kategori, harga_master, harga_jual (TANPA kode)
     */
    public function exportTarifCsvForInsurer(string $type, string $insurerId): string
    {
        $itemTable    = $this->itemTable($type);
        $itemNameCol  = $this->itemNameColumn($type);
        $itemCatCol   = $this->itemKategoriColumn($type);
        $itemPriceCol = $this->itemMasterPriceColumn($type);

        $rows = DB::table($this->tariffTable($type) . ' as t')
            ->join("{$itemTable} as item", "t.{$this->tariffFk($type)}", '=', 'item.id')
            ->where('t.insurer_id', $insurerId)
            ->whereNull('t.deleted_at')
            ->whereNull('item.deleted_at')   // jangan ekspor tarif item master yg sudah dihapus
                                             // (kalau diekspor, import gagal "tidak ditemukan" — export & import inkonsisten)
            ->select([
                "item.{$itemNameCol} as nama",
                "item.{$itemCatCol} as kategori",
                "item.{$itemPriceCol} as harga_master",
                't.price as harga_jual',
            ])
            ->orderBy("item.{$itemNameCol}")
            ->get();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['no', 'nama', 'kategori', 'harga_master', 'harga_jual'], ',', '"', '\\');
        $no = 1;
        foreach ($rows as $row) {
            fputcsv($output, [
                $no++,
                $row->nama,
                $row->kategori,
                $row->harga_master,
                $row->harga_jual,
            ], ',', '"', '\\');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Import tarif insurer dari CSV.
     * Expected header (case-insensitive): nama, kategori, harga_jual (wajib);
     * kolom lain (no, harga_master) opsional & di-ignore.
     * Upsert by (item_id, insurer_id) — item lookup via (nama, kategori) ke master.
     */
    public function importTarifCsvForInsurer(string $type, string $insurerId, string $csvContent): array
    {
        $lines = $this->csvDataLines($csvContent);
        if (empty($lines)) {
            throw new \Exception('File CSV kosong.', 422);
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));

        foreach (['nama', 'kategori', 'harga_jual'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw new \Exception("Header CSV harus mengandung kolom '{$required}'.", 422);
            }
        }

        $model        = $this->tariffModel($type);
        $fk           = $this->tariffFk($type);
        $itemTable    = $this->itemTable($type);
        $itemNameCol  = $this->itemNameColumn($type);
        $itemCatCol   = $this->itemKategoriColumn($type);

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($lines as $idx => $line) {
            $lineNum = $idx + 2;
            if (trim($line) === '') continue;

            $values = str_getcsv($line, ',', '"', '\\');
            if (count($values) !== count($headers)) {
                $errors[] = "Baris {$lineNum}: jumlah kolom tidak sesuai header";
                $skipped++;
                continue;
            }
            $row = array_combine($headers, $values);

            $nama     = trim((string) ($row['nama'] ?? ''));
            $kategori = trim((string) ($row['kategori'] ?? ''));
            $hargaJual = $row['harga_jual'] ?? '';

            if ($nama === '' || $kategori === '' || $hargaJual === '') {
                $errors[] = "Baris {$lineNum}: 'nama', 'kategori', atau 'harga_jual' kosong";
                $skipped++;
                continue;
            }

            // Lookup item by (nama, kategori) — case-insensitive
            $item = DB::table($itemTable)
                ->whereRaw("LOWER({$itemNameCol}) = ?", [strtolower($nama)])
                ->whereRaw("LOWER({$itemCatCol}) = ?", [strtolower($kategori)])
                ->whereNull('deleted_at')
                ->first();

            if (! $item) {
                $errors[] = "Baris {$lineNum}: item '{$nama}' kategori '{$kategori}' tidak ditemukan di master";
                $skipped++;
                continue;
            }

            $existing = $model::where($fk, $item->id)->where('insurer_id', $insurerId)->first();
            $model::updateOrCreate(
                [$fk => $item->id, 'insurer_id' => $insurerId],
                ['price' => (float) $hargaJual, 'is_active' => true]
            );
            if ($existing) $updated++; else $inserted++;
        }

        $this->log(auth('api')->id(), 'IMPORT_TARIF_CSV', null, null, "type:{$type} insurer:{$insurerId} new:{$inserted} upd:{$updated} skip:{$skipped}");

        return compact('inserted', 'updated', 'skipped', 'errors');
    }

    private function tariffTable(string $type): string
    {
        return match ($type) {
            'tindakan' => 'procedure_tariffs',
            'obat'     => 'medication_tariffs',
            'bhp'      => 'bhp_tariffs',
            'iol'      => 'iol_tariffs',
        };
    }

    private function itemTable(string $type): string
    {
        return match ($type) {
            'tindakan' => 'procedures',
            'obat'     => 'medications',
            'bhp'      => 'bhp_items',
            'iol'      => 'iol_items',
        };
    }

    /** Kolom unique-code per tabel item (iol_items pakai serial_number, lain pakai code). */
    private function itemCodeColumn(string $type): string
    {
        return $type === 'iol' ? 'serial_number' : 'code';
    }

    /** Kolom nama item per tabel (iol_items pakai brand). */
    private function itemNameColumn(string $type): string
    {
        return $type === 'iol' ? 'brand' : 'name';
    }

    /** Kolom kategori-equivalent per tabel item. */
    private function itemKategoriColumn(string $type): string
    {
        return match ($type) {
            'tindakan' => 'category',
            'obat'     => 'golongan',
            'bhp'      => 'category',
            'iol'      => 'iol_type',
        };
    }

    /** Kolom harga master per tabel item. */
    private function itemMasterPriceColumn(string $type): string
    {
        return $type === 'tindakan' ? 'base_price' : 'price';
    }

    // =========================================================================
    // RESOURCE CSV — generic export/import/template untuk master data sederhana
    // (obat, bhp, iol, icd10, icd9). Tarif punya endpoint sendiri di atas.
    // =========================================================================

    /**
     * Definisi schema CSV per resource:
     *   table       — nama tabel di DB
     *   model       — FQCN Eloquent model
     *   uniqueKey   — kolom unik untuk upsert (cari row existing)
     *   columns     — daftar kolom yang di-export & di-import (urutan = header CSV)
     *   casts       — map kolom -> cast type ('int'|'float'|'bool') saat import
     */
    private function resourceSchema(string $type): array
    {
        return match ($type) {
            'tindakan' => [
                'table'     => 'procedures',
                'model'     => Procedure::class,
                'uniqueKey' => 'code',
                'columns'   => ['code', 'name', 'category', 'base_price', 'keterangan', 'is_active'],
                'casts'     => ['base_price' => 'float', 'is_active' => 'bool'],
            ],
            'obat' => [
                'table'     => 'medications',
                'model'     => Medication::class,
                'uniqueKey' => 'code',
                'columns'   => ['code', 'kfa_code', 'name', 'generic_name', 'composition', 'manufacturer', 'formularium', 'form_sediaan', 'golongan', 'unit_besar', 'unit_kecil', 'konversi', 'stock', 'min_stock', 'price', 'expiry_date', 'batch_number', 'description', 'is_active'],
                'casts'     => ['konversi' => 'int', 'stock' => 'int', 'min_stock' => 'int', 'price' => 'float', 'is_active' => 'bool'],
            ],
            'bhp' => [
                'table'     => 'bhp_items',
                'model'     => BhpItem::class,
                'uniqueKey' => 'code',
                'columns'   => ['code', 'name', 'category', 'unit', 'manufacturer', 'stock', 'min_stock', 'price', 'expiry_date', 'batch_number', 'description', 'is_active'],
                'casts'     => ['stock' => 'int', 'min_stock' => 'int', 'price' => 'float', 'is_active' => 'bool'],
            ],
            'iol' => [
                'table'     => 'iol_items',
                'model'     => IolItem::class,
                'uniqueKey' => 'serial_number',
                'columns'   => ['brand', 'manufacturer', 'model', 'iol_type', 'material', 'power', 'cylinder', 'axis', 'lot_number', 'serial_number', 'gs1_barcode', 'expiry_date', 'stock', 'price', 'is_active'],
                'casts'     => ['power' => 'float', 'cylinder' => 'float', 'axis' => 'int', 'stock' => 'int', 'price' => 'float', 'is_active' => 'bool'],
            ],
            'icd10' => [
                'table'     => 'icd10_codes',
                'model'     => Icd10Code::class,
                'uniqueKey' => 'code',
                'columns'   => ['code', 'chapter', 'chapter_label', 'category', 'description', 'indonesian_description', 'is_eye_related', 'is_favorite'],
                'casts'     => ['is_eye_related' => 'bool', 'is_favorite' => 'bool'],
            ],
            'icd9' => [
                'table'     => 'icd9_codes',
                'model'     => Icd9Code::class,
                'uniqueKey' => 'code',
                'columns'   => ['code', 'category', 'description', 'indonesian_description', 'is_eye_related', 'is_favorite'],
                'casts'     => ['is_eye_related' => 'bool', 'is_favorite' => 'bool'],
            ],
            'alat-medis' => [
                'table'     => 'medical_equipments',
                'model'     => MedicalEquipment::class,
                'uniqueKey' => 'code', // export pakai uniqueKey utk orderBy; import by-name (code auto-gen MEQ-NNN)
                'columns'   => ['code', 'name', 'category', 'brand', 'model', 'serial_number', 'location', 'status', 'calibration_due_at', 'purchase_date', 'description', 'is_active'],
                'casts'     => ['is_active' => 'bool'],
            ],
            default => throw new \Exception("Tipe resource tidak dikenal: {$type}", 422),
        };
    }

    /**
     * Generate CSV template kosong (header row saja) untuk resource tertentu.
     */
    public function csvTemplate(string $type): string
    {
        // Special-case: tindakan pakai header friendly tanpa kode
        if ($type === 'tindakan') {
            return $this->tindakanCsvHeaderOnly();
        }

        $schema  = $this->resourceSchema($type);
        $columns = $this->csvHeaderColumns($type, $schema);
        $output  = fopen('php://temp', 'r+');

        // Petunjuk pengisian sebagai baris komentar (#). Importer otomatis
        // meng-skip baris diawali '#', jadi admin tidak wajib menghapusnya.
        foreach ($this->csvTemplateNotes($type) as $note) {
            fwrite($output, '# ' . $note . "\n");
        }

        fputcsv($output, $columns, ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Pecah konten CSV jadi baris data: buang \r, baris kosong, dan baris
     * komentar (diawali '#' — petunjuk pengisian di template). Hasilnya
     * di-reindex 0-based sehingga elemen pertama = header.
     */
    private function csvDataLines(string $csvContent): array
    {
        $raw = explode("\n", str_replace("\r", '', trim($csvContent)));
        $lines = array_filter($raw, static function ($line) {
            $t = trim($line);
            return $t !== '' && ! str_starts_with($t, '#');
        });
        return array_values($lines);
    }

    /**
     * Cast satu sel CSV ke tipe target, dengan penanganan sel KOSONG yang aman
     * terhadap kolom NOT NULL:
     *   - int/float kosong → 0  (mis. stock/min_stock NOT NULL tanpa default →
     *     dulu di-set null → INSERT crash 23502).
     *   - bool kosong → false.
     *   - string kosong → null (kolom string umumnya nullable).
     * Sel berisi tetap di-cast sesuai tipe.
     */
    private function castCsvCell(mixed $raw, string $castType): mixed
    {
        $empty = ($raw === '' || $raw === null);

        return match ($castType) {
            'int'   => $empty ? 0 : (int) $raw,
            'float' => $empty ? 0.0 : (float) $raw,
            'bool'  => $empty ? false : in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'y'], true),
            default => $empty ? null : $raw,
        };
    }

    /**
     * Cocokkan nama kategori (case-insensitive) ke master ProcedureCategory dan
     * kembalikan NAMA KANONIK yang terdaftar. Null kalau tidak ada yang cocok.
     * Dipakai import tindakan supaya 'tindakan'/'TINDAKAN' = 'Tindakan'.
     */
    private function resolveCategoryName(string $category): ?string
    {
        $cat = ProcedureCategory::whereRaw('LOWER(name) = ?', [strtolower(trim($category))])->first();
        return $cat?->name;
    }

    /**
     * Parse nilai boolean dari sel CSV (status aktif/nonaktif, dll).
     * Aktif: 1/true/yes/y/aktif/active. Nonaktif: 0/false/no/n/nonaktif/inactive.
     * Kosong / nilai tak dikenal → $default.
     */
    private function parseCsvBool(mixed $raw, bool $default = true): bool
    {
        if ($raw === null) return $default;
        $v = strtolower(trim((string) $raw));
        if ($v === '') return $default;
        if (in_array($v, ['1', 'true', 'yes', 'y', 'aktif', 'active'], true)) return true;
        if (in_array($v, ['0', 'false', 'no', 'n', 'nonaktif', 'tidak aktif', 'inactive'], true)) return false;
        return $default;
    }

    /**
     * Baris petunjuk pengisian (tanpa prefix '#') untuk template CSV.
     * Ditaruh di atas header; importer akan mengabaikannya saat parsing.
     */
    private function csvTemplateNotes(string $type): array
    {
        $common = [
            'PETUNJUK PENGISIAN — baris diawali "#" diabaikan saat import (boleh dibiarkan/dihapus).',
            'Kolom "name" WAJIB diisi. Identifier upsert = name (tidak case-sensitive): nama baru = tambah, nama sama = perbarui.',
            'Kolom "is_active": 1 = aktif, 0 = nonaktif.',
        ];

        return match ($type) {
            'bhp' => array_merge($common, [
                'Kolom "code" dibuat otomatis (BHP-001, BHP-002, ...) untuk item baru — tidak perlu diisi.',
                'Kolom "category" WAJIB salah satu: ' . implode(' | ', \App\Models\BhpItem::CATEGORIES) . '.',
                'Kolom "expiry_date": format YYYY-MM-DD (mis. 2027-01-31), boleh kosong.',
                'Contoh baris: Kasa Steril 10x10,MEDICAL_BHP,pcs,Onemed,500,50,1500,2027-01-31,,Catatan,1',
            ]),
            'obat' => array_merge($common, [
                'Kolom "code" dibuat otomatis (MED-001, ...) untuk item baru — tidak perlu diisi.',
                'Kolom "kfa_code" = kode KFA Kemenkes (untuk Satu Sehat), boleh kosong. Isi dari menu Inventori Farmasi (tombol Cari KFA) atau ketik manual.',
                'Kolom "expiry_date": format YYYY-MM-DD, boleh kosong. konversi/stock/min_stock = angka bulat, price = angka.',
            ]),
            'alat-medis' => array_merge($common, [
                'Kolom "code" dibuat otomatis (MEQ-001, MEQ-002, ...) untuk item baru — tidak perlu diisi.',
                'Kolom "category" salah satu: ' . implode(' | ', \App\Models\MedicalEquipment::CATEGORIES) . '.',
                'Kolom "status" salah satu: ACTIVE | MAINTENANCE | RETIRED.',
                'Kolom tanggal (calibration_due_at, purchase_date): format YYYY-MM-DD, boleh kosong.',
                'Contoh baris: Microscope Zeiss,MICROSCOPE,Zeiss,OPMI Lumera,SN-123,OK-1,ACTIVE,2026-12-31,2024-01-15,Catatan,1',
            ]),
            'iol' => [
                'PETUNJUK PENGISIAN — baris diawali "#" diabaikan saat import (boleh dibiarkan/dihapus).',
                'Identitas IOL = kombinasi brand + model + power (WAJIB ketiganya). Sama → perbarui, beda → tambah.',
                'serial_number OPSIONAL (untuk unit fisik tertentu). power/cylinder/price = angka, axis/stock = bulat.',
                'Kolom "iol_type": MONOFOCAL | MULTIFOCAL | TORIC | TRIFOCAL | EDOF | PHAKIC.',
                'Kolom "is_active": 1 = aktif, 0 = nonaktif.',
            ],
            default => $common,
        };
    }

    /**
     * Kolom CSV yang dipakai untuk template & export.
     * obat/bhp: kode di-exclude (auto-gen MED-/BHP-NNN di backend; identifier upsert = name).
     */
    private function csvHeaderColumns(string $type, array $schema): array
    {
        if (in_array($type, ['obat', 'bhp', 'alat-medis'], true)) {
            return array_values(array_filter($schema['columns'], fn ($c) => $c !== 'code'));
        }
        return $schema['columns'];
    }

    /**
     * Export semua baris (non-soft-deleted) resource ke CSV.
     */
    public function exportResourceCsv(string $type): string
    {
        // Special-case: tindakan
        if ($type === 'tindakan') {
            return $this->exportTindakanCsv();
        }

        $schema  = $this->resourceSchema($type);
        $columns = $this->csvHeaderColumns($type, $schema);
        $orderBy = in_array($type, ['obat', 'bhp', 'alat-medis'], true) ? 'name' : $schema['uniqueKey'];

        $rows = DB::table($schema['table'])
            ->whereNull('deleted_at')
            ->orderBy($orderBy)
            ->get($columns);

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $columns, ',', '"', '\\');

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $val = $row->$col ?? null;
                if (is_bool($val)) {
                    $val = $val ? '1' : '0';
                }
                $values[] = $val;
            }
            fputcsv($output, $values, ',', '"', '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Header CSV master tindakan (tanpa kode). Format:
     * no, nama, kategori, harga, keterangan, status
     */
    private function tindakanCsvHeaderOnly(): string
    {
        $kategori = ProcedureCategory::orderBy('name')->pluck('name')->implode(' | ');
        $notes = [
            'PETUNJUK PENGISIAN — baris diawali "#" diabaikan saat import (boleh dibiarkan/dihapus).',
            'Kolom WAJIB: nama, kategori, harga. (keterangan & status opsional; kolom "no" diabaikan)',
            'kategori HARUS terdaftar di master kategori (case-insensitive): ' . ($kategori ?: '-') . '.',
            'Kategori baru? Tambah dulu di tombol "Kelola Kategori". Kode tindakan dibuat otomatis (mis. TND-001).',
            'harga = angka >= 0 (kosong/negatif/bukan angka → baris ditolak).',
            'status: aktif/1/true → aktif; nonaktif/0/false → nonaktif; kosong → aktif.',
            'Identitas: nama + kategori (case-insensitive). Sudah ada → perbarui harga; baru → tambah.',
        ];

        $output = fopen('php://temp', 'r+');
        foreach ($notes as $note) {
            fwrite($output, '# ' . $note . "\n");
        }
        fputcsv($output, ['no', 'nama', 'kategori', 'harga', 'keterangan', 'status'], ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Export master tindakan ke CSV.
     * Header: no, nama, kategori, harga, keterangan, status
     * Kode tidak diekspor — saat re-import, lookup pakai nama+kategori.
     */
    private function exportTindakanCsv(): string
    {
        $rows = Procedure::orderBy('category')->orderBy('name')->get(['name', 'category', 'base_price', 'keterangan', 'is_active']);

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['no', 'nama', 'kategori', 'harga', 'keterangan', 'status'], ',', '"', '\\');
        $no = 1;
        foreach ($rows as $row) {
            fputcsv($output, [
                $no++,
                $row->name,
                $row->category,
                $row->base_price,
                $row->keterangan ?? '',
                $row->is_active ? 'aktif' : 'nonaktif',
            ], ',', '"', '\\');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Import CSV untuk resource. Upsert by uniqueKey.
     *
     * **Special-case 'tindakan'**: kolom `code` opsional. Baris dengan code
     * kosong → CREATE baru + auto-generate code dari kategori. Baris dengan
     * code terisi → UPDATE existing by code.
     *
     * Returns: { inserted, updated, skipped, errors[] }
     */
    public function importResourceCsv(string $type, string $csvContent): array
    {
        if ($type === 'tindakan') {
            return $this->importTindakanCsv($csvContent);
        }

        if (in_array($type, ['obat', 'bhp', 'alat-medis'], true)) {
            return $this->importItemByNameCsv($type, $csvContent);
        }

        if ($type === 'iol') {
            return $this->importIolCsv($csvContent);
        }

        $schema = $this->resourceSchema($type);
        $model  = $schema['model'];
        $unique = $schema['uniqueKey'];

        $lines = $this->csvDataLines($csvContent);
        if (empty($lines)) {
            throw new \Exception('File CSV kosong.', 422);
        }

        $headers = array_map('trim', str_getcsv(array_shift($lines), ',', '"', '\\'));

        if (! in_array($unique, $headers, true)) {
            throw new \Exception("Kolom unik '{$unique}' tidak ditemukan di header CSV.", 422);
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($lines as $idx => $line) {
            $lineNum = $idx + 2; // +1 untuk header, +1 untuk 1-based

            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, ',', '"', '\\');
            if (count($values) !== count($headers)) {
                $errors[] = "Baris {$lineNum}: jumlah kolom tidak sesuai header";
                $skipped++;
                continue;
            }

            $row = array_combine($headers, $values);

            if (empty($row[$unique])) {
                $errors[] = "Baris {$lineNum}: kolom unik '{$unique}' kosong";
                $skipped++;
                continue;
            }

            $data = [];
            foreach ($schema['columns'] as $col) {
                if (! array_key_exists($col, $row)) {
                    continue;
                }
                $data[$col] = $this->castCsvCell($row[$col], $schema['casts'][$col] ?? 'string');
            }

            try {
                $existing = $model::where($unique, $data[$unique])->first();
                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    $model::create($data);
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Baris {$lineNum}: " . $e->getMessage();
                $skipped++;
            }
        }

        $this->log(
            auth('api')->id(),
            'IMPORT_RESOURCE_CSV',
            null,
            null,
            "type:{$type} inserted:{$inserted} updated:{$updated} skipped:{$skipped}"
        );

        return compact('inserted', 'updated', 'skipped', 'errors');
    }

    /**
     * Import CSV khusus tindakan. Header tanpa kode — identifier pakai
     * kombinasi (nama, kategori). Code di-autogenerate kalau CREATE.
     *
     * Header expected (case-insensitive): nama, kategori, harga (wajib).
     * keterangan & status (aktif/nonaktif/1/0) opsional. Kolom `no` di-ignore.
     *
     * Behavior:
     *   - (nama, kategori) sudah ada → UPDATE harga/keterangan/status; code unchanged
     *   - (nama, kategori) baru     → CREATE + autogen code dari kategori
     */
    private function importTindakanCsv(string $csvContent): array
    {
        $lines = $this->csvDataLines($csvContent);
        if (empty($lines)) {
            throw new \Exception('File CSV kosong.', 422);
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));

        foreach (['nama', 'kategori', 'harga'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw new \Exception("Header CSV harus mengandung kolom '{$required}'.", 422);
            }
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($lines as $idx => $line) {
            $lineNum = $idx + 2;
            if (trim($line) === '') continue;

            $values = str_getcsv($line, ',', '"', '\\');
            if (count($values) !== count($headers)) {
                $errors[] = "Baris {$lineNum}: jumlah kolom tidak sesuai header";
                $skipped++;
                continue;
            }
            $row = array_combine($headers, $values);

            $name     = trim((string) ($row['nama'] ?? ''));
            $category = trim((string) ($row['kategori'] ?? ''));
            $price    = trim((string) ($row['harga'] ?? ''));

            if ($name === '' || $category === '') {
                $errors[] = "Baris {$lineNum}: 'nama' atau 'kategori' kosong";
                $skipped++;
                continue;
            }

            // Validasi harga: WAJIB angka >= 0 (kosong/negatif/non-numeric ditolak).
            // Cegah bug lama `(float)"abc"` → 0 yang diam-diam jadi tarif Rp 0.
            if ($price === '' || ! is_numeric($price) || (float) $price < 0) {
                $errors[] = "Baris {$lineNum}: 'harga' harus berupa angka >= 0 (diisi: '" . $price . "')";
                $skipped++;
                continue;
            }

            // Kategori dicocokkan case-insensitive ke master ProcedureCategory.
            // Pakai NAMA KANONIK yang terdaftar (mis. 'tindakan' → 'Tindakan').
            $canonicalCategory = $this->resolveCategoryName($category);
            if ($canonicalCategory === null) {
                $errors[] = "Baris {$lineNum}: kategori '{$category}' tidak terdaftar di master kategori. Tambah dulu di Kelola Kategori.";
                $skipped++;
                continue;
            }

            $data = [
                'name'       => $name,
                'category'   => $canonicalCategory,
                'base_price' => (float) $price,
                'keterangan' => isset($row['keterangan']) && $row['keterangan'] !== '' ? $row['keterangan'] : null,
                'is_active'  => $this->parseCsvBool($row['status'] ?? null, true),
            ];

            try {
                // Lookup by (nama, kategori kanonik) — case-insensitive
                $existing = Procedure::whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->whereRaw('LOWER(category) = ?', [strtolower($canonicalCategory)])
                    ->first();

                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    // CREATE + autogen code dari kategori kanonik (sudah pasti terdaftar)
                    $autoCode = $this->generateProcedureCode($canonicalCategory);
                    Procedure::create($data + ['code' => $autoCode]);
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Baris {$lineNum}: " . $e->getMessage();
                $skipped++;
            }
        }

        $this->log(auth('api')->id(), 'IMPORT_TINDAKAN_CSV', null, null, "inserted:{$inserted} updated:{$updated} skipped:{$skipped}");
        return compact('inserted', 'updated', 'skipped', 'errors');
    }

    /**
     * Import CSV untuk obat/bhp dengan kolom `code` opsional/hilang.
     *
     * - Identifier upsert: LOWER(name). Match → UPDATE (code tidak diubah).
     *   Mismatch → CREATE + auto-gen code (MED-NNN / BHP-NNN).
     * - Duplicate name dalam 1 file: last-row-wins (baris terakhir menimpa
     *   data baris-baris sebelumnya untuk key yg sama).
     * - `code` di header tetap diterima (kalau user paksa sertakan) — dipakai
     *   sebagai code eksplisit saat CREATE, tapi tidak override lookup.
     */
    private function importItemByNameCsv(string $type, string $csvContent): array
    {
        $schema = $this->resourceSchema($type);
        $model  = $schema['model'];

        $lines = $this->csvDataLines($csvContent);
        if (empty($lines)) {
            throw new \Exception('File CSV kosong.', 422);
        }

        $headers = array_map('trim', str_getcsv(array_shift($lines), ',', '"', '\\'));

        if (! in_array('name', $headers, true)) {
            throw new \Exception("Header CSV harus mengandung kolom 'name'.", 422);
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        // Pass 1: gather → last-row-wins by LOWER(name)
        $bucket = []; // key: lower(name), value: ['lineNum'=>int, 'data'=>array]
        foreach ($lines as $idx => $line) {
            $lineNum = $idx + 2;
            if (trim($line) === '') continue;

            $values = str_getcsv($line, ',', '"', '\\');
            if (count($values) !== count($headers)) {
                $errors[] = "Baris {$lineNum}: jumlah kolom tidak sesuai header";
                $skipped++;
                continue;
            }
            $row = array_combine($headers, $values);

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = "Baris {$lineNum}: kolom 'name' kosong";
                $skipped++;
                continue;
            }

            $data = [];
            foreach ($schema['columns'] as $col) {
                if (! array_key_exists($col, $row)) continue;
                $data[$col] = $this->castCsvCell($row[$col], $schema['casts'][$col] ?? 'string');
            }
            $data['name'] = $name;

            $bucket[strtolower($name)] = ['lineNum' => $lineNum, 'data' => $data];
        }

        // Pass 2: persist (last write wins per name)
        foreach ($bucket as $lname => $entry) {
            $lineNum = $entry['lineNum'];
            $data    = $entry['data'];
            try {
                $existing = $model::whereRaw('LOWER(name) = ?', [$lname])->first();
                if ($existing) {
                    unset($data['code']); // never override existing code
                    $existing->update($data);
                    $updated++;
                } else {
                    if (empty($data['code'])) {
                        $data['code'] = match ($type) {
                            'obat'       => $this->generateObatCode(),
                            'bhp'        => $this->generateBhpCode(),
                            'alat-medis' => $this->generateAlatMedisCode(),
                        };
                    }
                    $model::create($data);
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Baris {$lineNum}: " . $e->getMessage();
                $skipped++;
            }
        }

        $this->log(
            auth('api')->id(),
            'IMPORT_RESOURCE_CSV',
            null,
            null,
            "type:{$type} inserted:{$inserted} updated:{$updated} skipped:{$skipped}"
        );

        return compact('inserted', 'updated', 'skipped', 'errors');
    }

    /**
     * Import CSV IOL. Identitas upsert = (brand, model, power) — katalog IOL.
     * `serial_number` OPSIONAL (banyak IOL master belum diserialisasi per unit;
     * kolomnya partial-unique hanya saat tidak null). DULU pakai serial_number
     * sebagai kunci → export lalu import balik selalu gagal "serial kosong".
     *
     * Header wajib: brand, model, power. Sisanya opsional (lihat resourceSchema).
     * Lookup case-insensitive utk brand/model.
     */
    private function importIolCsv(string $csvContent): array
    {
        $schema = $this->resourceSchema('iol');

        $lines = $this->csvDataLines($csvContent);
        if (empty($lines)) {
            throw new \Exception('File CSV kosong.', 422);
        }

        $headers = array_map('trim', str_getcsv(array_shift($lines), ',', '"', '\\'));
        foreach (['brand', 'model', 'power'] as $req) {
            if (! in_array($req, $headers, true)) {
                throw new \Exception("Header CSV IOL harus mengandung kolom '{$req}'.", 422);
            }
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($lines as $idx => $line) {
            $lineNum = $idx + 2;
            if (trim($line) === '') continue;

            $values = str_getcsv($line, ',', '"', '\\');
            if (count($values) !== count($headers)) {
                $errors[] = "Baris {$lineNum}: jumlah kolom tidak sesuai header";
                $skipped++;
                continue;
            }
            $row = array_combine($headers, $values);

            $brand = trim((string) ($row['brand'] ?? ''));
            $model = trim((string) ($row['model'] ?? ''));
            $power = $row['power'] ?? '';
            if ($brand === '' || $model === '' || $power === '') {
                $errors[] = "Baris {$lineNum}: 'brand', 'model', atau 'power' kosong (wajib untuk identitas IOL)";
                $skipped++;
                continue;
            }

            // Bangun data dari kolom schema + cast.
            $data = [];
            foreach ($schema['columns'] as $col) {
                if (! array_key_exists($col, $row)) continue;
                $data[$col] = $this->castCsvCell($row[$col], $schema['casts'][$col] ?? 'string');
            }

            try {
                $existing = IolItem::whereRaw('LOWER(brand) = ?', [strtolower($brand)])
                    ->whereRaw('LOWER(model) = ?', [strtolower($model)])
                    ->where('power', (float) $power)
                    ->first();

                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    IolItem::create($data);
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Baris {$lineNum}: " . $e->getMessage();
                $skipped++;
            }
        }

        $this->log(auth('api')->id(), 'IMPORT_RESOURCE_CSV', null, null, "type:iol inserted:{$inserted} updated:{$updated} skipped:{$skipped}");

        return compact('inserted', 'updated', 'skipped', 'errors');
    }

    // =========================================================================
    // JENIS DOKUMEN
    // =========================================================================

    public function indexJenisDokumen(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = DocumentType::with('children')->whereNull('parent_id');

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['show_in_rme'])) {
            $query->where('show_in_rme', (bool) $filters['show_in_rme']);
        }

        return $query->orderBy('sort_order')->get();
    }

    public function storeJenisDokumen(array $data): DocumentType
    {
        $docType = DocumentType::create($data);
        $this->log(auth('api')->id(), 'CREATE_DOC_TYPE', DocumentType::class, $docType->id);
        return $docType;
    }

    public function updateJenisDokumen(string $id, array $data): DocumentType
    {
        $docType = DocumentType::findOrFail($id);
        $docType->update($data);
        $this->log(auth('api')->id(), 'UPDATE_DOC_TYPE', DocumentType::class, $id);
        return $docType->fresh('children');
    }

    public function deleteJenisDokumen(string $id): void
    {
        $docType = DocumentType::findOrFail($id);

        if ($docType->children()->exists()) {
            throw new \Exception('Jenis dokumen tidak bisa dihapus — masih memiliki sub-jenis.', 422);
        }

        $docType->delete();
        $this->log(auth('api')->id(), 'DELETE_DOC_TYPE', DocumentType::class, $id);
    }

    // =========================================================================
    // TEMPLATE DOKUMEN
    // =========================================================================

    public function indexTemplateDokumen(array $filters = []): LengthAwarePaginator
    {
        $query = DocumentTemplate::with('documentType');

        if (! empty($filters['document_type_id'])) {
            $query->where('document_type_id', $filters['document_type_id']);
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 20);
    }

    public function showTemplateDokumen(string $id): DocumentTemplate
    {
        return DocumentTemplate::with('documentType')->findOrFail($id);
    }

    public function storeTemplateDokumen(array $data): DocumentTemplate
    {
        $template = DocumentTemplate::create($data);
        $this->log(auth('api')->id(), 'CREATE_TEMPLATE', DocumentTemplate::class, $template->id);
        return $template->load('documentType');
    }

    public function updateTemplateDokumen(string $id, array $data): DocumentTemplate
    {
        $template = DocumentTemplate::findOrFail($id);
        $template->update($data);
        $this->log(auth('api')->id(), 'UPDATE_TEMPLATE', DocumentTemplate::class, $id);
        return $template->fresh('documentType');
    }

    public function deleteTemplateDokumen(string $id): void
    {
        DocumentTemplate::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_TEMPLATE', DocumentTemplate::class, $id);
    }

    // =========================================================================
    // STATION DOCUMENT MAPPINGS
    // =========================================================================

    public function indexStasiunDokumen(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = StationDocumentMapping::with('documentType');

        if (! empty($filters['station'])) {
            $query->where('station', $filters['station']);
        }

        return $query->orderBy('station')->orderBy('document_type_id')->get();
    }

    public function updateStasiunDokumen(string $id, array $data): StationDocumentMapping
    {
        $mapping = StationDocumentMapping::findOrFail($id);

        $mapping->update(array_filter([
            'is_available' => $data['is_available'] ?? null,
            'can_create'   => $data['can_create'] ?? null,
            'can_submit'   => $data['can_submit'] ?? null,
            'can_print'    => $data['can_print'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_STATION_MAPPING', StationDocumentMapping::class, $id);

        return $mapping->fresh('documentType');
    }

    // =========================================================================
    // DOCUMENT NUMBER CONFIGS
    // =========================================================================

    public function indexNomorDokumen(): \Illuminate\Database\Eloquent\Collection
    {
        return DocumentNumberConfig::orderBy('document_type_code')->get();
    }

    public function updateNomorDokumen(string $id, array $data): DocumentNumberConfig
    {
        $config = DocumentNumberConfig::findOrFail($id);

        $config->update(array_filter([
            'format'       => $data['format'] ?? null,
            'prefix'       => $data['prefix'] ?? null,
            'reset_period' => $data['reset_period'] ?? null,
            'seq_length'   => $data['seq_length'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_DOC_NUMBER_CONFIG', DocumentNumberConfig::class, $id);

        return $config->fresh();
    }

    // =========================================================================
    // BILLING CATEGORIES (kategori grouping di rincian tagihan Kasir)
    // =========================================================================

    public function indexBillingCategory(array $filters = []): array
    {
        $query = BillingCategory::query();
        if (array_key_exists('active', $filters) && $filters['active'] !== null && $filters['active'] !== '') {
            $query->where('is_active', (bool) $filters['active']);
        }
        return $query->orderBy('sort_order')->orderBy('name')->get()->toArray();
    }

    public function storeBillingCategory(array $data): BillingCategory
    {
        $row = BillingCategory::create([
            'name'       => trim($data['name']),
            'sort_order' => $data['sort_order'] ?? 100,
            'is_active'  => $data['is_active'] ?? true,
        ]);
        $this->log(auth('api')->id(), 'CREATE_BILLING_CATEGORY', BillingCategory::class, $row->id);
        return $row;
    }

    public function updateBillingCategory(string $id, array $data): BillingCategory
    {
        $row = BillingCategory::findOrFail($id);
        $row->update(array_filter([
            'name'       => isset($data['name'])       ? trim($data['name']) : null,
            'sort_order' => $data['sort_order']        ?? null,
            'is_active'  => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
        ], fn ($v) => ! is_null($v)));
        $this->log(auth('api')->id(), 'UPDATE_BILLING_CATEGORY', BillingCategory::class, $id);
        return $row->fresh();
    }

    public function deleteBillingCategory(string $id): void
    {
        $row = BillingCategory::findOrFail($id);
        $row->delete();
        $this->log(auth('api')->id(), 'DELETE_BILLING_CATEGORY', BillingCategory::class, $id);
    }

    /**
     * Bulk reorder. Payload: [{ id, sort_order }, ...]
     */
    public function reorderBillingCategory(array $rows): void
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                if (empty($r['id'])) continue;
                BillingCategory::where('id', $r['id'])->update(['sort_order' => (int) ($r['sort_order'] ?? 100)]);
            }
        });
        $this->log(auth('api')->id(), 'REORDER_BILLING_CATEGORY', BillingCategory::class, null, count($rows) . ' rows');
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function log(
        ?string $userId,
        string $action,
        ?string $model = null,
        ?string $modelId = null,
        ?string $description = null
    ): void {
        SystemLog::create([
            'user_id'     => $userId,
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'description' => $description,
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
}
