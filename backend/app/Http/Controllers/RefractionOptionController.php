<?php

namespace App\Http\Controllers;

use App\Models\RefractionOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RefractionOptionController — CRUD master OPSI REFRAKSI + endpoint publik
 * `options()` yang dipakai RefraksionisView untuk mengisi combobox.
 *
 * RBAC: GET terbuka untuk station refraksi (lihat route); write digate
 * permission master (pengaturan.*) di route.
 */
class RefractionOptionController extends Controller
{
    /**
     * GET /refraksi/opsi
     * Map kind → daftar opsi siap-pakai (untuk combobox). Hanya yang aktif.
     * Bentuk: { sphere: ['+0.00','+0.25',...], axis: ['0','5',...], visus: [...] }
     */
    public function options(): JsonResponse
    {
        $map = RefractionOption::where('is_active', true)
            ->get()
            ->mapWithKeys(fn (RefractionOption $o) => [$o->kind => $o->generateOptions()])
            ->all();

        return $this->ok($map);
    }

    /**
     * GET /master/refraksi-opsi
     * Daftar penuh konfigurasi (untuk UI admin Master Data).
     */
    public function index(): JsonResponse
    {
        $rows = RefractionOption::orderBy('kind')->get()->map(function (RefractionOption $o) {
            $arr = $o->toArray();
            $arr['preview'] = array_slice($o->generateOptions(), 0, 6);
            $arr['count']   = count($o->generateOptions());
            return $arr;
        });

        return $this->ok($rows);
    }

    /**
     * POST /master/refraksi-opsi
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, null);

        try {
            $option = RefractionOption::create($data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($option, 'Opsi refraksi dibuat', 201);
    }

    /**
     * PUT /master/refraksi-opsi/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $option = RefractionOption::findOrFail($id);
        $data   = $this->validatePayload($request, $option->id);

        // `kind` immutable setelah dibuat (jadi anchor dropdown di frontend).
        unset($data['kind']);

        try {
            $option->update($data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($option->fresh(), 'Opsi refraksi diperbarui');
    }

    /**
     * DELETE /master/refraksi-opsi/{id}
     * Hard delete — opsi master tak punya FK ke data klinis (combobox toleran).
     */
    public function destroy(string $id): JsonResponse
    {
        $option = RefractionOption::findOrFail($id);
        $option->delete();

        return $this->ok(null, 'Opsi refraksi dihapus');
    }

    // =========================================================================

    private function validatePayload(Request $request, ?string $ignoreId): array
    {
        $validated = $request->validate([
            'kind' => [
                $ignoreId ? 'sometimes' : 'required',
                Rule::in(RefractionOption::KINDS),
                Rule::unique('refraction_options', 'kind')->ignore($ignoreId),
            ],
            'label'     => 'required|string|max:100',
            'mode'      => ['required', Rule::in(RefractionOption::MODES)],
            'format'    => ['required', Rule::in(RefractionOption::FORMATS)],
            // Rentang lebar: axis sampai 180, dioptri ±25, keratometri ~60. Beri ruang.
            'min_value' => 'nullable|numeric|between:-360,360|required_if:mode,range',
            'max_value' => 'nullable|numeric|between:-360,360|required_if:mode,range',
            'step'      => 'nullable|numeric|gt:0|max:50|required_if:mode,range',
            'values'    => 'nullable|array|required_if:mode,list',
            'values.*'  => 'string|max:20',
            'is_active' => 'boolean',
        ]);

        if (($validated['mode'] ?? null) === 'range'
            && isset($validated['min_value'], $validated['max_value'])
            && (float) $validated['max_value'] < (float) $validated['min_value']) {
            abort(response()->json([
                'success' => false, 'data' => null,
                'message' => 'Nilai maksimum harus ≥ minimum.', 'errors' => null,
            ], 422));
        }

        return $validated;
    }

    // =========================================================================
    // RESPONSE HELPERS (base Controller kosong — wajib deklarasi lokal)
    // =========================================================================

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int|string $status = 500): JsonResponse
    {
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 500;
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
