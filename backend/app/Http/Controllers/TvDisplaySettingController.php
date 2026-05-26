<?php

namespace App\Http\Controllers;

use App\Models\TvDisplaySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TvDisplaySettingController extends Controller
{
    /**
     * GET /antrean-tv/display-settings — public (dipakai oleh AntreanTVView
     * untuk render TTS template + flash + badge + toggle kartu).
     *
     * Return semua 8 stasiun. Kalau row belum ada di DB (mis. fresh migrate
     * tanpa seed), fallback ke `TvDisplaySetting::defaults()`.
     */
    public function index(): JsonResponse
    {
        $rows = TvDisplaySetting::all()->keyBy('station');
        $defaults = TvDisplaySetting::defaults();
        $data = [];
        foreach ($defaults as $station => $defaultPayload) {
            $row = $rows->get($station);
            $data[$station] = $row ? $this->serialize($row) : array_merge(
                ['station' => $station],
                $defaultPayload,
            );
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * PUT /antrean-tv/display-settings/{station} — protected (auth:api).
     * Upsert row untuk 1 stasiun.
     */
    public function update(Request $request, string $station): JsonResponse
    {
        $defaults = TvDisplaySetting::defaults();
        if (!isset($defaults[$station])) {
            return response()->json([
                'success' => false,
                'message' => "Stasiun tidak dikenal: {$station}",
            ], 422);
        }

        $validated = $request->validate([
            'tts_template'        => 'nullable|string|max:500',
            'flash_label_top'     => 'nullable|string|max:100',
            'flash_badge_text'    => 'nullable|string|max:200',
            'custom_poli_label'   => 'nullable|string|max:100',
            'show_name_in_flash'  => 'sometimes|boolean',
            'show_poly_in_flash'  => 'sometimes|boolean',
            'show_name_in_card'   => 'sometimes|boolean',
            'show_poly_in_card'   => 'sometimes|boolean',
            'read_name_in_tts'    => 'sometimes|boolean',
        ]);

        $row = TvDisplaySetting::updateOrCreate(
            ['station' => $station],
            $validated,
        );

        return response()->json([
            'success' => true,
            'data'    => $this->serialize($row),
            'message' => "Setting tampilan stasiun {$station} disimpan.",
        ]);
    }

    /**
     * POST /antrean-tv/display-settings/{station}/reset — protected.
     * Reset ke default. Mengembalikan baris ke nilai factory.
     */
    public function reset(string $station): JsonResponse
    {
        $defaults = TvDisplaySetting::defaults();
        if (!isset($defaults[$station])) {
            return response()->json([
                'success' => false,
                'message' => "Stasiun tidak dikenal: {$station}",
            ], 422);
        }

        $row = TvDisplaySetting::updateOrCreate(
            ['station' => $station],
            $defaults[$station],
        );

        return response()->json([
            'success' => true,
            'data'    => $this->serialize($row),
            'message' => "Setting tampilan stasiun {$station} dikembalikan ke default.",
        ]);
    }

    private function serialize(TvDisplaySetting $row): array
    {
        return [
            'station'             => $row->station,
            'tts_template'        => $row->tts_template,
            'flash_label_top'     => $row->flash_label_top,
            'flash_badge_text'    => $row->flash_badge_text,
            'custom_poli_label'   => $row->custom_poli_label,
            'show_name_in_flash'  => (bool) $row->show_name_in_flash,
            'show_poly_in_flash'  => (bool) $row->show_poly_in_flash,
            'show_name_in_card'   => (bool) $row->show_name_in_card,
            'show_poly_in_card'   => (bool) $row->show_poly_in_card,
            'read_name_in_tts'    => (bool) $row->read_name_in_tts,
        ];
    }
}
