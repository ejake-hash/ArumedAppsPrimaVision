<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserService
{
    public function getAll(array $filters = []): array
    {
        $q = User::with(['role:id,name,display_name', 'employee:id,name,profession,nip']);

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(fn ($qq) => $qq
                ->where('name', 'ilike', "%{$s}%")
                ->orWhere('username', 'ilike', "%{$s}%")
                ->orWhere('email', 'ilike', "%{$s}%")
            );
        }

        if (! empty($filters['role_id'])) {
            $q->where('role_id', $filters['role_id']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', (bool) $filters['is_active']);
        }

        return $q->orderBy('name')->get()->map(fn ($u) => $this->format($u))->toArray();
    }

    public function getById(string $id): array
    {
        $user = User::with(['role:id,name,display_name', 'employee:id,name,profession,nip'])
            ->findOrFail($id);

        return $this->format($user);
    }

    public function create(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            // Validasi role exists
            $role = Role::findOrFail($data['role_id']);

            // Generate password kalau tidak diisi
            $password = $data['password'] ?? Str::random(10);

            $user = User::create([
                'name'        => $data['name'],
                'username'    => $data['username'],
                'email'       => $data['email'],
                'role_id'     => $role->id,
                'employee_id' => $data['employee_id'] ?? null,
                'password'    => $password,    // auto-hash via cast
                'pin'         => ! empty($data['pin']) ? $data['pin'] : null,
                'is_active'   => $data['is_active'] ?? true,
            ]);

            return $user;
        });

        return $this->getById($user->id);
    }

    public function update(string $id, array $data): array
    {
        DB::transaction(function () use ($id, $data) {
            $user = User::findOrFail($id);

            $payload = array_filter([
                'name'        => $data['name']     ?? null,
                'username'    => $data['username'] ?? null,
                'email'       => $data['email']    ?? null,
                'role_id'     => $data['role_id']  ?? null,
                'employee_id' => array_key_exists('employee_id', $data) ? $data['employee_id'] : null,
                'is_active'   => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
            ], fn ($v) => $v !== null);

            // employee_id boleh di-set null secara eksplisit
            if (array_key_exists('employee_id', $data) && $data['employee_id'] === null) {
                $payload['employee_id'] = null;
            }

            // pin: key hadir = ubah. '' / null = hapus PIN, angka = set PIN.
            if (array_key_exists('pin', $data)) {
                $payload['pin'] = ($data['pin'] === '' || $data['pin'] === null) ? null : $data['pin'];
            }

            $user->update($payload);
        });

        return $this->getById($id);
    }

    public function delete(string $id, ?User $actor = null): void
    {
        $user = User::findOrFail($id);

        if ($actor && $actor->id === $user->id) {
            throw new \Exception('Tidak bisa menghapus akun sendiri.', 422);
        }

        if ($user->isSuperadmin()) {
            // Cek apakah masih ada Superadmin lain
            $otherSuperadmin = User::where('id', '!=', $id)
                ->whereHas('role', fn ($q) => $q->where('name', 'superadmin'))
                ->exists();
            if (! $otherSuperadmin) {
                throw new \Exception('Tidak bisa menghapus Superadmin terakhir.', 422);
            }
        }

        $user->delete();
    }

    public function toggleAktif(string $id, ?User $actor = null): array
    {
        $user = User::findOrFail($id);

        if ($actor && $actor->id === $user->id) {
            throw new \Exception('Tidak bisa menonaktifkan akun sendiri.', 422);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return $this->getById($id);
    }

    public function resetPassword(string $id, ?string $newPassword = null): string
    {
        $user = User::findOrFail($id);
        $generated = $newPassword ?? Str::random(10);

        $user->update(['password' => $generated]);   // auto-hash

        return $generated;
    }

    /**
     * Reset PIN tanda tangan: generate PIN 6 digit acak, simpan, kembalikan
     * nilai baru (ditampilkan sekali ke superadmin). PIN lama tidak diekspos.
     */
    public function resetPin(string $id): string
    {
        $user = User::findOrFail($id);
        $generated = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->update(['pin' => $generated]);

        return $generated;
    }

    // ─── CSV: Template / Export / Import ──────────────────────────────────────

    /** Kolom CSV data pengguna. Password sengaja tidak ada (auto-generate saat import). */
    private const CSV_COLUMNS = ['name', 'username', 'email', 'role', 'is_active', 'nip'];

    /**
     * Template CSV kosong + baris petunjuk (diawali '#', di-skip importer).
     */
    public function csvTemplate(): string
    {
        $output = fopen('php://temp', 'r+');

        foreach ($this->csvTemplateNotes() as $note) {
            fwrite($output, '# ' . $note . "\n");
        }

        fputcsv($output, self::CSV_COLUMNS, ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    private function csvTemplateNotes(): array
    {
        $roleCodes = Role::orderBy('name')->pluck('name')->all();

        return [
            'PETUNJUK PENGISIAN — baris diawali "#" diabaikan saat import (boleh dibiarkan/dihapus).',
            'Kolom WAJIB: name, username, email, role. Username & email harus unik.',
            'Kolom "role" diisi KODE role (bukan nama tampilan). Pilihan: ' . implode(' | ', $roleCodes) . '.',
            'Kolom "is_active": 1 = aktif, 0 = nonaktif (kosong dianggap aktif).',
            'Kolom "nip" opsional: NIP pegawai untuk menautkan akun ke data pegawai (kosongkan jika tidak ada).',
            'Password TIDAK diisi di sini — sistem membuat password acak per user dan menampilkannya setelah import.',
            'Baris dengan username/email yang sudah terdaftar akan dilewati (dilaporkan di ringkasan).',
            'Contoh baris: Budi Santoso,budi,budi@klinik.com,admisi,1,',
        ];
    }

    /** Export seluruh user ke CSV (password tidak diekspor). */
    public function exportCsv(): string
    {
        $users = User::with(['role:id,name', 'employee:id,nip'])->orderBy('name')->get();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, self::CSV_COLUMNS, ',', '"', '\\');

        foreach ($users as $u) {
            fputcsv($output, [
                $u->name,
                $u->username,
                $u->email,
                $u->role?->name ?? '',
                $u->is_active ? 1 : 0,
                $u->employee?->nip ?? '',
            ], ',', '"', '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Import CSV: hanya menambah user baru. Baris dengan username/email yang
     * sudah ada di-skip dan dilaporkan. Password digenerate acak per user dan
     * dikembalikan agar bisa dibagikan ke pengguna.
     *
     * @return array{created: array, skipped: array, errors: array}
     */
    public function importCsv(string $csvContent): array
    {
        $lines = $this->csvDataLines($csvContent);
        if (count($lines) < 2) {
            throw new \Exception('File CSV kosong atau hanya berisi header.', 422);
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));
        $idx = array_flip($headers);

        foreach (['name', 'username', 'email', 'role'] as $req) {
            if (! isset($idx[$req])) {
                throw new \Exception("Kolom wajib \"{$req}\" tidak ditemukan di header CSV.", 422);
            }
        }

        // Lookup role by name (case-insensitive) & employee by nip — sekali query.
        $roleByName = Role::all()->keyBy(fn ($r) => strtolower($r->name));

        $created = [];
        $skipped = [];
        $errors  = [];

        foreach ($lines as $n => $line) {
            $rowNo  = $n + 2; // +1 header sudah di-shift, +1 supaya 1-based menghitung header
            $values = str_getcsv($line, ',', '"', '\\');
            $get = fn ($key) => isset($idx[$key]) ? trim($values[$idx[$key]] ?? '') : '';

            $name     = $get('name');
            $username = $get('username');
            $email    = $get('email');
            $roleName = $get('role');
            $nip      = $get('nip');
            $isActive = $get('is_active');

            if ($name === '' || $username === '' || $email === '' || $roleName === '') {
                $errors[] = ['row' => $rowNo, 'reason' => 'name/username/email/role wajib diisi'];
                continue;
            }

            $role = $roleByName->get(strtolower($roleName));
            if (! $role) {
                $errors[] = ['row' => $rowNo, 'username' => $username, 'reason' => "Role \"{$roleName}\" tidak ditemukan"];
                continue;
            }

            if (User::where('username', $username)->orWhere('email', $email)->exists()) {
                $skipped[] = ['row' => $rowNo, 'username' => $username, 'reason' => 'Username/email sudah terdaftar'];
                continue;
            }

            $employeeId = null;
            if ($nip !== '') {
                $emp = Employee::where('nip', $nip)->first();
                if (! $emp) {
                    $errors[] = ['row' => $rowNo, 'username' => $username, 'reason' => "NIP \"{$nip}\" tidak ditemukan"];
                    continue;
                }
                $employeeId = $emp->id;
            }

            $password = Str::random(10);

            try {
                DB::transaction(function () use ($name, $username, $email, $role, $employeeId, $isActive, $password) {
                    User::create([
                        'name'        => $name,
                        'username'    => $username,
                        'email'       => $email,
                        'role_id'     => $role->id,
                        'employee_id' => $employeeId,
                        'password'    => $password,   // auto-hash via cast
                        'is_active'   => $isActive === '' ? true : (bool) (int) $isActive,
                    ]);
                });

                $created[] = ['name' => $name, 'username' => $username, 'email' => $email, 'password' => $password];
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNo, 'username' => $username, 'reason' => 'Gagal simpan: ' . $e->getMessage()];
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Pecah CSV jadi baris data: buang \r, baris kosong, dan baris komentar ('#').
     * Hasil di-reindex 0-based (elemen pertama = header).
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

    private function format(User $u): array
    {
        return [
            'id'             => $u->id,
            'name'           => $u->name,
            'username'       => $u->username,
            'email'          => $u->email,
            'is_active'      => $u->is_active,
            'has_pin'        => ! empty($u->pin),
            'last_login_at'  => $u->last_login_at,
            'role'           => $u->role ? [
                'id'           => $u->role->id,
                'name'         => $u->role->name,
                'display_name' => $u->role->display_name,
            ] : null,
            'employee'       => $u->employee ? [
                'id'         => $u->employee->id,
                'name'       => $u->employee->name,
                'nip'        => $u->employee->nip,
                'profession' => $u->employee->profession,
            ] : null,
            'created_at'     => $u->created_at,
            'updated_at'     => $u->updated_at,
        ];
    }
}
