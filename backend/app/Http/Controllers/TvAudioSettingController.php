<?php

namespace App\Http\Controllers;

use App\Models\TvAudioSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TvAudioSettingController extends Controller
{
    /**
     * GET /antrean-tv/audio-settings — public.
     * AntreanTVView load setting ini saat mount supaya default bunyi & volume
     * dipakai langsung tanpa user harus set ulang setiap hari.
     */
    public function show(): JsonResponse
    {
        $row = TvAudioSetting::singleton();
        return response()->json([
            'success' => true,
            'data'    => $this->serialize($row),
        ]);
    }

    /**
     * PUT /antrean-tv/audio-settings — protected (auth:api).
     * Update singleton row.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sound_preset'   => 'sometimes|string|max:32',
            'sound_volume'   => 'sometimes|numeric|min:0|max:1',
            'audio_enabled'  => 'sometimes|boolean',
            'flash_duration' => 'sometimes|integer|min:3|max:10',
            'call_delay'     => 'sometimes|integer|min:5|max:10',
            'tts_voice_name' => 'nullable|string|max:200',
            'tts_rate'       => 'sometimes|numeric|min:0.5|max:2.0',
        ]);

        $row = TvAudioSetting::singleton();
        $row->fill($validated)->save();

        return response()->json([
            'success' => true,
            'data'    => $this->serialize($row),
            'message' => 'Setting bunyi tersimpan sebagai default.',
        ]);
    }

    private function serialize(TvAudioSetting $row): array
    {
        return [
            'sound_preset'   => $row->sound_preset,
            'sound_volume'   => (float) $row->sound_volume,
            'audio_enabled'  => (bool) $row->audio_enabled,
            'flash_duration' => (int) $row->flash_duration,
            'call_delay'     => (int) $row->call_delay,
            'tts_voice_name' => $row->tts_voice_name,
            'tts_rate'       => (float) $row->tts_rate,
        ];
    }
}
