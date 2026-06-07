<?php

namespace App\Http\Controllers;

use App\Services\PenunjangIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Terima hasil penunjang (PDF/gambar) dari bridge OCT / watcher USG → tautkan ke order.
 * Auth = service token (middleware service-token). Bukan login manusia.
 */
class PenunjangIngestController extends Controller
{
    public function __construct(private readonly PenunjangIngestService $service) {}

    /**
     * POST /integrasi/penunjang/ingest
     * multipart: file (gambar/PDF) + accession_number|no_rm + source + external_ref
     *   + (opsional) xml — file XML data alat (mis. Quantel biometri). Bila ada,
     *     no_rm/external_ref/biometri kaya diturunkan dari isi XML oleh service.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'             => 'required|file|mimetypes:application/pdf,image/jpeg,image/png|max:20480',
            'xml'              => 'nullable|file|max:5120',
            'accession_number' => 'nullable|string|max:16',
            'no_rm'            => 'nullable|string|max:50',
            'source'           => 'nullable|string|in:OCT,USG_WATCHER,QUANTEL_WATCHER',
            'external_ref'     => 'nullable|string|max:191',
        ]);

        $result = $this->service->ingest($request->file('file'), [
            'accession_number' => $validated['accession_number'] ?? null,
            'no_rm'            => $validated['no_rm'] ?? null,
            'source'           => $validated['source'] ?? 'OCT',
            'external_ref'     => $validated['external_ref'] ?? null,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'xml_content'      => $request->hasFile('xml')
                ? file_get_contents($request->file('xml')->getRealPath())
                : null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $result,
            'message' => $result['matched'] ? 'Hasil ditautkan ke order' : 'Hasil masuk Inbox (belum tertaut)',
            'errors'  => null,
        ], 201);
    }
}
