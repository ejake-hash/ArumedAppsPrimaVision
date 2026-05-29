<?php

namespace App\Services\FormRegistry;

use App\Models\ClinicProfile;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolve binding satu field ke nilai konkret untuk Visit tertentu.
 *
 * Kontrak `binding`:
 *   {
 *     "kind":   "db" | "aggregate" | "static" | "clinic",
 *     "source": "resource.column" | "resource.relation.column" | aggregate-key,
 *     "format": optional — untuk aggregate
 *     "value":  optional — untuk static literal
 *   }
 *
 * Catatan: tipe `computed_*` di-handle via type field (lihat ScoringEngine +
 * DocumentRenderer), bukan via binding.kind. Tidak ada kind 'computed' di sini.
 *
 * Resource db yang dikenal (dimulai dari Visit):
 *   - patient.*           → $visit->patient->*
 *   - visit.*             → $visit->*
 *   - doctorExamination.* → $visit->doctorExamination->*
 *   - nurseAssessment.*   → $visit->nurseAssessment->*
 *   - medicalResume.*     → $visit->medicalResume->*
 *
 * Catatan:
 *   - Path tidak valid / relasi null → return null (jangan exception).
 */
final class BindingResolver
{
    private ?ClinicProfile $clinicProfileCache = null;

    public function __construct(
        private readonly AggregateResolver $aggregates = new AggregateResolver(),
    ) {}

    public function resolve(Visit $visit, array $binding): mixed
    {
        $kind = $binding['kind'] ?? null;

        return match ($kind) {
            'db'        => $this->resolveDb($visit, (string) ($binding['source'] ?? '')),
            'clinic'    => $this->resolveClinic((string) ($binding['source'] ?? '')),
            'static'    => $binding['value'] ?? null,
            'aggregate' => $this->aggregates->resolve(
                $visit,
                (string) ($binding['source'] ?? ''),
                isset($binding['format']) ? (string) $binding['format'] : null,
            ),
            default     => null,
        };
    }

    /**
     * Resolve clinic.* tanpa Visit — dipakai DocumentRenderer::renderForPreview()
     * untuk render dokumen pasien baru yang belum punya Visit di DB.
     */
    public function resolveClinicPublic(string $source): mixed
    {
        return $this->resolveClinic($source);
    }

    /**
     * Path: "resource.column" atau "resource.relation.column".
     * Resource pertama selalu di-resolve relatif ke $visit (visit itu sendiri,
     * atau relasi langsung dari Visit seperti patient / doctorExamination).
     */
    private function resolveDb(Visit $visit, string $source): mixed
    {
        if ($source === '') {
            return null;
        }
        if (!FieldRegistry::isValidDbPath($source)) {
            // Tidak terdaftar di whitelist — refuse to resolve.
            return null;
        }

        $parts = explode('.', $source);
        $resource = array_shift($parts);

        // Resource "visit" = column langsung di $visit.
        $node = $resource === 'visit' ? $visit : $this->loadRelation($visit, $resource);

        foreach ($parts as $segment) {
            if ($node === null) {
                return null;
            }
            if ($node instanceof Model) {
                // Pakai attribute access dulu, fallback ke relasi.
                $next = $node->getAttribute($segment);
                if ($next === null && method_exists($node, $segment)) {
                    $next = $node->{$segment};
                }
                $node = $next;
            } else {
                return null;
            }
        }

        return $this->normalizeScalar($node);
    }

    private function loadRelation(Visit $visit, string $relation): ?Model
    {
        // Map resource-name → method relasi di Visit.
        $map = [
            'patient'           => 'patient',
            'doctorExamination' => 'doctorExamination',
            'nurseAssessment'   => 'nurseAssessment',
            'medicalResume'     => 'medicalResume',
        ];
        if (!isset($map[$relation])) {
            return null;
        }
        $method = $map[$relation];
        $node = $visit->{$method};
        return $node instanceof Model ? $node : null;
    }

    /**
     * Convert image path (di disk public) → data URL `data:image/...;base64,...`.
     * Return string kosong kalau file tidak ada (jangan throw — graceful).
     */
    private function encodeImageAsDataUrl(string $path): string
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            if (!$disk->exists($path)) {
                return '';
            }
            $bytes = $disk->get($path);
            if ($bytes === null || $bytes === '') {
                return '';
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png'        => 'image/png',
                'jpg', 'jpeg'=> 'image/jpeg',
                'svg'        => 'image/svg+xml',
                'webp'       => 'image/webp',
                'gif'        => 'image/gif',
                default      => 'application/octet-stream',
            };
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function resolveClinic(string $source): mixed
    {
        if ($source === '' || !str_starts_with($source, 'clinic.')) {
            return null;
        }
        $column = substr($source, strlen('clinic.'));
        if (!FieldRegistry::isValidDbPath('clinic.' . $column)) {
            return null;
        }

        $this->clinicProfileCache ??= ClinicProfile::query()->first();
        if ($this->clinicProfileCache === null) {
            return null;
        }
        $value = $this->clinicProfileCache->getAttribute($column);

        // Kolom *_path (logo_path, signature_path, stamp_path) → convert ke
        // base64 data URL inline. Alasan:
        //   1. Cross-origin safe — tidak perlu request HTTP (Vite :5173 vs
        //      Laravel :8000 sering bermasalah CORS untuk static resource).
        //   2. Snapshot-friendly — dokumen FINALIZED tetap valid walaupun
        //      file fisik di disk dihapus/diganti (truly immutable per
        //      PMK 24/2022 spirit).
        //   3. Print-friendly — data URL render konsisten di print window.
        // Trade-off: rendered_html jadi lebih besar (~30-40KB per logo).
        // Diterima karena gzip storage (Gap #8) sudah compress ~70%.
        if (is_string($value) && $value !== '' && str_ends_with($column, '_path')) {
            return $this->encodeImageAsDataUrl($value);
        }

        return $this->normalizeScalar($value);
    }

    /**
     * Pastikan nilai bisa di-stringkan untuk substitusi placeholder.
     * Date/DateTime → ISO string; array → JSON; bool → 'Ya'/'Tidak'; lainnya as-is.
     */
    private function normalizeScalar(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }
        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        }
        return $value;
    }
}
