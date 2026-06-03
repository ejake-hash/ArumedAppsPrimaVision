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
use App\Models\Supplier;
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
                    // password: model User punya cast 'hashed' → biarkan plain, JANGAN
                    // Hash::make di sini (kalau di-hash dulu jadi double-hash & login gagal).
                    'password'    => $data['password'],
                    // pin: DISIMPAN PLAINTEXT (model TIDAK punya cast 'pin'; verifikasi
                    // pakai hash_equals mentah di DokterController). Konsisten dgn
                    // UserService — kalau Hash::make di sini, verify-pin/TTD selalu gagal.
                    'pin'         => $data['pin'] ?? '123456',
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

        // Model User punya cast 'hashed' → pass plain (Hash::make di sini = double-hash → login gagal).
        $employee->user->update(['password' => $password]);
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
        // only_tpa_view (opt-in, khusus halaman Metode Bayar): sembunyikan anggota TPA
        // (yang punya parent_id) karena tarifnya identik dengan TPA induk → duplikatif.
        // DEFAULT tanpa flag = kembalikan SEMUA, supaya dropdown Admisi/Tarif/dll yang
        // pakai endpoint ini tetap bisa memilih anggota (mis. "Allianz").
        if (! empty($filters['only_tpa_view'])) {
            $query->whereNull('parent_id');
        }
        return $query->orderBy('name')->paginate($filters['per_page'] ?? 20);
    }

    public function storePenjamin(array $data): Insurer
    {
        // is_system tidak bisa di-set via API (hanya seeder)
        unset($data['is_system']);
        // Guard keanggotaan TPA bila parent_id di-set lewat jalur API/CSV.
        if (! empty($data['parent_id'])) {
            $this->assertValidTpaParent($data['parent_id'], null);
        }
        // is_tpa: selalu eksplisit boolean (default false). Anggota TPA (punya parent)
        // tidak boleh sekaligus jadi TPA induk.
        $isTpa = ! empty($data['is_tpa']);
        if ($isTpa && ! empty($data['parent_id'])) {
            throw new \Exception('Anggota TPA tidak boleh ditandai sebagai TPA induk.', 422);
        }
        $data['is_tpa'] = $isTpa;
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
        // Guard keanggotaan TPA bila parent_id di-set lewat jalur API/CSV.
        if (array_key_exists('parent_id', $data) && ! empty($data['parent_id'])) {
            if ($insurer->is_system) {
                throw new \Exception('Penjamin sistem tidak boleh dijadikan anggota TPA.', 422);
            }
            $this->assertValidTpaParent($data['parent_id'], $insurer->id);
        }

        // is_tpa: penanda TPA induk. (Untuk sistem sudah ter-strip di atas.)
        if (array_key_exists('is_tpa', $data)) {
            $wantTpa = ! empty($data['is_tpa']);
            // parent_id final = yang baru (kalau dikirim) atau yang lama.
            $finalParent = array_key_exists('parent_id', $data) ? $data['parent_id'] : $insurer->parent_id;
            if ($wantTpa && ! empty($finalParent)) {
                throw new \Exception('Anggota TPA tidak boleh ditandai sebagai TPA induk. Keluarkan dulu dari TPA.', 422);
            }
            // Cegah menonaktifkan TPA yang masih punya anggota (hindari anggota orphan).
            if (! $wantTpa && Insurer::where('parent_id', $insurer->id)->exists()) {
                throw new \Exception('Tidak bisa menonaktifkan TPA: masih punya anggota. Keluarkan semua anggota dulu.', 422);
            }
            $data['is_tpa'] = $wantTpa;
        }

        $insurer->update($data);
        $this->log(auth('api')->id(), 'UPDATE_PENJAMIN', Insurer::class, $id);
        return $insurer->fresh();
    }

    /**
     * Validasi calon TPA induk untuk keanggotaan: harus ada, BUKAN sistem, dan harus
     * root (parent_id null) — mencegah rantai 3-tingkat (A→B→C). $selfId = id insurer
     * yang sedang dijadikan anggota (untuk cek calon parent tidak sama dgn diri sendiri).
     */
    private function assertValidTpaParent(string $parentId, ?string $selfId): void
    {
        if ($selfId !== null && $parentId === $selfId) {
            throw new \Exception('TPA tidak boleh sama dengan diri sendiri.', 422);
        }
        $parent = Insurer::find($parentId);
        if (! $parent) {
            throw new \Exception('TPA induk tidak ditemukan.', 422);
        }
        if ($parent->is_system) {
            throw new \Exception('Penjamin sistem tidak boleh dijadikan TPA induk.', 422);
        }
        if ($parent->parent_id !== null) {
            throw new \Exception('TPA induk tidak boleh berupa anggota TPA lain (maks. 2 tingkat).', 422);
        }
    }

    // ─── TPA membership (kelola anggota dari sisi TPA induk) ──────────────────

    /**
     * Jadikan satu penjamin (member) sebagai anggota TPA induk (tpa).
     * Tarif lama member DIHAPUS (soft-delete) di 4 tabel tarif — anggota mengikuti
     * tarif TPA 100% lewat Insurer::tariffInsurerId(), jadi tarif sendiri tak terpakai
     * & menyisakan data nyangkut bila dibiarkan.
     */
    public function addTpaMember(string $tpaId, string $memberId): Insurer
    {
        $tpa    = Insurer::findOrFail($tpaId);
        $member = Insurer::findOrFail($memberId);

        if ($member->id === $tpa->id) {
            throw new \Exception('Anggota tidak boleh sama dengan TPA.', 422);
        }
        if ($tpa->is_system || $member->is_system) {
            throw new \Exception('Penjamin sistem (UMUM/BPJS/SOSIAL) tidak bisa jadi TPA maupun anggota.', 422);
        }
        if ($tpa->is_tpa !== true) {
            throw new \Exception('Penjamin ini bukan TPA induk. Tandai sebagai TPA dulu.', 422);
        }
        if ($tpa->parent_id !== null) {
            throw new \Exception('TPA induk tidak boleh berupa anggota TPA lain (maks. 2 tingkat).', 422);
        }
        if ($member->parent_id !== null) {
            throw new \Exception('Penjamin ini sudah menjadi anggota TPA lain. Keluarkan dulu.', 422);
        }
        if (Insurer::where('parent_id', $member->id)->exists()) {
            throw new \Exception('Penjamin ini adalah TPA induk yang punya anggota — tidak bisa dijadikan anggota.', 422);
        }

        DB::transaction(function () use ($tpa, $member) {
            // Hapus tarif lama anggota (soft-delete). Anggota numpang tarif TPA.
            ProcedureTariff::where('insurer_id', $member->id)->delete();
            MedicationTariff::where('insurer_id', $member->id)->delete();
            BhpTariff::where('insurer_id', $member->id)->delete();
            IolTariff::where('insurer_id', $member->id)->delete();

            $member->update(['parent_id' => $tpa->id]);
        });

        $this->log(auth('api')->id(), 'ADD_TPA_MEMBER', Insurer::class, $member->id, "tpa:{$tpa->id}");
        return $member->fresh();
    }

    /**
     * Tambah anggota TPA lewat NAMA BARU (buat penjamin baru sekaligus). Bila nama
     * sudah ada (case-insensitive, soft-delete aware) → delegasikan ke addTpaMember
     * supaya guard & penghapusan tarif lama konsisten. Anggota baru = tipe ASURANSI,
     * aktif, tanpa tarif.
     */
    public function addTpaMemberByName(string $tpaId, string $name): Insurer
    {
        $tpa  = Insurer::findOrFail($tpaId);
        $name = trim(preg_replace('/\s+/', ' ', $name));

        if ($name === '') {
            throw new \Exception('Nama asuransi wajib diisi.', 422);
        }
        if ($tpa->is_system) {
            throw new \Exception('Penjamin sistem tidak bisa jadi TPA.', 422);
        }
        if ($tpa->is_tpa !== true || $tpa->parent_id !== null) {
            throw new \Exception('Penjamin ini bukan TPA induk. Tandai sebagai TPA dulu.', 422);
        }

        $existing = Insurer::withTrashed()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            if ($existing->is_system) {
                throw new \Exception("Nama bentrok dengan penjamin sistem '{$existing->name}'.", 422);
            }
            // Existing yang valid → reuse addTpaMember (guard + hapus tarif lama).
            return $this->addTpaMember($tpaId, $existing->id);
        }

        $member = Insurer::create([
            'name'      => $name,
            'type'      => 'ASURANSI',
            'is_active' => true,
            'is_tpa'    => false,
            'parent_id' => $tpa->id,
        ]);
        $this->log(auth('api')->id(), 'ADD_TPA_MEMBER_NEW', Insurer::class, $member->id, "tpa:{$tpa->id}");
        return $member->fresh();
    }

    /**
     * Keluarkan anggota dari TPA (parent_id → null). Tarif yang dihapus saat join
     * TIDAK dikembalikan — anggota keluar tanpa tarif (harus diisi manual). Diinfokan di UI.
     */
    public function removeTpaMember(string $tpaId, string $memberId): Insurer
    {
        $member = Insurer::findOrFail($memberId);
        if ($member->parent_id !== $tpaId) {
            throw new \Exception('Penjamin ini bukan anggota TPA tersebut.', 422);
        }
        $member->update(['parent_id' => null]);
        $this->log(auth('api')->id(), 'REMOVE_TPA_MEMBER', Insurer::class, $member->id, "tpa:{$tpaId}");
        return $member->fresh();
    }

    /**
     * Kandidat anggota untuk dropdown "Tambah Anggota" di halaman TPA: penjamin
     * ASURANSI/PERUSAHAAN yang root (bukan anggota), bukan sistem, bukan TPA itu sendiri,
     * dan belum punya anggota sendiri (cegah rantai 3-tingkat).
     */
    public function candidateTpaMembers(string $tpaId): array
    {
        return Insurer::query()
            ->whereNull('parent_id')
            ->where('is_system', false)
            ->where('is_tpa', false)   // TPA lain tidak boleh jadi anggota TPA
            ->where('id', '!=', $tpaId)
            ->whereIn('type', ['ASURANSI', 'PERUSAHAAN'])
            ->whereDoesntHave('children')
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->all();
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
        // children diringkas (id/name/type) untuk panel "Anggota TPA".
        $insurer = Insurer::with(['parent', 'children:id,name,type,parent_id'])->findOrFail($id);

        // Lookup tarif via parent_id kalau child (inheritance murni)
        $tariffInsurerId = $insurer->tariffInsurerId();

        $counts = [
            'tindakan' => ProcedureTariff::where('insurer_id', $tariffInsurerId)->count(),
            'obat'     => MedicationTariff::where('insurer_id', $tariffInsurerId)->count(),
            'bhp'      => BhpTariff::where('insurer_id', $tariffInsurerId)->count(),
            'iol'      => IolTariff::where('insurer_id', $tariffInsurerId)->count(),
        ];

        // Boleh kelola anggota TPA bila: bukan sistem, root (bukan anggota), DAN ditandai is_tpa.
        $canManageMembers = ! $insurer->is_system
            && $insurer->parent_id === null
            && $insurer->is_tpa === true;

        return [
            'insurer'             => $insurer,
            'is_child_tpa'        => $insurer->isChildTpa(),
            'tariff_insurer_id'   => $tariffInsurerId,
            'counts'              => $counts,
            'can_manage_members'  => $canManageMembers,
        ];
    }

    // ─── Penjamin — CSV template / export / import ───────────────────────────

    private const PENJAMIN_CSV_HEADER = ['nama', 'tipe', 'kode', 'parent', 'telepon', 'email', 'alamat', 'aktif', 'is_tpa'];

    /** Template CSV penjamin (header + petunjuk pengisian). */
    public function templatePenjaminCsv(): string
    {
        $notes = [
            'PETUNJUK PENGISIAN — baris diawali "#" diabaikan saat import.',
            'Kolom WAJIB: nama, tipe. Opsional: kode, parent, telepon, email, alamat, aktif (1/0, default 1), is_tpa (1/0).',
            'tipe = salah satu: UMUM, BPJS, ASURANSI, PERUSAHAAN, SOSIAL.',
            'parent = NAMA penjamin induk (TPA) bila penjamin ini mewarisi tarif TPA (mis. Allianz via Admedika). Kosongkan kalau stand-alone.',
            'is_tpa = 1 hanya untuk TPA induk (mis. Admedika). Penjamin sistem & anggota TPA harus 0.',
            'Penjamin dicocokkan by NAMA: sudah ada → kolom lain di-update; belum → ditambah.',
            'Baris sistem (UMUM/BPJS/SOSIAL) hanya boleh update telepon/email/alamat/aktif; nama & tipe dipertahankan.',
        ];
        $output = fopen('php://temp', 'r+');
        foreach ($notes as $n) {
            fwrite($output, '# ' . $n . "\n");
        }
        fputcsv($output, self::PENJAMIN_CSV_HEADER, ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /** Export semua penjamin ke CSV. */
    public function exportPenjaminCsv(): string
    {
        $rows = Insurer::with('parent')->orderBy('name')->get();
        $output = fopen('php://temp', 'r+');
        fputcsv($output, self::PENJAMIN_CSV_HEADER, ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($output, [
                $r->name,
                $r->type,
                $r->code ?? '',
                $r->parent?->name ?? '',
                $r->phone ?? '',
                $r->email ?? '',
                $r->address ?? '',
                $r->is_active ? 1 : 0,
                $r->is_tpa ? 1 : 0,
            ], ',', '"', '\\');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Import penjamin dari CSV.
     * Header wajib (case-insensitive): nama, tipe. Opsional: kode, parent, telepon, email, alamat, aktif.
     * Upsert by NAMA (soft-delete aware → restore bila trashed). parent dicocokkan by nama
     * (penjamin induk harus sudah ada). Baris sistem hanya update phone/email/address/is_active.
     */
    public function importPenjaminCsv(string $csvContent): array
    {
        $lines = $this->csvDataLines($csvContent);
        if (empty($lines)) {
            throw new \Exception('File kosong.', 422);
        }
        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));
        foreach (['nama', 'tipe'] as $req) {
            if (! in_array($req, $headers, true)) {
                throw new \Exception("Header CSV harus mengandung kolom '{$req}'.", 422);
            }
        }

        $validTypes = ['UMUM', 'BPJS', 'ASURANSI', 'PERUSAHAAN', 'SOSIAL'];
        $inserted = 0; $updated = 0; $skipped = 0; $errors = [];

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

            $nama = trim((string) ($row['nama'] ?? ''));
            $tipe = strtoupper(trim((string) ($row['tipe'] ?? '')));
            if ($nama === '' || $tipe === '') {
                $errors[] = "Baris {$lineNum}: 'nama' atau 'tipe' kosong";
                $skipped++;
                continue;
            }
            if (! in_array($tipe, $validTypes, true)) {
                $errors[] = "Baris {$lineNum}: tipe '{$tipe}' tidak valid (harus " . implode('/', $validTypes) . ')';
                $skipped++;
                continue;
            }

            $kode    = array_key_exists('kode', $row) ? (trim((string) $row['kode']) ?: null) : null;
            $telepon = array_key_exists('telepon', $row) ? (trim((string) $row['telepon']) ?: null) : null;
            $email   = array_key_exists('email', $row) ? (trim((string) $row['email']) ?: null) : null;
            $alamat  = array_key_exists('alamat', $row) ? (trim((string) $row['alamat']) ?: null) : null;
            $aktif   = array_key_exists('aktif', $row) ? (trim((string) $row['aktif']) !== '' ? (bool) (int) $row['aktif'] : true) : true;
            // is_tpa hanya dibaca bila kolomnya ADA di file — supaya import file lama
            // (tanpa kolom) TIDAK melucuti flag TPA existing.
            $hasIsTpaCol = array_key_exists('is_tpa', $row);
            $isTpa = $hasIsTpaCol && in_array(strtolower(trim((string) $row['is_tpa'])), ['1', 'ya', 'true', 'y', 'yes'], true);

            // Resolve parent (TPA) by nama bila diisi.
            $parentId = null;
            $parentName = array_key_exists('parent', $row) ? trim((string) $row['parent']) : '';
            if ($parentName !== '') {
                $parent = Insurer::whereRaw('LOWER(name) = ?', [strtolower($parentName)])->first();
                if (! $parent) {
                    $errors[] = "Baris {$lineNum}: parent '{$parentName}' tidak ditemukan, parent dikosongkan";
                } else {
                    $parentId = $parent->id;
                }
            }

            // Upsert by name (soft-delete aware) → restore bila trashed.
            $existing = Insurer::withTrashed()->whereRaw('LOWER(name) = ?', [strtolower($nama)])->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                if ($existing->is_system) {
                    // Baris sistem: hanya boleh ubah telepon/email/alamat/aktif. is_tpa diabaikan.
                    if ($isTpa) {
                        $errors[] = "Baris {$lineNum}: penjamin sistem tidak boleh is_tpa, diabaikan";
                    }
                    $existing->update(['phone' => $telepon, 'email' => $email, 'address' => $alamat, 'is_active' => $aktif]);
                } else {
                    // Cegah parent menunjuk diri sendiri.
                    if ($parentId === $existing->id) {
                        $parentId = null;
                        $errors[] = "Baris {$lineNum}: parent tidak boleh diri sendiri, dikosongkan";
                    }
                    $payload = [
                        'type' => $tipe, 'code' => $kode, 'parent_id' => $parentId,
                        'phone' => $telepon, 'email' => $email, 'address' => $alamat, 'is_active' => $aktif,
                    ];
                    // is_tpa hanya disentuh bila kolom ada (jaga flag existing saat file lama).
                    if ($hasIsTpaCol) {
                        $wantTpa = $isTpa;
                        if ($wantTpa && $parentId !== null) {
                            $wantTpa = false;
                            $errors[] = "Baris {$lineNum}: anggota TPA tidak boleh is_tpa, diset 0";
                        }
                        if (! $wantTpa && Insurer::where('parent_id', $existing->id)->exists()) {
                            $wantTpa = true;
                            $errors[] = "Baris {$lineNum}: TPA masih punya anggota, is_tpa dipertahankan 1";
                        }
                        $payload['is_tpa'] = $wantTpa;
                    }
                    $existing->update($payload);
                }
                $updated++;
            } else {
                // Baris baru: anggota (punya parent) tidak boleh is_tpa.
                $wantTpa = $isTpa;
                if ($wantTpa && $parentId !== null) {
                    $wantTpa = false;
                    $errors[] = "Baris {$lineNum}: anggota TPA tidak boleh is_tpa, diset 0";
                }
                Insurer::create([
                    'name' => $nama, 'type' => $tipe, 'code' => $kode, 'parent_id' => $parentId,
                    'phone' => $telepon, 'email' => $email, 'address' => $alamat, 'is_active' => $aktif,
                    'is_tpa' => $wantTpa,
                ]);
                $inserted++;
            }
        }

        $this->log(auth('api')->id(), 'IMPORT_PENJAMIN_CSV', Insurer::class, null, "new:{$inserted} upd:{$updated} skip:{$skipped}");
        return compact('inserted', 'updated', 'skipped', 'errors');
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

    // ─── Kategori Tindakan — CSV template / export / import ──────────────────

    /** Template CSV kategori tindakan (header + petunjuk). */
    public function templateKategoriCsv(): string
    {
        $notes = [
            'PETUNJUK PENGISIAN — baris diawali "#" diabaikan saat import.',
            'Kolom WAJIB: nama, prefix_kode. Opsional: deskripsi, aktif (1/0, default 1).',
            'prefix_kode = awalan kode tindakan (mis. TND), otomatis di-UPPERCASE; harus unik.',
            'Kategori dicocokkan by NAMA: sudah ada → prefix/deskripsi/aktif di-update; belum → ditambah.',
            'prefix_kode TIDAK akan diubah bila kategori existing sudah dipakai tindakan (dilewati dgn catatan).',
        ];
        $output = fopen('php://temp', 'r+');
        foreach ($notes as $n) {
            fwrite($output, '# ' . $n . "\n");
        }
        fputcsv($output, ['nama', 'prefix_kode', 'deskripsi', 'aktif'], ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /** Export semua kategori tindakan ke CSV. */
    public function exportKategoriCsv(): string
    {
        $rows = ProcedureCategory::orderBy('name')->get(['name', 'code_prefix', 'description', 'is_active']);
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['nama', 'prefix_kode', 'deskripsi', 'aktif'], ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($output, [$r->name, $r->code_prefix, $r->description ?? '', $r->is_active ? 1 : 0], ',', '"', '\\');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Import kategori tindakan dari CSV.
     * Header wajib (case-insensitive): nama, prefix_kode. Opsional: deskripsi, aktif.
     * Upsert by NAMA (soft-delete aware). Bila kategori existing sudah dipakai tindakan
     * dan prefix berbeda → prefix dipertahankan + dicatat sebagai warning (selaras
     * dengan guard updateProcedureCategory), kolom lain tetap di-update.
     */
    public function importKategoriCsv(string $csvContent): array
    {
        $lines = $this->csvDataLines($csvContent);
        if (empty($lines)) {
            throw new \Exception('File kosong.', 422);
        }
        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));
        foreach (['nama', 'prefix_kode'] as $req) {
            if (! in_array($req, $headers, true)) {
                throw new \Exception("Header CSV harus mengandung kolom '{$req}'.", 422);
            }
        }

        $inserted = 0; $updated = 0; $skipped = 0; $errors = [];

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

            $nama   = trim((string) ($row['nama'] ?? ''));
            $prefix = strtoupper(trim((string) ($row['prefix_kode'] ?? '')));
            if ($nama === '' || $prefix === '') {
                $errors[] = "Baris {$lineNum}: 'nama' atau 'prefix_kode' kosong";
                $skipped++;
                continue;
            }
            $desc   = array_key_exists('deskripsi', $row) ? trim((string) $row['deskripsi']) : null;
            $aktif  = array_key_exists('aktif', $row) ? (trim((string) $row['aktif']) !== '' ? (bool) (int) $row['aktif'] : true) : true;

            // Cari existing by name (soft-delete aware) → restore bila trashed.
            $existing = ProcedureCategory::withTrashed()->whereRaw('LOWER(name) = ?', [strtolower($nama)])->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $payload = ['description' => $desc, 'is_active' => $aktif];

                // Guard prefix: kalau berbeda & sudah dipakai tindakan → pertahankan + warning.
                if ($prefix !== $existing->code_prefix) {
                    $used = Procedure::where('code', 'like', $existing->code_prefix . '-%')->count();
                    if ($used > 0) {
                        $errors[] = "Baris {$lineNum}: prefix '{$existing->code_prefix}' dipakai {$used} tindakan, prefix tidak diubah";
                    } else {
                        // Pastikan prefix baru tidak bentrok kategori lain.
                        $clash = ProcedureCategory::withTrashed()
                            ->where('code_prefix', $prefix)
                            ->where('id', '!=', $existing->id)
                            ->exists();
                        if ($clash) {
                            $errors[] = "Baris {$lineNum}: prefix '{$prefix}' sudah dipakai kategori lain, prefix tidak diubah";
                        } else {
                            $payload['code_prefix'] = $prefix;
                        }
                    }
                }
                $existing->update($payload);
                $updated++;
            } else {
                // Cek bentrok prefix sebelum create.
                $clash = ProcedureCategory::withTrashed()->where('code_prefix', $prefix)->first();
                if ($clash) {
                    if ($clash->trashed()) {
                        // Reuse baris trashed yang prefix-nya sama: jadikan kategori ini.
                        $clash->restore();
                        $clash->update(['name' => $nama, 'description' => $desc, 'is_active' => $aktif]);
                        $updated++;
                    } else {
                        $errors[] = "Baris {$lineNum}: prefix '{$prefix}' sudah dipakai kategori '{$clash->name}'";
                        $skipped++;
                    }
                    continue;
                }
                ProcedureCategory::create([
                    'name' => $nama, 'code_prefix' => $prefix, 'description' => $desc, 'is_active' => $aktif,
                ]);
                $inserted++;
            }
        }

        $this->log(auth('api')->id(), 'IMPORT_PROC_CATEGORY_CSV', ProcedureCategory::class, null, "new:{$inserted} upd:{$updated} skip:{$skipped}");
        return compact('inserted', 'updated', 'skipped', 'errors');
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
        $code = $this->upsertIcdRow(Icd10Code::class, $data);
        $this->log(auth('api')->id(), 'CREATE_ICD10', Icd10Code::class, $code->id);
        return $code;
    }

    /**
     * Tambah/restore satu kode ICD yang AMAN terhadap soft-delete.
     *
     * Tabel icd10_codes/icd9_codes pakai SoftDeletes + unique index PLAIN pada `code`
     * tanpa filter deleted_at. `create()` biasa hanya melihat baris non-trashed → bila
     * kode pernah dihapus (soft) lalu ditambah ulang, INSERT kena unique violation
     * (23505) → 500. Helper ini cek withTrashed: kalau baris trashed → restore + update;
     * kalau aktif → tetap blok (caller/validasi yang menolak). Pola sama upsertTarifRow.
     */
    private function upsertIcdRow(string $model, array $data): mixed
    {
        $existing = $model::withTrashed()->where('code', $data['code'])->first();

        if ($existing) {
            if (! $existing->trashed()) {
                // Kode aktif sudah ada — biarkan unique violation/validasi yang menolak.
                throw new \Exception("Kode '{$data['code']}' sudah terdaftar.", 422);
            }
            $existing->restore();
            $existing->update($data);
            return $existing;
        }

        return $model::create($data);
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
        $code = $this->upsertIcdRow(Icd9Code::class, $data);
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

    private function generateSupplierCode(): string
    {
        $last = Supplier::withTrashed()
            ->where('code', 'like', 'SUP-%')
            ->orderByDesc('code')
            ->value('code');
        $next = 1;
        if ($last && preg_match('/^SUP-(\d+)$/', $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }
        return sprintf('SUP-%03d', $next);
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
        // Sertakan kolom on_hand (inventory_stocks, sumber stok tunggal per-tipe).
        $query = IolItem::withOnHand();
        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('iol_items.brand', 'ilike', "%{$kw}%")
                ->orWhere('iol_items.model', 'ilike', "%{$kw}%")
                ->orWhere('iol_items.manufacturer', 'ilike', "%{$kw}%")
                ->orWhere('iol_items.serial_number', 'ilike', "%{$kw}%")
                ->orWhere('iol_items.lot_number', 'ilike', "%{$kw}%")
                ->orWhere('iol_items.gtin', 'ilike', "%{$kw}%")
            );
        }
        if (! empty($filters['iol_type'])) {
            $query->where('iol_items.iol_type', $filters['iol_type']);
        }
        if (! empty($filters['material'])) {
            $query->where('iol_items.material', $filters['material']);
        }
        if (isset($filters['active'])) {
            $query->where('iol_items.is_active', (bool) $filters['active']);
        }
        // available_only (per-tipe): aktif & on_hand > 0 (BUKAN lagi is_used/stock legacy).
        if (! empty($filters['available_only'])) {
            $query->where('iol_items.is_active', true)
                ->whereRaw('COALESCE(iol_stock.qty, 0) > 0');
        }
        return $query->orderBy('iol_items.brand')->paginate($filters['per_page'] ?? 25);
    }

    public function storeIol(array $data): IolItem
    {
        // Stok BUKAN ke kolom legacy iol_items.stock (tak otoritatif) — seed ke
        // inventory_stocks (INVENTORI) bila form/klien mengirim stok awal > 0.
        // Konsisten dgn importIolCsv(); sumber stok tunggal = inventory_stocks.
        $stockQty = isset($data['stock']) ? (int) $data['stock'] : 0;
        unset($data['stock']);

        $iol = IolItem::create($data);
        if ($stockQty > 0) {
            $this->seedIolStock($iol, $stockQty, $data['expiry_date'] ?? null);
        }
        $this->log(auth('api')->id(), 'CREATE_IOL', IolItem::class, $iol->id);
        return $iol;
    }

    public function updateIol(string $id, array $data): IolItem
    {
        // Sama dgn storeIol: jangan tulis kolom legacy stock. Bila klien mengirim
        // stok, seed/tambah ke inventory_stocks (bukan set ulang — penyesuaian stok
        // sebenarnya lewat opname/penerimaan, bukan form master).
        $stockQty = isset($data['stock']) ? (int) $data['stock'] : 0;
        unset($data['stock']);

        $iol = IolItem::findOrFail($id);
        $iol->update($data);
        if ($stockQty > 0) {
            $this->seedIolStock($iol, $stockQty, $data['expiry_date'] ?? null);
        }
        $this->log(auth('api')->id(), 'UPDATE_IOL', IolItem::class, $id);
        return $iol->fresh();
    }

    /** Tambah stok awal IOL ke inventory_stocks lokasi INVENTORI (batch generik NULL). */
    private function seedIolStock(IolItem $iol, int $qty, $expiry = null): void
    {
        if ($qty <= 0) return;
        app(\App\Services\InventoryStockService::class)->upsertStock(
            \App\Models\InventoryStock::TYPE_IOL,
            (string) $iol->id,
            \App\Models\InventoryStock::LOC_INVENTORI,
            null,
            $qty,
            ($expiry !== null && $expiry !== '') ? $expiry : null,
        );
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
        if (! empty($filters['package_type'])) {
            $query->where('package_type', $filters['package_type']);
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

        $values = ['price' => $data['price'], 'is_active' => $data['is_active'] ?? true];

        // Pos kwitansi obat: 1 obat = 1 pos tetap → set di baris ini DAN propagasi ke
        // semua baris tarif obat tsb (lintas penjamin) agar invariant terjaga (kasir
        // baca pos dari baris UMUM). Hanya berlaku type 'obat'.
        if ($type === 'obat' && array_key_exists('pos_kwitansi', $data) && $data['pos_kwitansi']) {
            $values['pos_kwitansi'] = $data['pos_kwitansi'];
        }

        $tariff = $this->upsertTarifRow($model, $fk, $data[$fk], $data['insurer_id'], $values);

        if ($type === 'obat' && ! empty($values['pos_kwitansi'])) {
            $this->propagatePosKwitansi($data[$fk], $values['pos_kwitansi']);
        }

        $this->log(auth('api')->id(), 'UPSERT_TARIF', $model, $tariff->id, "type:{$type}");

        return $tariff;
    }

    /**
     * Samakan pos_kwitansi di SEMUA baris tarif satu obat (lintas penjamin, termasuk
     * baris soft-deleted) → menjaga invariant "1 obat = 1 pos" walau kasir hanya membaca
     * baris UMUM. Dipanggil saat store/update tarif obat dengan pos_kwitansi.
     */
    private function propagatePosKwitansi(string $medicationId, string $pos): void
    {
        \App\Models\MedicationTariff::withTrashed()
            ->where('medication_id', $medicationId)
            ->update(['pos_kwitansi' => $pos]);
    }

    /**
     * Upsert satu baris tarif (item × insurer) yang AMAN terhadap soft-delete.
     *
     * Tabel tarif pakai SoftDeletes + unique index PLAIN (item_id, insurer_id) tanpa
     * filter deleted_at. `updateOrCreate` biasa hanya melihat baris non-trashed → saat
     * baris pernah dihapus (soft) lalu di-tambah ulang, ia mencoba INSERT dan kena
     * unique violation (23505) → 500. Helper ini cek withTrashed: kalau ada baris
     * trashed → restore + update; else updateOrCreate normal.
     */
    private function upsertTarifRow(string $model, string $fk, string $itemId, ?string $insurerId, array $values): mixed
    {
        $existing = $model::withTrashed()
            ->where($fk, $itemId)
            ->where('insurer_id', $insurerId)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($values);
            return $existing;
        }

        return $model::create([$fk => $itemId, 'insurer_id' => $insurerId] + $values);
    }

    public function updateTarif(string $type, string $id, array $data): mixed
    {
        $model  = $this->tariffModel($type);
        $tariff = $model::findOrFail($id);

        $values = ['price' => $data['price'], 'is_active' => $data['is_active'] ?? $tariff->is_active];
        if ($type === 'obat' && array_key_exists('pos_kwitansi', $data) && $data['pos_kwitansi']) {
            $values['pos_kwitansi'] = $data['pos_kwitansi'];
        }
        $tariff->update($values);

        // Propagasi pos ke semua baris tarif obat ini (invariant 1 obat = 1 pos).
        if ($type === 'obat' && ! empty($values['pos_kwitansi'])) {
            $this->propagatePosKwitansi($tariff->medication_id, $values['pos_kwitansi']);
        }

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

            $existing = $model::withTrashed()->where($fk, $item->id)->where('insurer_id', $insurerId)->first();
            $this->upsertTarifRow(
                $model,
                $fk,
                $item->id,
                $insurerId,
                ['price' => (float) $hargaJual, 'is_active' => true]
            );
            if ($existing) $updated++; else $inserted++;
        }

        $this->log(auth('api')->id(), 'IMPORT_TARIF_CSV', null, null, "type:{$type} insurer:{$insurerId} new:{$inserted} upd:{$updated} skip:{$skipped}");

        return compact('inserted', 'updated', 'skipped', 'errors');
    }

    /**
     * Export tarif SEMUA penjamin (lossless) untuk satu type.
     * Header: no, nama, kategori, penjamin, harga_master, harga_jual.
     * Dipakai oleh GET /master/tarif/{type}/export-csv (insurer-less).
     */
    public function exportTarifCsv(string $type): string
    {
        $itemTable    = $this->itemTable($type);
        $itemNameCol  = $this->itemNameColumn($type);
        $itemCatCol   = $this->itemKategoriColumn($type);
        $itemPriceCol = $this->itemMasterPriceColumn($type);

        $rows = DB::table($this->tariffTable($type) . ' as t')
            ->join("{$itemTable} as item", "t.{$this->tariffFk($type)}", '=', 'item.id')
            ->leftJoin('insurers as ins', 't.insurer_id', '=', 'ins.id')
            ->whereNull('t.deleted_at')
            ->whereNull('item.deleted_at')   // jangan ekspor tarif item master yang sudah dihapus
            ->select([
                "item.{$itemNameCol} as nama",
                "item.{$itemCatCol} as kategori",
                'ins.name as penjamin',
                "item.{$itemPriceCol} as harga_master",
                't.price as harga_jual',
            ])
            ->orderBy("item.{$itemNameCol}")
            ->orderBy('ins.name')
            ->get();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['no', 'nama', 'kategori', 'penjamin', 'harga_master', 'harga_jual'], ',', '"', '\\');
        $no = 1;
        foreach ($rows as $row) {
            fputcsv($output, [
                $no++,
                $row->nama,
                $row->kategori,
                $row->penjamin ?? 'SEMUA',
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
     * Import tarif SEMUA penjamin dari CSV.
     * Header wajib (case-insensitive): nama, kategori, penjamin, harga_jual.
     * Item di-lookup via (nama, kategori); penjamin via name; upsert by (item_id, insurer_id).
     */
    public function importTarifCsv(string $type, string $csvContent): array
    {
        $lines = $this->csvDataLines($csvContent);
        if (empty($lines)) {
            throw new \Exception('File CSV kosong.', 422);
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));
        foreach (['nama', 'kategori', 'penjamin', 'harga_jual'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw new \Exception("Header CSV harus mengandung kolom '{$required}'.", 422);
            }
        }

        $model       = $this->tariffModel($type);
        $fk          = $this->tariffFk($type);
        $itemTable   = $this->itemTable($type);
        $itemNameCol = $this->itemNameColumn($type);
        $itemCatCol  = $this->itemKategoriColumn($type);

        $inserted = 0; $updated = 0; $skipped = 0; $errors = [];

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

            $nama      = trim((string) ($row['nama'] ?? ''));
            $kategori  = trim((string) ($row['kategori'] ?? ''));
            $penjamin  = trim((string) ($row['penjamin'] ?? ''));
            $hargaJual = $row['harga_jual'] ?? '';

            if ($nama === '' || $kategori === '' || $penjamin === '' || $hargaJual === '') {
                $errors[] = "Baris {$lineNum}: 'nama', 'kategori', 'penjamin', atau 'harga_jual' kosong";
                $skipped++;
                continue;
            }

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

            $insurer = DB::table('insurers')
                ->whereRaw('LOWER(name) = ?', [strtolower($penjamin)])
                ->whereNull('deleted_at')
                ->first();
            if (! $insurer) {
                $errors[] = "Baris {$lineNum}: penjamin '{$penjamin}' tidak ditemukan";
                $skipped++;
                continue;
            }

            $existing = $model::where($fk, $item->id)->where('insurer_id', $insurer->id)->first();
            $model::updateOrCreate(
                [$fk => $item->id, 'insurer_id' => $insurer->id],
                ['price' => (float) $hargaJual, 'is_active' => true]
            );
            if ($existing) $updated++; else $inserted++;
        }

        $this->log(auth('api')->id(), 'IMPORT_TARIF_CSV', null, null, "type:{$type} all-insurer new:{$inserted} upd:{$updated} skip:{$skipped}");

        // 'imported' = alias agar pesan controller (yang memakai key 'imported') tetap valid.
        $imported = $inserted + $updated;
        return compact('imported', 'inserted', 'updated', 'skipped', 'errors');
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
                // Master = identitas/atribut item SAJA. Harga JUAL dikelola di Buku Tarif
                // (medication_tariffs/bhp_tariffs/iol_tariffs baris UMUM); stok per-batch
                // di Penerimaan/Stock Opname (inventory_stocks). Kolom legacy price/stock/
                // min_stock/expiry_date/batch_number SENGAJA tidak diekspor/diimpor.
                'columns'   => ['code', 'kfa_code', 'name', 'generic_name', 'composition', 'manufacturer', 'formularium', 'form_sediaan', 'golongan', 'unit_besar', 'unit_kecil', 'konversi', 'description', 'is_active'],
                'casts'     => ['konversi' => 'int', 'is_active' => 'bool'],
            ],
            'bhp' => [
                'table'     => 'bhp_items',
                'model'     => BhpItem::class,
                'uniqueKey' => 'code',
                // Lihat catatan 'obat': harga & stok dikelola di modulnya, bukan master.
                'columns'   => ['code', 'name', 'category', 'unit', 'manufacturer', 'description', 'is_active'],
                'casts'     => ['is_active' => 'bool'],
            ],
            'iol' => [
                'table'     => 'iol_items',
                'model'     => IolItem::class,
                // Per-tipe: identitas master = (brand,model,power) — di-handle di importIolCsv().
                // uniqueKey hanya dipakai utk orderBy export; pakai 'brand' (serial sering kosong).
                // Lihat catatan 'obat': harga & stok dikelola di modulnya, bukan master.
                'uniqueKey' => 'brand',
                'columns'   => ['brand', 'manufacturer', 'model', 'iol_type', 'material', 'power', 'cylinder', 'axis', 'gtin', 'lot_number', 'serial_number', 'gs1_barcode', 'is_active'],
                'casts'     => ['power' => 'float', 'cylinder' => 'float', 'axis' => 'int', 'is_active' => 'bool'],
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
            'supplier' => [
                'table'     => 'suppliers',
                'model'     => Supplier::class,
                'uniqueKey' => 'code', // export orderBy; import by-name (code auto-gen SUP-NNN)
                'columns'   => ['code', 'name', 'contact_person', 'phone', 'email', 'npwp', 'address', 'is_active'],
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
        // Buang BOM UTF-8 (Excel "Save as CSV") agar header kolom pertama tak rusak.
        if (str_starts_with($csvContent, "\xEF\xBB\xBF")) {
            $csvContent = substr($csvContent, 3);
        }
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
                'Harga & stok TIDAK di sini: harga di menu Penentuan Harga, stok di Penerimaan/Stock Opname.',
                'Contoh baris: Kasa Steril 10x10,MEDICAL_BHP,pcs,Onemed,Catatan,1',
            ]),
            'obat' => array_merge($common, [
                'Kolom "code" dibuat otomatis (MED-001, ...) untuk item baru — tidak perlu diisi.',
                'Kolom "kfa_code" = kode KFA Kemenkes (untuk Satu Sehat), boleh kosong. Isi dari menu Inventori Farmasi (tombol Cari KFA) atau ketik manual.',
                'Kolom "konversi" = angka bulat. Harga & stok TIDAK di sini: harga di menu Penentuan Harga, stok di Penerimaan/Stock Opname.',
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
                'serial_number OPSIONAL (untuk unit fisik tertentu). power/cylinder = angka, axis = bulat.',
                'Harga & stok TIDAK di sini: harga di menu Penentuan Harga, stok di Penerimaan/Stock Opname.',
                'Kolom "iol_type": MONOFOCAL | MULTIFOCAL | TORIC | TRIFOCAL | EDOF | PHAKIC.',
                'Kolom "is_active": 1 = aktif, 0 = nonaktif.',
            ],
            'supplier' => array_merge($common, [
                'Kolom "code" dibuat otomatis (SUP-001, SUP-002, ...) untuk supplier baru — tidak perlu diisi.',
                'Kolom opsional: contact_person, phone, email, npwp, address.',
                'Contoh baris: PT. Kimia Farma Distributor,Budi,021-555111,sales@kf.co.id,01.234.567.8-901.000,Jl. Veteran No.1,1',
            ]),
            default => $common,
        };
    }

    /**
     * Kolom CSV yang dipakai untuk template & export.
     * obat/bhp: kode di-exclude (auto-gen MED-/BHP-NNN di backend; identifier upsert = name).
     */
    private function csvHeaderColumns(string $type, array $schema): array
    {
        if (in_array($type, ['obat', 'bhp', 'alat-medis', 'supplier'], true)) {
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
        $orderBy = in_array($type, ['obat', 'bhp', 'alat-medis', 'supplier'], true) ? 'name' : $schema['uniqueKey'];

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

        if (in_array($type, ['obat', 'bhp', 'alat-medis', 'supplier'], true)) {
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
                // withTrashed: bila baris pernah di-soft-delete, default scope tak
                // melihatnya → create() kena unique violation (23505). Lookup trashed
                // lalu restore agar re-import kode yang pernah dihapus tetap jalan.
                $usesSoftDeletes = in_array(
                    \Illuminate\Database\Eloquent\SoftDeletes::class,
                    class_uses_recursive($model),
                    true
                );

                $query    = $usesSoftDeletes ? $model::withTrashed() : $model::query();
                $existing = $query->where($unique, $data[$unique])->first();

                if ($existing) {
                    if ($usesSoftDeletes && $existing->trashed()) {
                        $existing->restore();
                    }
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
                            'supplier'   => $this->generateSupplierCode(),
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

            // Kolom `stock`/`expiry_date` TIDAK lagi bagian dari schema master IOL
            // (tak diekspor) — tapi import MASIH menerimanya sebagai stok AWAL bila
            // admin menyertakannya di file. Sumber stok sebenarnya = inventory_stocks,
            // jadi nilai itu di-seed ke lokasi INVENTORI via seedIolStock(), BUKAN
            // ditulis ke kolom legacy. Dibaca langsung dari $row (bukan $data) karena
            // sudah dikeluarkan dari schema['columns'].
            $rawStock = $row['stock'] ?? '';
            $stockQty = ($rawStock !== '' && is_numeric($rawStock)) ? (int) $rawStock : 0;
            $rawExpiry = trim((string) ($row['expiry_date'] ?? ''));
            $expiry  = $rawExpiry !== '' ? $rawExpiry : null;

            try {
                $existing = IolItem::whereRaw('LOWER(brand) = ?', [strtolower($brand)])
                    ->whereRaw('LOWER(model) = ?', [strtolower($model)])
                    ->where('power', (float) $power)
                    ->first();

                if ($existing) {
                    $existing->update($data);
                    $iolItem = $existing;
                    $updated++;
                } else {
                    $iolItem = IolItem::create($data);
                    $inserted++;
                }

                // Seed stok awal ke inventory_stocks (INVENTORI) bila CSV menyertakan stok > 0.
                $this->seedIolStock($iolItem, $stockQty, $expiry);
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

    public function storeNomorDokumen(array $data): DocumentNumberConfig
    {
        $code = strtoupper($data['document_type_code']);

        // DB punya unique index pada document_type_code (termasuk baris soft-deleted),
        // jadi kode yang pernah dihapus tak bisa di-insert ulang. Pulihkan & timpa
        // baris lama bila ada, agar tak melanggar constraint.
        $config = DocumentNumberConfig::withTrashed()
            ->where('document_type_code', $code)
            ->first();

        $payload = [
            'document_type_code' => $code,
            'format'             => $data['format'],
            'prefix'             => $data['prefix'] ?? null,
            'reset_period'       => $data['reset_period'],
            'seq_length'         => $data['seq_length'],
            'last_seq'           => 0,
        ];

        if ($config) {
            $config->restore();
            $config->update($payload);
        } else {
            $config = DocumentNumberConfig::create($payload);
        }

        $this->log(auth('api')->id(), 'CREATE_DOC_NUMBER_CONFIG', DocumentNumberConfig::class, $config->id);

        return $config;
    }

    public function updateNomorDokumen(string $id, array $data): DocumentNumberConfig
    {
        $config = DocumentNumberConfig::findOrFail($id);

        // format/reset_period/seq_length sudah `required` di controller → set
        // langsung. prefix opsional: array_key_exists membedakan "tidak dikirim"
        // (jangan sentuh) vs "dikirim null" (clear). document_type_code immutable.
        $update = [
            'format'       => $data['format'],
            'reset_period' => $data['reset_period'],
            'seq_length'   => $data['seq_length'],
        ];
        if (array_key_exists('prefix', $data)) {
            $update['prefix'] = $data['prefix'];
        }
        $config->update($update);

        $this->log(auth('api')->id(), 'UPDATE_DOC_NUMBER_CONFIG', DocumentNumberConfig::class, $id);

        return $config->fresh();
    }

    public function destroyNomorDokumen(string $id): void
    {
        $config = DocumentNumberConfig::findOrFail($id);
        $config->delete();

        $this->log(auth('api')->id(), 'DELETE_DOC_NUMBER_CONFIG', DocumentNumberConfig::class, $id);
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
