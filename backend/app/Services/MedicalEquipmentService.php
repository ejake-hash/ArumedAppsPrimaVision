<?php

namespace App\Services;

use App\Models\MedicalEquipment;
use App\Models\MedicalEquipmentTariff;
use App\Models\MedicalEquipmentUsage;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * MedicalEquipmentService — CRUD master alat medis, tarif per insurer,
 * dan log pemakaian per kunjungan/operasi.
 */
class MedicalEquipmentService
{
    public function __construct(private Request $request)
    {
    }

    // =========================================================================
    // MASTER EQUIPMENT
    // =========================================================================

    public function index(array $filters = []): LengthAwarePaginator
    {
        $q = MedicalEquipment::query();
        if (!empty($filters['search'])) {
            $kw = $filters['search'];
            $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$kw}%")
                ->orWhere('code', 'ilike', "%{$kw}%")
                ->orWhere('brand', 'ilike', "%{$kw}%")
                ->orWhere('serial_number', 'ilike', "%{$kw}%")
            );
        }
        if (!empty($filters['category'])) {
            $q->where('category', $filters['category']);
        }
        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (isset($filters['active'])) {
            $q->where('is_active', (bool) $filters['active']);
        }
        return $q->orderBy('name')->paginate($filters['per_page'] ?? 25);
    }

    public function show(string $id): MedicalEquipment
    {
        return MedicalEquipment::with('tariffs.insurer')->findOrFail($id);
    }

    public function store(array $data): MedicalEquipment
    {
        if (empty($data['code'])) {
            $data['code'] = $this->generateCode();
        }
        $eq = MedicalEquipment::create($data);
        $this->log('CREATE_EQUIPMENT', $eq->id, "code:{$eq->code} name:{$eq->name}");
        return $eq;
    }

    public function update(string $id, array $data): MedicalEquipment
    {
        $eq = MedicalEquipment::findOrFail($id);
        unset($data['code']); // never overwrite code
        $eq->update($data);
        $this->log('UPDATE_EQUIPMENT', $id, "name:{$eq->name}");
        return $eq->fresh();
    }

    public function destroy(string $id): void
    {
        $eq = MedicalEquipment::findOrFail($id);
        $eq->delete();
        $this->log('DELETE_EQUIPMENT', $id, "name:{$eq->name}");
    }

    private function generateCode(): string
    {
        $last = MedicalEquipment::withTrashed()
            ->where('code', 'like', 'MEQ-%')
            ->orderByDesc('code')
            ->value('code');
        $next = 1;
        if ($last && preg_match('/^MEQ-(\d+)$/', $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }
        return sprintf('MEQ-%03d', $next);
    }

    // =========================================================================
    // TARIF
    // =========================================================================

    public function listTariffs(string $equipmentId): array
    {
        return MedicalEquipmentTariff::with('insurer')
            ->where('medical_equipment_id', $equipmentId)
            ->orderBy('classification')
            ->get()
            ->toArray();
    }

    public function upsertTariff(string $equipmentId, array $data): MedicalEquipmentTariff
    {
        MedicalEquipment::findOrFail($equipmentId);

        $tariff = MedicalEquipmentTariff::updateOrCreate(
            [
                'medical_equipment_id' => $equipmentId,
                'insurer_id'           => $data['insurer_id'] ?? null,
                'classification'       => $data['classification'],
            ],
            [
                'price'     => $data['price'],
                'is_active' => $data['is_active'] ?? true,
            ]
        );
        $this->log('UPSERT_EQUIPMENT_TARIFF', $tariff->id, "eq:{$equipmentId} price:{$tariff->price}");
        return $tariff->fresh('insurer');
    }

    public function deleteTariff(string $tariffId): void
    {
        $t = MedicalEquipmentTariff::findOrFail($tariffId);
        $t->delete();
        $this->log('DELETE_EQUIPMENT_TARIFF', $tariffId);
    }

    // =========================================================================
    // USAGE
    // =========================================================================

    public function recordUsage(array $data): MedicalEquipmentUsage
    {
        return DB::transaction(function () use ($data) {
            $usage = MedicalEquipmentUsage::create([
                'medical_equipment_id' => $data['medical_equipment_id'],
                'visit_id'             => $data['visit_id'],
                'surgery_schedule_id'  => $data['surgery_schedule_id'] ?? null,
                'used_by_id'           => auth('api')->user()?->employee?->id,
                'used_at'              => $data['used_at'] ?? now(),
                'notes'                => $data['notes'] ?? null,
            ]);
            $this->log('RECORD_EQUIPMENT_USAGE', $usage->id, "eq:{$data['medical_equipment_id']} visit:{$data['visit_id']}");
            return $usage->load('equipment');
        });
    }

    public function deleteUsage(string $id): void
    {
        $u = MedicalEquipmentUsage::findOrFail($id);
        $u->delete();
        $this->log('DELETE_EQUIPMENT_USAGE', $id);
    }

    public function usagesByVisit(string $visitId): array
    {
        return MedicalEquipmentUsage::with('equipment')
            ->where('visit_id', $visitId)
            ->orderByDesc('used_at')
            ->get()
            ->toArray();
    }

    // =========================================================================
    private function log(string $action, ?string $modelId, ?string $desc = null): void
    {
        SystemLog::create([
            'user_id'     => auth('api')->id(),
            'action'      => $action,
            'model'       => 'MedicalEquipment',
            'model_id'    => $modelId,
            'description' => $desc,
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
}
