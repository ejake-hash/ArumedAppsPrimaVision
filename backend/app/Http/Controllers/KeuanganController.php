<?php

namespace App\Http\Controllers;

use App\Services\KeuanganService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Modul Keuangan — rekap honor (jasa medis) dokter + CRUD aturan honor.
 * Rute digate permission:keuangan.read (baca/ekspor) & keuangan.write/delete (aturan).
 */
class KeuanganController extends Controller
{
    public function __construct(private readonly KeuanganService $service) {}

    // ── Rekap honor ──────────────────────────────────────────────────────────

    public function recap(Request $request): JsonResponse
    {
        $filters = $this->recapFilters($request);
        return $this->ok($this->service->buildHonorRecap($filters));
    }

    public function export(Request $request): Response
    {
        $filters = $this->recapFilters($request);
        $csv = $this->service->buildHonorRecapCsv($filters);
        $base = 'rekap-honor-' . ($filters['period'] ?? now()->format('Y-m'));

        if (strtolower((string) $request->query('format')) === 'xlsx') {
            $xlsx = \App\Support\SpreadsheetHelper::csvToXlsx($csv, 'Rekap Honor');
            return response($xlsx, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$base}.xlsx\"",
            ]);
        }
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$base}.csv\"",
        ]);
    }

    // ── Laporan obat farmasi ──────────────────────────────────────────────────

    public function medicationReport(Request $request): JsonResponse
    {
        return $this->ok($this->service->medicationReport($this->medReportFilters($request)));
    }

    public function medicationReportExport(Request $request): Response
    {
        $filters = $this->medReportFilters($request);
        $csv = $this->service->buildMedicationReportCsv($filters);
        $base = 'laporan-obat-' . ($filters['period'] ?? now()->format('Y-m'));

        if (strtolower((string) $request->query('format')) === 'xlsx') {
            $xlsx = \App\Support\SpreadsheetHelper::csvToXlsx($csv, 'Laporan Obat');
            return response($xlsx, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$base}.xlsx\"",
            ]);
        }
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$base}.csv\"",
        ]);
    }

    // ── Aturan honor (fee rules) ──────────────────────────────────────────────

    public function options(): JsonResponse
    {
        return $this->ok($this->service->feeRuleOptions());
    }

    public function indexRules(Request $request): JsonResponse
    {
        return $this->ok($this->service->listFeeRules($request->only(['employee_id', 'rule_type'])));
    }

    public function storeRule(Request $request): JsonResponse
    {
        $data = $this->ruleInput($request);
        return $this->ok($this->service->upsertFeeRule($data), 'Aturan honor disimpan', 201);
    }

    public function updateRule(Request $request, string $id): JsonResponse
    {
        $data = $this->ruleInput($request);
        return $this->ok($this->service->upsertFeeRule($data, $id), 'Aturan honor diperbarui');
    }

    public function destroyRule(string $id): JsonResponse
    {
        $this->service->deleteFeeRule($id);
        return $this->ok(null, 'Aturan honor dihapus');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recapFilters(Request $request): array
    {
        $validated = $request->validate([
            'period'      => 'nullable|date_format:Y-m',
            'employee_id' => 'nullable|uuid',
            'payer_group' => 'nullable|in:BPJS,UMUM',
            'bpjs_basis'  => 'nullable|in:finalized,paid',
        ]);
        // format=xlsx (query export) bukan filter — abaikan.
        return array_filter($validated, fn ($v) => $v !== null);
    }

    private function medReportFilters(Request $request): array
    {
        $validated = $request->validate([
            'period'      => 'nullable|date_format:Y-m',
            'payer_group' => 'nullable|in:BPJS,UMUM',
            'bpjs_basis'  => 'nullable|in:finalized,paid',
            'category'    => 'nullable|in:rawat_jalan,pasca_bedah,obat_bebas',
        ]);
        return array_filter($validated, fn ($v) => $v !== null);
    }

    private function ruleInput(Request $request): array
    {
        return $request->validate([
            'employee_id'        => 'nullable|uuid|exists:employees,id',
            'rule_type'          => 'required|in:PERCENT_CATEGORY,PERCENT_PAYER,NOMINAL_PACKAGE',
            'category'           => 'nullable|string|max:150',
            'surgery_package_id' => 'nullable|uuid|exists:surgery_packages,id',
            'payer_group'        => 'nullable|in:BPJS,UMUM',
            'percent'            => 'nullable|numeric|min:0|max:100',
            'nominal'            => 'nullable|numeric|min:0',
            'basis'              => 'nullable|in:GROSS,NET',
            'effective_from'     => 'required|date',
            'effective_to'       => 'nullable|date|after_or_equal:effective_from',
            'label'              => 'nullable|string|max:200',
            'is_active'          => 'nullable|boolean',
        ]);
    }

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
