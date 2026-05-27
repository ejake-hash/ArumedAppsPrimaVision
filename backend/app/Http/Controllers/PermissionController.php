<?php

namespace App\Http\Controllers;

use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct(private readonly PermissionService $service) {}

    /** List semua permission grouped by module (untuk matriks Roles UI). */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getAllGrouped(),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    /** Flat list (untuk lookup/dropdown). */
    public function flat(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getAll(),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    /** PUT — set override nama tampilan modul (UI-only). Balikan grouped terbaru. */
    public function updateLabel(Request $request, string $module): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'required|string|max:120',
        ]);

        $this->service->updateLabel($module, trim($validated['label']));

        return response()->json([
            'success' => true,
            'data'    => $this->service->getAllGrouped(),
            'message' => 'Nama modul diperbarui.',
            'errors'  => null,
        ]);
    }

    /** POST — kembalikan nama modul ke default (hapus override). Balikan grouped terbaru. */
    public function resetLabel(string $module): JsonResponse
    {
        $this->service->resetLabel($module);

        return response()->json([
            'success' => true,
            'data'    => $this->service->getAllGrouped(),
            'message' => 'Nama modul dikembalikan ke default.',
            'errors'  => null,
        ]);
    }
}
