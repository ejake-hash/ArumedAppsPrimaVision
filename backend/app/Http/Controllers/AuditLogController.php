<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AuditLogController — audit trail READ-ONLY (tabel system_logs) untuk tab
 * "Audit Log" di DataPenggunaView (Kepegawaian & RBAC). Superadmin-only (grup /rbac).
 *
 * system_logs ditulis oleh banyak service (login, kasir, farmasi, master data,
 * refraksi, dll) via SystemLog::create. Endpoint ini HANYA membaca + filter +
 * paginasi — tidak menulis/ubah apa pun (audit log immutable, PMK 24/2022).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = SystemLog::query()->with('user:id,name,username');

        if ($s = trim((string) $request->query('search', ''))) {
            $term = '%' . $s . '%';
            // Cari di deskripsi & nama model. model_id (uuid) tidak di-ilike
            // (operator ~~ tak berlaku utk uuid di pgsql).
            $q->where(fn ($qq) => $qq
                ->where('description', 'ilike', $term)
                ->orWhere('model', 'ilike', $term));
        }
        if ($action = $request->query('action')) {
            $q->where('action', $action);
        }
        if ($userId = $request->query('user_id')) {
            $q->where('user_id', $userId);
        }
        if ($model = $request->query('model')) {
            $q->where('model', $model);
        }
        if ($from = $request->query('date_from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));
        $logs = $q->orderByDesc('created_at')->paginate($perPage)->withQueryString();

        // Facet: daftar action unik (untuk dropdown filter di UI).
        $actions = SystemLog::query()
            ->select('action')->distinct()->orderBy('action')->pluck('action');

        return response()->json([
            'success' => true,
            'data'    => [
                'logs'   => $logs,
                'facets' => ['actions' => $actions],
            ],
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }
}
