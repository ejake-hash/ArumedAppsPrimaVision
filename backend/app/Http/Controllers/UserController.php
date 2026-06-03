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
            'pin'         => 'nullable|digits_between:4,6',
            'is_active'   => 'nullable|boolean',
            // Profil nakes (ditulis ke pegawai tertaut bila ada). NIP unik lintas pegawai.
            'nip'         => 'nullable|string|max:20|unique:employees,nip',
            'sip'         => 'nullable|string|max:50',
            'str'         => 'nullable|string|max:50',
        ]);

        $data = $this->service->create($validated);

        return response()->json([
            'success' => true, 'data' => $data,
            'message' => 'User berhasil dibuat', 'errors' => null,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        // NIP unik lintas pegawai, tapi abaikan pegawai milik user ini sendiri agar
        // edit tanpa mengubah NIP tidak ditolak. employee_id boleh null (tak ada nakes).
        $ownEmployeeId = \App\Models\User::whereKey($id)->value('employee_id');
        $nipRule = 'nullable|string|max:20|unique:employees,nip'
            . ($ownEmployeeId ? ',' . $ownEmployeeId : '');

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'username'    => 'sometimes|string|max:50|unique:users,username,'.$id,
            'email'       => 'sometimes|email|unique:users,email,'.$id,
            'role_id'     => 'sometimes|uuid|exists:roles,id',
            'employee_id' => 'nullable|uuid|exists:employees,id',
            'password'    => 'nullable|string|min:6',
            'pin'         => 'nullable|digits_between:4,6',
            'is_active'   => 'sometimes|boolean',
            // Profil nakes (ditulis ke pegawai tertaut bila ada).
            'nip'         => $nipRule,
            'sip'         => 'nullable|string|max:50',
            'str'         => 'nullable|string|max:50',
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

    /** Aksi reset password/PIN hanya untuk superadmin. */
    private function denyIfNotSuperadmin(Request $request): ?JsonResponse
    {
        if (! $request->user()?->isSuperadmin()) {
            return response()->json([
                'success' => false, 'data' => null,
                'message' => 'Hanya superadmin yang dapat melakukan aksi ini.', 'errors' => null,
            ], 403);
        }
        return null;
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyIfNotSuperadmin($request)) return $deny;

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

    public function resetPin(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyIfNotSuperadmin($request)) return $deny;

        $generated = $this->service->resetPin($id);

        return response()->json([
            'success' => true,
            'data'    => ['new_pin' => $generated],
            'message' => 'PIN berhasil direset',
            'errors'  => null,
        ]);
    }

    // GET /rbac/users/csv-template  (?format=xlsx untuk Excel)
    public function csvTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return $this->csvOrXlsx($request, $this->service->csvTemplate(), 'template-pengguna', 'Pengguna');
    }

    // GET /rbac/users/export  (?format=xlsx untuk Excel)
    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return $this->csvOrXlsx($request, $this->service->exportCsv(), 'data-pengguna-' . now()->format('Ymd-His'), 'Pengguna');
    }

    /** Kirim CSV string sbg file CSV (default) atau XLSX bila ?format=xlsx. */
    private function csvOrXlsx(Request $request, string $csv, string $baseName, string $sheetTitle): \Symfony\Component\HttpFoundation\Response
    {
        if (strtolower((string) $request->query('format')) === 'xlsx') {
            $xlsx = \App\Support\SpreadsheetHelper::csvToXlsx($csv, $sheetTitle);

            return response($xlsx, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$baseName}.xlsx\"",
            ]);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$baseName}.csv\"",
        ]);
    }

    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120',
        ]);

        try {
            // CSV/XLSX/ODS → CSV string ternormalisasi → jalur importer CSV existing.
            $result = $this->service->importCsv(\App\Support\SpreadsheetHelper::fileToCsv($request->file('file')));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 'data' => null,
                'message' => $e->getMessage(), 'errors' => null,
            ], $this->statusOf($e, 422));
        }

        $created = count($result['created']);
        $skipped = count($result['skipped']);
        $errors  = count($result['errors']);

        return response()->json([
            'success' => true,
            'data'    => $result,
            'message' => "Import selesai: {$created} ditambah, {$skipped} dilewati, {$errors} gagal.",
            'errors'  => null,
        ]);
    }
}
