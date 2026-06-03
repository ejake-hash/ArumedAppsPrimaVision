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

    // ─── CSV / Excel: Template / Export / Import ──────────────────────────────

    /** Kolom CSV daftar role. `permissions` = daftar key dipisah '|'. */
    private const CSV_COLUMNS = ['name', 'display_name', 'description', 'is_active', 'permissions'];

    /** Role bawaan sistem — tidak dibuat ulang lewat import (tapi boleh di-update permission-nya). */
    private const SUPERADMIN = 'superadmin';

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
        $allKeys = Permission::active()->orderBy('key')->pluck('key')->all();
        // Tampilkan sebagian contoh key supaya baris petunjuk tidak kepanjangan.
        $sample  = array_slice($allKeys, 0, 8);

        return [
            'PETUNJUK PENGISIAN - baris diawali "#" diabaikan saat import (boleh dibiarkan/dihapus).',
            'Kolom WAJIB: name. "name" = KODE role internal (huruf kecil, tanpa spasi), dipakai sebagai kunci unik.',
            'Import bersifat UPSERT: jika "name" sudah ada -> role diperbarui; jika belum -> role dibuat baru.',
            'Kolom "is_active": 1 = aktif, 0 = nonaktif (kosong dianggap aktif).',
            'Kolom "permissions" = daftar KEY hak akses dipisah tanda "|". Contoh: admisi.read|admisi.write|kasir.read',
            '  Format key: <modul>.<read|write|delete>. Key yang tidak dikenal akan dilaporkan & diabaikan (baris tetap diproses).',
            '  Mengosongkan "permissions" saat update akan MENGHAPUS semua hak akses role tsb. Biarkan kolom apa adanya jika tak ingin mengubah.',
            '  Contoh key tersedia: ' . implode(' | ', $sample) . (count($allKeys) > count($sample) ? ' | ...' : ''),
            'Role "superadmin" otomatis bypass (akses penuh) - permission untuknya diabaikan saat import.',
            'Contoh: petugas_lab,Petugas Laboratorium,Akses modul penunjang,1,penunjang.read|penunjang.write',
        ];
    }

    /** Export seluruh role ke CSV. Superadmin diekspor dgn permissions = "*". */
    public function exportCsv(): string
    {
        $roles = Role::with('permissions:id,key')->orderBy('name')->get();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, self::CSV_COLUMNS, ',', '"', '\\');

        foreach ($roles as $r) {
            $perms = $r->isSuperadmin()
                ? '*'
                : $r->permissions->pluck('key')->sort()->implode('|');

            fputcsv($output, [
                $r->name,
                $r->display_name ?? '',
                $r->description ?? '',
                $r->is_active ? 1 : 0,
                $perms,
            ], ',', '"', '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Import CSV daftar role (UPSERT by name). Mengembalikan ringkasan per baris.
     *
     * @return array{created: array, updated: array, skipped: array, errors: array}
     */
    public function importCsv(string $csvContent): array
    {
        $lines = $this->csvDataLines($csvContent);
        if (count($lines) < 2) {
            throw new \Exception('File kosong atau hanya berisi header.', 422);
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));
        $idx = array_flip($headers);

        if (! isset($idx['name'])) {
            throw new \Exception('Kolom wajib "name" tidak ditemukan di header.', 422);
        }

        // Lookup permission key -> id (sekali query). Hanya permission aktif.
        $permByKey = Permission::active()->get(['id', 'key'])->keyBy(fn ($p) => strtolower($p->key));

        $created = [];
        $updated = [];
        $skipped = [];
        $errors  = [];

        foreach ($lines as $n => $line) {
            $rowNo  = $n + 2; // +1 header sudah di-shift, +1 supaya 1-based termasuk header
            $values = str_getcsv($line, ',', '"', '\\');
            $get = fn ($key) => isset($idx[$key]) ? trim($values[$idx[$key]] ?? '') : '';

            $name = strtolower($get('name'));
            if ($name === '') {
                $errors[] = ['row' => $rowNo, 'name' => '', 'reason' => 'Kolom "name" wajib diisi.'];
                continue;
            }

            // Superadmin: bypass via kode, jangan diutak-atik lewat import.
            if ($name === self::SUPERADMIN) {
                $skipped[] = ['row' => $rowNo, 'name' => $name, 'reason' => 'Role superadmin bypass — diabaikan.'];
                continue;
            }

            $displayName = $get('display_name') ?: $name;
            $description = $get('description');
            $isActiveRaw = $get('is_active');
            $isActive    = ($isActiveRaw === '' ) ? true : in_array(strtolower($isActiveRaw), ['1', 'true', 'ya', 'aktif', 'y'], true);

            // Parse permission keys (dipisah '|'), pisahkan yang dikenal vs tidak.
            $permRaw = $get('permissions');
            $permIds = [];
            $unknown = [];
            $hasPermColumn = isset($idx['permissions']);
            if ($permRaw !== '') {
                foreach (preg_split('/[|,;]+/', $permRaw) as $key) {
                    $key = strtolower(trim($key));
                    if ($key === '' || $key === '*') { continue; }
                    if (isset($permByKey[$key])) {
                        $permIds[] = $permByKey[$key]->id;
                    } else {
                        $unknown[] = $key;
                    }
                }
                $permIds = array_values(array_unique($permIds));
            }

            try {
                DB::transaction(function () use ($name, $displayName, $description, $isActive, $permIds, $hasPermColumn, $permRaw, &$created, &$updated, $rowNo, $unknown) {
                    $role = Role::where('name', $name)->first();
                    $isNew = ! $role;

                    if ($isNew) {
                        $role = Role::create([
                            'name'         => $name,
                            'display_name' => $displayName,
                            'description'  => $description ?: null,
                            'guard_name'   => 'api',
                            'is_active'    => $isActive,
                        ]);
                    } else {
                        $role->update([
                            'display_name' => $displayName,
                            'description'  => $description ?: null,
                            'is_active'    => $isActive,
                        ]);
                    }

                    // Sinkron permission hanya jika kolom permissions hadir di file.
                    // (Kolom tidak ada => jangan sentuh hak akses yang sudah ada.)
                    if ($hasPermColumn) {
                        $role->permissions()->sync($permIds);
                    }

                    $entry = ['row' => $rowNo, 'name' => $name, 'display_name' => $displayName];
                    if (! empty($unknown)) {
                        $entry['warning'] = 'Key tak dikenal diabaikan: ' . implode(', ', $unknown);
                    }
                    if ($isNew) {
                        $created[] = $entry;
                    } else {
                        $updated[] = $entry;
                    }
                });
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNo, 'name' => $name, 'reason' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
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
