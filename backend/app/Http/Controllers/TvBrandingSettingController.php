<?php

namespace App\Http\Controllers;

use App\Models\TvBrandingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TvBrandingSettingController extends Controller
{
    /**
     * GET /antrean-tv/branding-settings — public.
     * AntreanTVView load saat mount supaya logo & nama klinik langsung dipakai.
     */
    public function show(): JsonResponse
    {
        $row = TvBrandingSetting::singleton();
        return response()->json([
            'success' => true,
            'data'    => $this->serialize($row),
        ]);
    }

    /**
     * PUT /antrean-tv/branding-settings — protected (auth:api).
     * Update singleton row.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Data URL base64 — batasi ~700 KB string (≈512 KB binary + overhead).
            'logo_data'           => 'nullable|string|max:716800',
            'clinic_name'         => 'sometimes|string|max:120',
            'clinic_subtitle'     => 'sometimes|nullable|string|max:160',
            'placeholder_title'   => 'sometimes|string|max:160',
            'placeholder_tagline' => 'sometimes|nullable|string|max:300',
        ]);

        // Tolak data URL non-gambar / format aneh.
        if (! empty($validated['logo_data']) && ! preg_match('#^data:image/(png|jpe?g|svg\+xml|webp);base64,#', $validated['logo_data'])) {
            return response()->json([
                'success' => false,
                'message' => 'Format logo harus gambar PNG, JPG, SVG, atau WebP.',
            ], 422);
        }

        $row = TvBrandingSetting::singleton();
        $row->fill($validated)->save();

        return response()->json([
            'success' => true,
            'data'    => $this->serialize($row),
            'message' => 'Identitas klinik tersimpan untuk semua TV.',
        ]);
    }

    /**
     * POST /antrean-tv/branding-settings/reset — protected (auth:api).
     * Reset singleton ke nilai factory (logo dihapus, teks kembali default).
     * Langsung berlaku untuk semua TV. Mirror TvDisplaySettingController::reset.
     */
    public function reset(): JsonResponse
    {
        $row = TvBrandingSetting::singleton();
        $row->fill(TvBrandingSetting::defaults())->save();

        return response()->json([
            'success' => true,
            'data'    => $this->serialize($row),
            'message' => 'Identitas klinik dikembalikan ke default untuk semua TV.',
        ]);
    }

    private function serialize(TvBrandingSetting $row): array
    {
        return [
            'logo_data'           => $row->logo_data,
            'clinic_name'         => $row->clinic_name,
            'clinic_subtitle'     => $row->clinic_subtitle,
            'placeholder_title'   => $row->placeholder_title,
            'placeholder_tagline' => $row->placeholder_tagline,
        ];
    }
}
