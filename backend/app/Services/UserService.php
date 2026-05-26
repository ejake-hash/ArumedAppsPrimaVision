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

    private function format(User $u): array
    {
        return [
            'id'             => $u->id,
            'name'           => $u->name,
            'username'       => $u->username,
            'email'          => $u->email,
            'is_active'      => $u->is_active,
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
