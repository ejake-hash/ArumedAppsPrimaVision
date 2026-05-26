<?php

namespace App\Http\Controllers;

use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'active', 'per_page']);
        return $this->ok($this->service->index($filters));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'           => 'nullable|string|max:20|unique:suppliers,code',
            'name'           => 'required|string|max:150',
            'contact_person' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:100',
            'npwp'           => 'nullable|string|max:30',
            'address'        => 'nullable|string',
            'is_active'      => 'sometimes|boolean',
        ]);

        $supplier = $this->service->create($data);
        return $this->ok($supplier, 'Supplier dibuat', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'sometimes|required|string|max:150',
            'contact_person' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:100',
            'npwp'           => 'nullable|string|max:30',
            'address'        => 'nullable|string',
            'is_active'      => 'sometimes|boolean',
        ]);

        $supplier = $this->service->update($id, $data);
        return $this->ok($supplier, 'Supplier diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return $this->ok(null, 'Supplier dihapus');
    }

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }
}
