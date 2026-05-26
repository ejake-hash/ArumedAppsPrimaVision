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
}
