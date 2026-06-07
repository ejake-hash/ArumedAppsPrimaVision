<?php

namespace App\Http\Controllers;

use App\Models\PatientIdentityDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Berkas identitas pasien (KTP — scan/foto/PDF), per-pasien.
 * Disimpan di disk `local` (privat, storage/app/private) — KTP = PII sensitif,
 * JANGAN diekspos lewat URL /storage publik. Disajikan lewat endpoint ber-auth
 * (showFile) yang sudah dijaga middleware `permission:admisi.read` di route group.
 * Pola validasi/store meniru PenunjangController::uploadHasilAttachment.
 */
class PatientIdentityDocumentController extends Controller
{
    private const DISK = 'local';

    /** GET /admisi/pasien/{id}/identity-documents */
    public function index(string $id): JsonResponse
    {
        $docs = PatientIdentityDocument::where('patient_id', $id)
            ->latest()
            ->get()
            ->map(fn ($d) => $this->present($d));

        return $this->ok($docs);
    }

    /** POST /admisi/pasien/{id}/identity-documents */
    public function store(string $id, Request $request): JsonResponse
    {
        $request->validate([
            // max:2048 KB = 2 MB. Gambar besar sudah dikompres di sisi browser;
            // ini jaring pengaman server.
            'file'     => 'required|file|mimes:jpeg,jpg,png,webp,pdf|max:2048',
            'doc_type' => 'nullable|in:KTP,KK,PASPOR,SIM,KIA',
        ]);

        $file = $request->file('file');
        $path = $file->store("patient-identity/{$id}", self::DISK);

        $doc = PatientIdentityDocument::create([
            'patient_id'     => $id,
            'doc_type'       => $request->input('doc_type', 'KTP'),
            'file_path'      => $path,
            'file_name'      => $file->getClientOriginalName(),
            'mime_type'      => $file->getClientMimeType(),
            'file_size'      => $file->getSize(),
            'uploaded_by_id' => auth('api')->user()?->employee_id,
        ]);

        return $this->ok($this->present($doc), 'Dokumen identitas diunggah', 201);
    }

    /** GET /admisi/pasien/{id}/identity-documents/{docId}/file — stream berkas privat */
    public function showFile(string $id, string $docId): StreamedResponse
    {
        $doc = PatientIdentityDocument::where('patient_id', $id)->findOrFail($docId);

        abort_unless(Storage::disk(self::DISK)->exists($doc->file_path), 404, 'Berkas tidak ditemukan');

        // Inline (preview di tab/blob). FE mengambilnya via Axios responseType blob.
        return Storage::disk(self::DISK)->response($doc->file_path, $doc->file_name, [
            'Content-Type' => $doc->mime_type ?? 'application/octet-stream',
        ]);
    }

    /** DELETE /admisi/pasien/{id}/identity-documents/{docId} */
    public function destroy(string $id, string $docId): JsonResponse
    {
        $doc = PatientIdentityDocument::where('patient_id', $id)->findOrFail($docId);

        Storage::disk(self::DISK)->delete($doc->file_path);
        $doc->delete();

        return $this->ok(null, 'Dokumen identitas dihapus');
    }

    /** Metadata aman untuk FE (tanpa membongkar isi berkas). */
    private function present(PatientIdentityDocument $d): array
    {
        return [
            'id'         => $d->id,
            'doc_type'   => $d->doc_type,
            'file_name'  => $d->file_name,
            'mime_type'  => $d->mime_type,
            'file_size'  => $d->file_size,
            'is_pdf'     => $d->mime_type === 'application/pdf',
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    // =========================================================================
    // RESPONSE HELPERS (selaras dgn controller lain)
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
