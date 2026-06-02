<?php

namespace App\Services;

use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class AuthService
{
    public function __construct(private readonly Request $request) {}

    /**
     * Authenticate user, return JWT token + user data.
     *
     * @throws \Exception on invalid credentials or inactive account
     */
    public function login(string $username, string $password): array
    {
        // Load minimal dulu (role + employee). Permissions di-load setelah
        // login berhasil, supaya credential salah tidak menyentuh tabel permissions.
        $user = User::with(['role', 'employee'])
            ->where('username', $username)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->log(null, 'LOGIN_FAILED', description: "Gagal login: username {$username}");
            throw new \Exception('Username atau password salah.', 401);
        }

        if (! $user->is_active) {
            $this->log($user->id, 'LOGIN_BLOCKED', description: 'Akun tidak aktif');
            throw new \Exception('Akun tidak aktif. Hubungi administrator.', 403);
        }

        // Token kedaluwarsa tepat jam 24.00 WIB (bukan 60 menit setelah login).
        auth('api')->factory()->setTTL($this->minutesUntilMidnight());
        $token = auth('api')->login($user);

        $user->update(['last_login_at' => now()]);

        // Setelah login berhasil — try load permissions (kalau tabel sudah ada).
        try {
            $user->load('role.permissions:id,key');
        } catch (\Throwable) {
            // Tabel permissions belum di-migrate — abaikan, formatUser akan
            // return permissions: [] dan user tetap bisa login.
        }

        $this->log($user->id, 'LOGIN', description: "Login berhasil dari {$this->request->ip()}");

        return [
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user'       => $this->formatUser($user),
        ];
    }

    /**
     * Invalidate current JWT token.
     */
    public function logout(): void
    {
        $userId = auth('api')->id();

        auth('api')->logout();

        $this->log($userId, 'LOGOUT', description: 'Logout berhasil');
    }

    /**
     * Refresh the current JWT token.
     *
     * @throws \Exception on invalid/expired token
     */
    public function refresh(): array
    {
        try {
            // Token hasil refresh juga ikut kedaluwarsa jam 24.00 WIB.
            auth('api')->factory()->setTTL($this->minutesUntilMidnight());
            $token = auth('api')->refresh();
        } catch (TokenExpiredException) {
            throw new \Exception('Token sudah kadaluarsa, silakan login ulang.', 401);
        } catch (TokenInvalidException | JWTException) {
            throw new \Exception('Token tidak valid.', 401);
        }

        return [
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ];
    }

    /**
     * Return the currently authenticated user with role and employee.
     */
    public function me(): array
    {
        $user = auth('api')->user()->load(['role', 'employee']);

        try {
            $user->load('role.permissions:id,key');
        } catch (\Throwable) {
            // Migration permissions belum di-run — abaikan.
        }

        return $this->formatUser($user);
    }

    /**
     * Change the authenticated user's password.
     *
     * @throws \Exception on wrong current password
     */
    public function changePassword(string $currentPassword, string $newPassword): void
    {
        /** @var User $user */
        $user = auth('api')->user();

        if (! Hash::check($currentPassword, $user->password)) {
            throw new \Exception('Password lama tidak sesuai.', 422);
        }

        $user->update(['password' => Hash::make($newPassword)]);

        $this->log($user->id, 'PASSWORD_CHANGED', description: 'Password berhasil diubah');
    }

    /**
     * Daftar role yang boleh mengelola PIN tanda tangan sendiri.
     */
    private const DOCTOR_ROLES = ['dokter', 'dokter_anestesi', 'dokter_umum'];

    private function assertDoctor(User $user): void
    {
        $role = $user->role?->name;
        if (! in_array($role, self::DOCTOR_ROLES, true)) {
            throw new \Exception('Fitur ini hanya untuk akun dokter.', 403);
        }
    }

    /**
     * Ubah PIN tanda tangan milik dokter yang sedang login.
     * PIN disimpan PLAIN agar konsisten dengan DokterController::verifyPin
     * (hash_equals plain) dan UserService::resetPin.
     *
     * @throws \Exception bila bukan dokter atau password salah
     */
    public function changePin(string $currentPassword, string $newPin): void
    {
        /** @var User $user */
        $user = auth('api')->user();
        $this->assertDoctor($user);

        if (! Hash::check($currentPassword, $user->password)) {
            throw new \Exception('Password tidak sesuai.', 422);
        }

        $user->update(['pin' => $newPin]);

        $this->log($user->id, 'PIN_CHANGED', description: 'PIN tanda tangan diubah');
    }

    /**
     * Reset kredensial user yang sedang login KE DEFAULT (berlaku semua role):
     *   - password → 888888 (default akun stasiun)
     *   - pin      → NULL (khusus dokter; dokter wajib set ulang sebelum tanda tangan)
     */
    public function resetToDefault(): void
    {
        /** @var User $user */
        $user = auth('api')->user();

        $payload = ['password' => Hash::make('888888')];
        // PIN hanya relevan untuk akun dokter (tanda tangan). Kosongkan bila dokter.
        if (in_array($user->role?->name, self::DOCTOR_ROLES, true)) {
            $payload['pin'] = null;
        }

        $user->update($payload);

        $this->log($user->id, 'CREDENTIALS_RESET_DEFAULT',
            description: 'Password direset ke default (888888)'
                . (array_key_exists('pin', $payload) ? ' & PIN dikosongkan' : ''));
    }

    // -------------------------------------------------------------------------

    /**
     * Selisih menit dari sekarang sampai tengah malam (00:00) WIB berikutnya.
     *
     * Dipakai sebagai TTL JWT supaya token kedaluwarsa tepat jam 24.00 waktu
     * Cilegon, bukan 60 menit setelah login. app.timezone = UTC, jadi tengah
     * malam dihitung eksplisit di Asia/Jakarta. Minimal 1 menit untuk
     * menghindari TTL 0 saat login persis tengah malam.
     */
    private function minutesUntilMidnight(): int
    {
        $now      = now('Asia/Jakarta');
        $midnight = $now->copy()->startOfDay()->addDay();

        return max(1, (int) ceil($midnight->diffInSeconds($now, true) / 60));
    }

    private function formatUser(User $user): array
    {
        $isSuper = $user->isSuperadmin();

        // Permission keys — sentinel "*" untuk Superadmin (bypass di frontend).
        // Defensif: kalau tabel permissions/role_permissions belum di-migrate,
        // jangan crash — biarkan kosong.
        $permissionKeys = $isSuper ? ['*'] : [];
        if (! $isSuper) {
            try {
                $permissionKeys = $user->role?->permissions?->pluck('key')->toArray() ?? [];
            } catch (\Throwable) {
                $permissionKeys = [];
            }
        }

        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'username'      => $user->username,
            'email'         => $user->email,
            'is_active'     => $user->is_active,
            'is_superadmin' => $isSuper,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'role'          => $user->role ? [
                'id'           => $user->role->id,
                'name'         => $user->role->name,
                'display_name' => $user->role->display_name,
            ] : null,
            'employee'      => $user->employee ? [
                'id'         => $user->employee->id,
                'name'       => $user->employee->name,
                'profession' => $user->employee->profession,
                'sip'        => $user->employee->sip,
            ] : null,
            'permissions'   => $permissionKeys,
        ];
    }

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
