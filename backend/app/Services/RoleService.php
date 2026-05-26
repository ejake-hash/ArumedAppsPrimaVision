<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class RoleService
{
    /**
     * List semua role + permission keys (untuk matriks frontend).
     */
    public function getAll(): array
    {
        $roles = Role::with('permissions:id,key,module,action')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return $roles->map(fn ($r) => $this->format($r))->toArray();
    }

    public function getById(string $id): array
    {
        $role = Role::with('permissions:id,key,module,action')
            ->withCount('users')
            ->findOrFail($id);

        return $this->format($role);
    }

    public function create(array $data): array
    {
        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'name'         => strtolower($data['name']),
                'display_name' => $data['display_name'] ?? $data['name'],
                'description'  => $data['description']  ?? null,
                'guard_name'   => 'api',
                'is_active'    => $data['is_active']    ?? true,
            ]);

            if (! empty($data['permission_ids'])) {
                $role->permissions()->sync($data['permission_ids']);
            }

            return $role;
        });

        return $this->getById($role->id);
    }

    public function update(string $id, array $data): array
    {
        DB::transaction(function () use ($id, $data) {
            $role = Role::findOrFail($id);

            if ($role->isSuperadmin() && isset($data['name']) && $data['name'] !== 'superadmin') {
                throw new \Exception('Role Superadmin tidak boleh diganti namanya.', 422);
            }

            $role->update(array_filter([
                'name'         => isset($data['name']) ? strtolower($data['name']) : null,
                'display_name' => $data['display_name'] ?? null,
                'description'  => $data['description']  ?? null,
                'is_active'    => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
            ], fn ($v) => $v !== null));

            if (array_key_exists('permission_ids', $data)) {
                if ($role->isSuperadmin()) {
                    // Superadmin bypass via kode, ignore pivot edit.
                    $role->permissions()->detach();
                } else {
                    $role->permissions()->sync($data['permission_ids']);
                }
            }
        });

        return $this->getById($id);
    }

    public function delete(string $id): void
    {
        $role = Role::withCount('users')->findOrFail($id);

        if ($role->isSuperadmin()) {
            throw new \Exception('Role Superadmin tidak boleh dihapus.', 422);
        }

        if ($role->users_count > 0) {
            throw new \Exception("Role masih digunakan oleh {$role->users_count} user aktif.", 422);
        }

        $role->delete();
    }

    public function syncPermissions(string $id, array $permissionIds): array
    {
        $role = Role::findOrFail($id);

        if ($role->isSuperadmin()) {
            throw new \Exception('Permission Superadmin di-bypass via kode — tidak perlu di-set.', 422);
        }

        // Validasi semua id valid
        $valid = Permission::whereIn('id', $permissionIds)->pluck('id')->toArray();
        $role->permissions()->sync($valid);

        return $this->getById($id);
    }

    private function format(Role $r): array
    {
        return [
            'id'             => $r->id,
            'name'           => $r->name,
            'display_name'   => $r->display_name,
            'description'    => $r->description,
            'is_active'      => $r->is_active,
            'is_system'      => $r->isSuperadmin() || in_array($r->name, [
                'dokter','perawat','refraksionis','penunjang','farmasi','kasir','verifikator','admisi',
            ], true),
            'user_count'     => $r->users_count ?? 0,
            'permission_keys'=> $r->isSuperadmin()
                ? ['*']  // sentinel — Superadmin bypass
                : $r->permissions->pluck('key')->toArray(),
            'permissions'    => $r->permissions->map(fn ($p) => [
                'id'     => $p->id,
                'key'    => $p->key,
                'module' => $p->module,
                'action' => $p->action,
            ])->toArray(),
        ];
    }
}
