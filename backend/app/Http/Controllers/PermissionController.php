<?php

namespace App\Http\Controllers;

use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

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
}
