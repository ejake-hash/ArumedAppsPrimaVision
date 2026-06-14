<?php

namespace App\Services;

use App\Models\DiagnosticOrder;
use App\Models\DiagnosticResult;
use App\Models\DiagnosticTestType;
use App\Models\Patient;
use App\Models\PenunjangIngestInbox;
use Illuminate\Http\UploadedFile;

/**
 * Otak pencocokan hasil penunjang yang dikirim bridge/watcher alat → order yang benar.
 *
 * Aturan match:
 *  1) accession_number → order terbuka (jalur OCT MWL). Paling andal.
 *  2) no_rm (jalur watcher USG) → SATU order penunjang terbuka milik pasien hari ini.
 *     0 atau ≥2 kandidat → ambigu → Inbox (verifikasi manusia).
 *  3) lainnya → Inbox.
 *
 * Idempoten via external_ref (study/SOP UID) supaya retry bridge tak menggandakan file.
 * Tak pernah membuang file diam-diam — yang tak match selalu masuk Inbox.
 */
class PenunjangIngestService
{
    public function __construct(
        private readonly PenunjangService $penunjang,
        private readonly QuantelXmlParser $quantel,
    ) {}

    public function ingest(UploadedFile $file, array $meta): array
    {
        // Jalur Quantel (biometri): file XML mendampingi gambar. Parse dulu supaya
        // no_rm + external_ref (ExamKey) + data biometri kaya bisa diturunkan dari
        // isi file — watcher cukup meneruskan file mentah tanpa logika.
        $expertisePatch = null;
        if (! empty($meta['xml_content'])) {
            $parsed = $this->quantel->parse($meta['xml_content']);
            if ($parsed) {
                $meta['no_rm']        = ($meta['no_rm'] ?? null) ?: ($parsed['no_rm'] ?? null);
                $meta['external_ref'] = ($meta['external_ref'] ?? null) ?: ($parsed['exam_key'] ?? null);

                // Arahkan ke order yang tepat sesuai jenis exam Quantel (xsi:type):
                //  - BIOMETRY → order Biometri (kode BIOM)
                //  - USG      → order ber-modalitas US selain Biometri
                $kind = $parsed['exam_kind'] ?? 'UNKNOWN';
                if ($kind === 'USG') {
                    $meta['prefer_modality']   = 'US';
                    $meta['exclude_test_type'] = DiagnosticTestType::BIOMETRI_CODE;
                } else {
                    // BIOMETRY (default) — perilaku lama.
                    $meta['prefer_test_type'] = DiagnosticTestType::BIOMETRI_CODE;
                }

                $expertisePatch = [
                    'source'   => $parsed['source'],
                    'biometry' => [
                        'exam_kind' => $kind,
                        'exam_key'  => $parsed['exam_key'],
                        'exam_date' => $parsed['exam_date'],
                        'physician' => $parsed['physician'],
                        'eyes'      => $parsed['eyes'],
                    ],
                ];
            }
        }

        $ref = $meta['external_ref'] ?? null;

        // Idempotensi — cek SEBELUM simpan file (hindari file yatim saat retry).
        if ($ref) {
            $inbox = PenunjangIngestInbox::where('external_ref', $ref)
                ->whereIn('status', ['UNMATCHED', 'ASSIGNED'])->first();
            if ($inbox) {
                return ['matched' => $inbox->status === 'ASSIGNED', 'inbox_id' => $inbox->id, 'duplicate' => true];
            }
            $existing = DiagnosticResult::whereJsonContains('expertise_data->ingest_refs', $ref)->first();
            if ($existing) {
                return ['matched' => true, 'order_id' => $existing->diagnostic_order_id, 'duplicate' => true];
            }
        }

        // Simpan file ke disk public (folder sama dgn upload manual → attachment_url jalan).
        $path = $file->store('penunjang-hasil', 'public');

        $order = $this->matchOrder($meta);

        if ($order) {
            $result = $this->penunjang->attachAttachmentToOrder($order, $path, null, $ref, $expertisePatch);
            return [
                'matched'          => true,
                'order_id'         => $order->id,
                'accession_number' => $order->accession_number,
                'patient_name'     => $order->visit?->patient?->name,
                'result_id'        => $result->id,
            ];
        }

        // Tak match → Inbox (jangan drop diam-diam).
        $inbox = PenunjangIngestInbox::create([
            'attachment_path'   => $path,
            'source'            => $meta['source'] ?? 'OCT',
            'accession_number'  => $meta['accession_number'] ?? null,
            'claimed_no_rm'     => $meta['no_rm'] ?? null,
            'original_filename' => $meta['original_filename'] ?? null,
            'external_ref'      => $ref,
            'status'            => 'UNMATCHED',
        ]);

        return ['matched' => false, 'inbox_id' => $inbox->id];
    }

    /** Resolusi order target dari meta. Null = tak ada/ambigu → caller kirim ke Inbox. */
    private function matchOrder(array $meta): ?DiagnosticOrder
    {
        // 1) Accession (OCT MWL).
        if (! empty($meta['accession_number'])) {
            return DiagnosticOrder::with('visit.patient')
                ->where('accession_number', $meta['accession_number'])
                ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
                ->first();
        }

        // 2) No.RM (watcher USG/Quantel) → tepat satu order penunjang terbuka hari ini.
        if (! empty($meta['no_rm'])) {
            $patient = Patient::where('no_rm', $meta['no_rm'])->first();
            if (! $patient) {
                return null;
            }
            $orders = DiagnosticOrder::with('visit.patient')
                ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
                ->whereNotNull('accession_number')
                ->whereDate('created_at', today())
                ->whereHas('visit', fn ($q) => $q->where('patient_id', $patient->id))
                ->get();

            // Bila ambigu (≥2 order terbuka), persempit sesuai jenis exam Quantel:
            if ($orders->count() > 1) {
                // (a) cocokkan kode test persis (mis. BIOMETRI = BIOM).
                if (! empty($meta['prefer_test_type'])) {
                    $preferred = $orders->where('test_type', $meta['prefer_test_type']);
                    if ($preferred->count() === 1) {
                        return $preferred->first();
                    }
                }

                // (b) cocokkan modalitas jenis (mis. USG = modalitas US selain Biometri).
                if (! empty($meta['prefer_modality'])) {
                    $accession = app(AccessionService::class);
                    $exclude   = $meta['exclude_test_type'] ?? null;
                    $preferred = $orders->filter(function ($o) use ($accession, $meta, $exclude) {
                        if ($exclude && $o->test_type === $exclude) {
                            return false;
                        }
                        return $accession->modalityFor($o->test_type) === $meta['prefer_modality'];
                    });
                    if ($preferred->count() === 1) {
                        return $preferred->first();
                    }
                }
            }

            return $orders->count() === 1 ? $orders->first() : null; // 0/≥2 → ambigu → Inbox
        }

        return null;
    }
}
