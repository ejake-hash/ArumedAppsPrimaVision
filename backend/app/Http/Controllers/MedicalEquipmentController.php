<?php

namespace App\Http\Controllers;

use App\Services\MedicalEquipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicalEquipmentController extends Controller
{
    public function __construct(private MedicalEquipmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->service->index(
            $request->only(['search', 'category', 'status', 'active', 'per_page'])
        ));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->service->show($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'               => 'nullable|string|max:50|unique:medical_equipments,code',
            'name'               => 'required|string|max:200',
            'category'           => 'nullable|in:MICROSCOPE,PHACO_MACHINE,BIOMETRY,AUTOREFRACTOR,LAINNYA',
            'brand'              => 'nullable|string|max:100',
            'model'              => 'nullable|string|max:100',
            'serial_number'      => 'nullable|string|max:100',
            'location'           => 'nullable|string|max:100',
            'status'             => 'nullable|in:ACTIVE,MAINTENANCE,RETIRED',
            'calibration_due_at' => 'nullable|date',
            'purchase_date'      => 'nullable|date',
            'description'        => 'nullable|string|max:500',
            'is_active'          => 'nullable|boolean',
        ]);

        return $this->ok($this->service->store($data), 'Alat medis dibuat', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'               => 'sometimes|string|max:200',
            'category'           => 'nullable|in:MICROSCOPE,PHACO_MACHINE,BIOMETRY,AUTOREFRACTOR,LAINNYA',
            'brand'              => 'nullable|string|max:100',
            'model'              => 'nullable|string|max:100',
            'serial_number'      => 'nullable|string|max:100',
            'location'           => 'nullable|string|max:100',
            'status'             => 'nullable|in:ACTIVE,MAINTENANCE,RETIRED',
            'calibration_due_at' => 'nullable|date',
            'purchase_date'      => 'nullable|date',
            'description'        => 'nullable|string|max:500',
            'is_active'          => 'nullable|boolean',
        ]);

        return $this->ok($this->service->update($id, $data), 'Alat medis diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->service->destroy($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok(null, 'Alat medis dihapus');
    }

    // ── TARIF ─────────────────────────────────────────────────────────────
    public function listTariffs(string $id): JsonResponse
    {
        return $this->ok($this->service->listTariffs($id));
    }

    public function upsertTariff(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'insurer_id'     => 'nullable|uuid|exists:insurers,id',
            'classification' => 'required|in:UMUM,BPJS,ASURANSI,PERUSAHAAN,SOSIAL',
            'price'          => 'required|numeric|min:0',
            'is_active'      => 'nullable|boolean',
        ]);
        return $this->ok($this->service->upsertTariff($id, $data), 'Tarif disimpan');
    }

    public function deleteTariff(string $tariffId): JsonResponse
    {
        $this->service->deleteTariff($tariffId);
        return $this->ok(null, 'Tarif dihapus');
    }

    // ── USAGE ─────────────────────────────────────────────────────────────
    public function recordUsage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'medical_equipment_id' => 'required|uuid|exists:medical_equipments,id',
            'visit_id'             => 'required|uuid|exists:visits,id',
            'surgery_schedule_id'  => 'nullable|uuid|exists:surgery_schedules,id',
            'used_at'              => 'nullable|date',
            'notes'                => 'nullable|string|max:500',
        ]);
        return $this->ok($this->service->recordUsage($data), 'Pemakaian alat dicatat', 201);
    }

    public function deleteUsage(string $id): JsonResponse
    {
        $this->service->deleteUsage($id);
        return $this->ok(null, 'Catatan pemakaian dihapus');
    }

    public function usagesByVisit(string $visitId): JsonResponse
    {
        return $this->ok($this->service->usagesByVisit($visitId));
    }

    // ── helpers ───────────────────────────────────────────────────────────
    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function error(string $message, int|string $status = 422): JsonResponse
    {
        // Coerce non-int status (e.g. PDO SQLSTATE string from QueryException) to a valid HTTP code.
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 422;
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
