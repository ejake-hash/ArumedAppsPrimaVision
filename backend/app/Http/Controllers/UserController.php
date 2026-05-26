<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserService $service) {}

    private function statusOf(\Throwable $e, int $fallback): int
    {
        $code = $e->getCode();
        $code = is_int($code) ? $code : (int) $code;
        return ($code >= 400 && $code < 600) ? $code : $fallback;
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'role_id', 'is_active']);

        return response()->json([
            'success' => true,
            'data'    => $this->service->getAll($filters),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        try {
            $data = $this->service->getById($id);
        } catch (\Throwable) {
            return response()->json([
                'success' => false, 'data' => null,
                'message' => 'User tidak ditemukan', 'errors' => null,
            ], 404);
        }

        return response()->json([
            'success' => true, 'data' => $data,
            'message' => 'Berhasil', 'errors' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'username'    => 'required|string|max:50|unique:users,username',
            'email'       => 'required|email|unique:users,email',
            'role_id'     => 'required|uuid|exists:roles,id',
            'employee_id' => 'nullable|uuid|exists:employees,id',
            'password'    => 'nullable|string|min:6',
            'is_active'   => 'nullable|boolean',
        ]);

        $data = $this->service->create($validated);

        return response()->json([
            'success' => true, 'data' => $data,
            'message' => 'User berhasil dibuat', 'errors' => null,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'username'    => 'sometimes|string|max:50|unique:users,username,'.$id,
            'email'       => 'sometimes|email|unique:users,email,'.$id,
            'role_id'     => 'sometimes|uuid|exists:roles,id',
            'employee_id' => 'nullable|uuid|exists:employees,id',
            'is_active'   => 'sometimes|boolean',
        ]);

        try {
            $data = $this->service->update($id, $validated);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'data' => null,
                'message' => $e->getMessage(), 'errors' => null,
            ], $this->statusOf($e, 422));
        }

        return response()->json([
            'success' => true, 'data' => $data,
            'message' => 'User diperbarui', 'errors' => null,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $this->service->delete($id, $request->user());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'data' => null,
                'message' => $e->getMessage(), 'errors' => null,
            ], $this->statusOf($e, 422));
        }

        return response()->json([
            'success' => true, 'data' => null,
            'message' => 'User dihapus', 'errors' => null,
        ]);
    }

    public function toggleAktif(Request $request, string $id): JsonResponse
    {
        try {
            $data = $this->service->toggleAktif($id, $request->user());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'data' => null,
                'message' => $e->getMessage(), 'errors' => null,
            ], $this->statusOf($e, 422));
        }

        return response()->json([
            'success' => true, 'data' => $data,
            'message' => $data['is_active'] ? 'User diaktifkan' : 'User dinonaktifkan',
            'errors'  => null,
        ]);
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'new_password' => 'nullable|string|min:6',
        ]);

        $generated = $this->service->resetPassword($id, $validated['new_password'] ?? null);

        return response()->json([
            'success' => true,
            'data'    => ['new_password' => $generated],
            'message' => 'Password berhasil direset',
            'errors'  => null,
        ]);
    }
}
