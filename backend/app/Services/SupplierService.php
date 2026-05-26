<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;

class SupplierService
{
    public function index(array $filters = []): LengthAwarePaginator
    {
        $q = Supplier::query();

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(function ($qq) use ($term) {
                $qq->where('code', 'ilike', $term)
                   ->orWhere('name', 'ilike', $term)
                   ->orWhere('contact_person', 'ilike', $term)
                   ->orWhere('phone', 'ilike', $term)
                   ->orWhere('email', 'ilike', $term)
                   ->orWhere('npwp', 'ilike', $term);
            });
        }

        if (isset($filters['active']) && $filters['active'] !== '' && $filters['active'] !== null) {
            $q->where('is_active', (bool) $filters['active']);
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        return $q->orderBy('name')->paginate($perPage);
    }

    public function create(array $data): Supplier
    {
        if (empty($data['code'])) {
            $data['code'] = $this->generateCode();
        }
        return Supplier::create($data);
    }

    public function update(string $id, array $data): Supplier
    {
        $supplier = Supplier::findOrFail($id);
        unset($data['code']); // code immutable
        $supplier->update($data);
        return $supplier->fresh();
    }

    public function delete(string $id): void
    {
        $supplier = Supplier::findOrFail($id);

        $hasPO  = $supplier->purchaseOrders()->exists();
        $hasGRN = $supplier->goodsReceipts()->exists();
        if ($hasPO || $hasGRN) {
            abort(422, 'Supplier tidak bisa dihapus — masih terpakai di Pembelian/Penerimaan.');
        }

        $supplier->delete();
    }

    private function generateCode(): string
    {
        $last = Supplier::withTrashed()
            ->where('code', 'like', 'SUP-%')
            ->orderByDesc('code')
            ->value('code');

        $next = 1;
        if ($last && preg_match('/SUP-(\d+)/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }
        return sprintf('SUP-%03d', $next);
    }
}
