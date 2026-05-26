<?php

namespace App\Http\Controllers;

use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $service) {}

    private function statusOf(\Throwable $e, int $fallback): int
    {
        $code = $e->getCode();
        $code = is_int($code) ? $code : (int) $code;
        return ($code >= 400 && $code < 600) ? $code : $fallback;
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getAll(),
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
                'message' => 'Role tidak ditemukan', 'errors' => null,
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
            'name'             => 'required|string|max:50|unique:roles,name',
            'display_name'     => 'nullable|string|max:100',
            'description'      => 'nullable|string',
            'is_active'        => 'nullable|boolean',
            'permission_ids'   => 'nullable|array',
            'permission_ids.*' => 'uuid|exists:permissions,id',
        ]);

        $data = $this->service->create($validated);

        return response()->json([
            'success' => true, 'data' => $data,
            'message' => 'Role berhasil dibuat', 'errors' => null,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'             => 'sometimes|string|max:50|unique:roles,name,'.$id,
            'display_name'     => 'nullable|string|max:100',
            'description'      => 'nullable|string',
            'is_active'        => 'sometimes|boolean',
            'permission_ids'   => 'sometimes|array',
            'permission_ids.*' => 'uuid|exists:permissions,id',
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
            'message' => 'Role diperbarui', 'errors' => null,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->service->delete($id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'data' => null,
                'message' => $e->getMessage(), 'errors' => null,
            ], $this->statusOf($e, 422));
        }

        return response()->json([
            'success' => true, 'data' => null,
            'message' => 'Role dihapus', 'errors' => null,
        ]);
    }

    public function syncPermissions(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'permission_ids'   => 'required|array',
            'permission_ids.*' => 'uuid|exists:permissions,id',
        ]);

        try {
            $data = $this->service->syncPermissions($id, $validated['permission_ids']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'data' => null,
                'message' => $e->getMessage(), 'errors' => null,
            ], $this->statusOf($e, 422));
        }

        return response()->json([
            'success' => true, 'data' => $data,
            'message' => 'Permission disinkronkan', 'errors' => null,
        ]);
    }
}
