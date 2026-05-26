<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RmTemplateGeneratorController extends Controller
{
    /**
     * Mengonversi dokumen fisik (PDF/Gambar) menjadi JSON Schema menggunakan Gemini API.
     */
   public function autoGenerate(Request $request)
    {
        ini_set('memory_limit', '1024M'); 
        set_time_limit(300);              

        $validator = Validator::make($request->all(), [
            'rm_file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $apiKey = env('GEMINI_API_KEY');
        try {
            $file = $request->file('rm_file');
            $fileData = base64_encode(file_get_contents($file->getRealPath()));
            $mimeType = $file->getMimeType();

            // =====================================================================
            // --- TAMBAHKAN KODE UJI POTONG JALUR DI SINI ---
            return response()->json([
                'status' => 'success',
                'message' => 'Koneksi Vue ke Laravel AMAN!',
                'file_size' => strlen($fileData) . ' bytes',
                'mime_type' => $mimeType
            ], 200);
            // =====================================================================

            // KODE DI BAWAH INI SEMENTARA TIDAK AKAN DIEKSEKUSI
            $systemInstruction = "Kamu adalah sistem pakar konversi dokumen Rekam Medis. Ekstrak struktur kolom input. Abaikan logo/alamat. WAJIB kembalikan JSON ARRAY murni, TANPA markdown.";
            $promptKriteria = "Analisislah dokumen ini dan buatkan JSON Schema (Array object dengan section dan fields). Tipe: text, textarea, radio, checkbox_group, signature.";

            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;

            $response = Http::withoutVerifying()
                ->timeout(120) 
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $systemInstruction . "\n\n" . $promptKriteria],
                                ['inlineData' => [ 
                                    'mimeType' => $mimeType, 
                                    'data' => $fileData
                                ]]
                            ]
                        ]
                    ]
                ]);

            if ($response->failed()) {
                return response()->json(['status' => 'error', 'message' => 'Google API Error: ' . $response->body()], 502);
            }

            $result = $response->json();
            $rawJsonText = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

            $parsedStructure = is_string($rawJsonText) ? json_decode($rawJsonText, true) : $rawJsonText;

            if (!$parsedStructure) {
                return response()->json(['status' => 'error', 'message' => 'AI gagal memberikan format JSON yang valid.'], 500);
            }

            return response()->json([
                'status' => 'success',
                'data' => $parsedStructure
            ], 200);

       } catch (\Exception $e) {
            Log::error('RM Generator Exception: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'DEBUG ERROR: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' Line: ' . $e->getLine()
            ], 500);
        }
    }
}