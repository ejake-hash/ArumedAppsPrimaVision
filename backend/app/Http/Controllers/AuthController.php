<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $service) {}

    /**
     * Casting aman exception code → HTTP status.
     * PDOException dkk. return string SQLSTATE — harus di-clamp ke 400..599.
     */
    private function statusOf(\Throwable $e, int $fallback): int
    {
        $code = $e->getCode();
        $code = is_int($code) ? $code : (int) $code;
        return ($code >= 400 && $code < 600) ? $code : $fallback;
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $data = $this->service->login($validated['username'], $validated['password']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => null,
            ], $this->statusOf($e, 401));
        }

        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => 'Login berhasil',
            'errors'  => null,
        ]);
    }

    public function logout(): JsonResponse
    {
        $this->service->logout();

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Logout berhasil',
            'errors'  => null,
        ]);
    }

    public function refresh(): JsonResponse
    {
        try {
            $data = $this->service->refresh();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => null,
            ], $this->statusOf($e, 401));
        }

        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => 'Token diperbarui',
            'errors'  => null,
        ]);
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->me(),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        try {
            $this->service->changePassword(
                $validated['current_password'],
                $validated['new_password']
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => null,
            ], $this->statusOf($e, 422));
        }

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Password berhasil diubah',
            'errors'  => null,
        ]);
    }

    /**
     * PUT /auth/pin — dokter mengubah PIN tanda tangan sendiri.
     * Body: { current_password, pin (4-6 digit) }
     */
    public function changePin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'pin'              => 'required|string|min:4|max:6|regex:/^\d+$/',
            // Wajib saat MENGGANTI PIN yang sudah ada (verifikasi kepemilikan PIN, bukan
            // sekadar password login yang bisa default/diketahui). Opsional saat set awal.
            'current_pin'      => 'nullable|string',
        ]);

        try {
            $this->service->changePin($validated['current_password'], $validated['pin'], $validated['current_pin'] ?? null);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => null,
            ], $this->statusOf($e, 422));
        }

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'PIN berhasil diubah',
            'errors'  => null,
        ]);
    }

    /**
     * POST /auth/reset-to-default — dokter mereset password (→888888) & PIN (→kosong).
     */
    public function resetToDefault(): JsonResponse
    {
        try {
            $this->service->resetToDefault();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => null,
            ], $this->statusOf($e, 422));
        }

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Password direset ke default (888888) & PIN dikosongkan',
            'errors'  => null,
        ]);
    }
}
