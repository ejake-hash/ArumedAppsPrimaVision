<?php

namespace App\Services\FormRegistry;

use App\Models\DoctorExamination;
use App\Models\MedicalResume;
use App\Models\NurseAssessment;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Router INPUT submission ke service domain existing.
 *
 * Kontrak:
 *   1. Inspect field_schema → kelompokkan field per resource (patient/visit/
 *      doctorExamination/nurseAssessment/medicalResume).
 *   2. Untuk setiap group, panggil adapter spesifik yang tahu cara
 *      create-or-update tabel target (handle uniqueness / lock / dst).
 *   3. Field dengan binding `static` di-collect sebagai snapshot
 *      payload (disimpan ke patient_documents.signatures atau metadata)
 *      tanpa sync ke tabel klinis.
 *   4. Field dengan binding `clinic` / `aggregate` / `computed` → READ-ONLY,
 *      tidak boleh muncul di form INPUT (lemparkan warning di submit, tapi
 *      tidak error karena field bisa di-mix di HYBRID form).
 *
 * Catatan:
 *   - Adapter sengaja tidak men-throw kalau resource tidak punya method
 *     yang sesuai — return warning di hasil submit.
 *   - Field `signature_canvas` tipe → SKIP (handled by Fase 4 signature flow).
 *   - Adapter `doctorExamination` + `medicalResume` aktif sejak gap-fix v2:
 *     firstOrNew, cek `is_finalized` (+ `is_editable` untuk resume), set creator
 *     pertama kali (`doctor_id` = employee user saat ini).
 */
final class SubmitRouter
{
    /**
     * @return array{
     *   synced: array<string, list<string>>,
     *   skipped: list<string>,
     *   warnings: list<string>,
     *   static_payload: array<string, mixed>
     * }
     */
    public function submit(Visit $visit, array $fieldSchema, array $data): array
    {
        $result = [
            'synced'         => [],
            'skipped'        => [],
            'warnings'       => [],
            'static_payload' => [],
        ];

        $fields = $this->flattenFields($fieldSchema);

        // Group field per resource (resource = bagian pertama dari binding.source).
        $byResource = ['patient' => [], 'visit' => [], 'doctorExamination' => [], 'nurseAssessment' => [], 'medicalResume' => []];
        // Reverse map [resource][column] => field.key — dipakai untuk re-map
        // ValidationException error key (column) → field key di form.
        $columnToFieldKey = ['patient' => [], 'visit' => [], 'doctorExamination' => [], 'nurseAssessment' => [], 'medicalResume' => []];
        foreach ($fields as $field) {
            $key      = $field['key']     ?? null;
            $binding  = $field['binding'] ?? [];
            $kind     = $binding['kind']  ?? null;
            $type     = $field['type']    ?? 'text';

            if (!is_string($key) || $key === '') continue;
            if (!array_key_exists($key, $data)) continue; // user tidak isi

            if ($type === 'signature_canvas') {
                $result['warnings'][] = "Field '{$key}' (signature) di-skip — handled by Fase 4 signature flow.";
                continue;
            }

            // Fase 5: scored_radio + computed_* di-snapshot ke static_payload
            // (bukan ke tabel klinis). Computed bisa skip dari user input juga —
            // ScoringEngine akan compute ulang saat render.
            if ($type === 'scored_radio' || str_starts_with((string) $type, 'computed_')) {
                $result['static_payload'][$key] = $data[$key];
                continue;
            }

            $val = $data[$key];

            if ($kind === 'static') {
                $result['static_payload'][$key] = $val;
                continue;
            }
            if ($kind === 'clinic' || $kind === 'aggregate' || $kind === 'computed') {
                $result['warnings'][] = "Field '{$key}' read-only ({$kind}) — diabaikan di submit.";
                continue;
            }
            if ($kind !== 'db') {
                $result['warnings'][] = "Field '{$key}' punya binding.kind '{$kind}' yang tidak dikenali — diabaikan.";
                continue;
            }

            $source = (string) ($binding['source'] ?? '');
            $resource = explode('.', $source, 2)[0] ?? '';

            if (!FieldRegistry::isValidDbPath($source)) {
                $result['warnings'][] = "Field '{$key}' binding.source '{$source}' tidak terdaftar di FieldRegistry — diabaikan.";
                continue;
            }

            if (!array_key_exists($resource, $byResource)) {
                $result['warnings'][] = "Field '{$key}' resource '{$resource}' belum didukung router INPUT — diabaikan.";
                continue;
            }

            // Map: nested path "doctorExamination.doctor.name" ditolak (read-only).
            // Hanya "doctorExamination.column_langsung" yang valid untuk WRITE.
            $rest = substr($source, strlen($resource) + 1);
            if (str_contains($rest, '.')) {
                $result['warnings'][] = "Field '{$key}' path '{$source}' terlalu dalam (nested) — diabaikan untuk WRITE.";
                continue;
            }

            $byResource[$resource][$rest] = $val;
            $columnToFieldKey[$resource][$rest] = $key;
        }

        try {
            DB::transaction(function () use ($visit, &$byResource, &$result) {
            if (!empty($byResource['patient'])) {
                $this->syncPatient($visit, $byResource['patient'], $result);
            }
            if (!empty($byResource['visit'])) {
                $this->syncVisit($visit, $byResource['visit'], $result);
            }
            if (!empty($byResource['nurseAssessment'])) {
                $this->syncNurseAssessment($visit, $byResource['nurseAssessment'], $result);
            }
            if (!empty($byResource['doctorExamination'])) {
                $this->syncDoctorExamination($visit, $byResource['doctorExamination'], $result);
            }
            if (!empty($byResource['medicalResume'])) {
                $this->syncMedicalResume($visit, $byResource['medicalResume'], $result);
            }
            });
        } catch (ValidationException $e) {
            // Re-map error key dari `data.<column>` ke `data.<fieldKey>` supaya
            // frontend bisa highlight field yang tepat (FormRMRenderer strip
            // prefix `data.` saat baca response.errors).
            $remapped = [];
            $errors = $e->errors();
            foreach ($errors as $key => $msgs) {
                $newKey = $key;
                if (str_starts_with($key, 'data.')) {
                    $col = substr($key, 5);
                    foreach ($columnToFieldKey as $resourceMap) {
                        if (isset($resourceMap[$col])) {
                            $newKey = 'data.' . $resourceMap[$col];
                            break;
                        }
                    }
                }
                $remapped[$newKey] = $msgs;
            }
            throw ValidationException::withMessages($remapped);
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Adapters
    // ─────────────────────────────────────────────────────────────────────────

    private function syncPatient(Visit $visit, array $patch, array &$result): void
    {
        $patient = $visit->patient;
        if (!$patient) {
            $result['warnings'][] = 'Visit tidak punya patient ter-link — patient.* di-skip.';
            return;
        }
        $patch = $this->validatePatch('patient', $patch);
        $patient->update($patch);
        $result['synced']['patient'] = array_keys($patch);
    }

    private function syncVisit(Visit $visit, array $patch, array &$result): void
    {
        // Subset kolom yang aman di-update via Form Registry (hindari status flow).
        $whitelist = ['follow_up_date', 'follow_up_reason', 'planning_follow_up'];
        $safe = array_intersect_key($patch, array_flip($whitelist));
        $rejected = array_diff(array_keys($patch), array_keys($safe));

        if (!empty($safe)) {
            $safe = $this->validatePatch('visit', $safe);
            $visit->update($safe);
            $result['synced']['visit'] = array_keys($safe);
        }
        foreach ($rejected as $col) {
            $result['warnings'][] = "visit.{$col} read-only via Form Registry (manipulasi via flow service).";
        }
    }

    private function syncNurseAssessment(Visit $visit, array $patch, array &$result): void
    {
        $assess = NurseAssessment::firstOrNew(['visit_id' => $visit->id]);
        if ($assess->is_finalized) {
            $result['warnings'][] = 'Nurse assessment sudah di-finalize — nurseAssessment.* di-skip.';
            return;
        }

        $patch = $this->validatePatch('nurseAssessment', $patch);
        foreach ($patch as $k => $v) {
            $assess->{$k} = $v;
        }
        if (!$assess->exists) {
            $user = auth('api')->user();
            $assess->assessed_by_id = $user?->employee_id;
        }
        $assess->save();
        $result['synced']['nurseAssessment'] = array_keys($patch);
    }

    private function syncDoctorExamination(Visit $visit, array $patch, array &$result): void
    {
        $exam = DoctorExamination::firstOrNew(['visit_id' => $visit->id]);
        if ($exam->is_finalized) {
            $result['warnings'][] = 'Doctor examination sudah di-finalize — doctorExamination.* di-skip.';
            return;
        }

        $patch = $this->validatePatch('doctorExamination', $patch);
        foreach ($patch as $k => $v) {
            $exam->{$k} = $v;
        }
        if (!$exam->exists) {
            $user = auth('api')->user();
            $exam->doctor_id = $user?->employee_id;
        }
        $exam->save();
        $result['synced']['doctorExamination'] = array_keys($patch);
    }

    private function syncMedicalResume(Visit $visit, array $patch, array &$result): void
    {
        $resume = MedicalResume::firstOrNew(['visit_id' => $visit->id]);
        if ($resume->is_finalized) {
            $result['warnings'][] = 'Medical resume sudah di-finalize — medicalResume.* di-skip.';
            return;
        }
        if ($resume->exists && $resume->is_editable === false) {
            $result['warnings'][] = 'Medical resume tidak editable — medicalResume.* di-skip.';
            return;
        }

        $patch = $this->validatePatch('medicalResume', $patch);
        foreach ($patch as $k => $v) {
            $resume->{$k} = $v;
        }
        if (!$resume->exists) {
            $user = auth('api')->user();
            $resume->doctor_id = $user?->employee_id;
            $resume->is_editable = true;
        }
        $resume->save();
        $result['synced']['medicalResume'] = array_keys($patch);
    }

    /**
     * Validasi server-side per-field SEBELUM mass-assign.
     *
     * Tujuan: input invalid (NIK non-digit, gender bukan L/P, date salah, dst)
     * jadi 422 per-field via ValidationException — bukan 500 DB constraint.
     *
     * Hanya field yang ada di $patch yang divalidasi (sometimes-style). Frontend
     * map error[key] → fieldErrors[key.replace(/^data\./)] sudah ada di
     * FormRMRenderer; key di-prefix `data.` supaya Laravel default error bag
     * jalan.
     */
    private function validatePatch(string $resource, array $patch): array
    {
        $rules = $this->rulesFor($resource);
        $applicable = array_intersect_key($rules, $patch);
        if (empty($applicable)) {
            return $patch;
        }

        // Prefix `data.` supaya frontend bisa map error key ke field key.
        $prefixed = [];
        $prefixedRules = [];
        foreach ($applicable as $col => $rule) {
            $prefixed["data.$col"]      = $patch[$col];
            $prefixedRules["data.$col"] = $rule;
        }

        $validator = Validator::make($prefixed, $prefixedRules, $this->validationMessages());
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $patch;
    }

    /**
     * Rules per resource — hanya field DB yang exposed di FieldRegistry.
     * Pakai `nullable` supaya field bisa di-clear via form (kosongkan input).
     */
    private function rulesFor(string $resource): array
    {
        return match ($resource) {
            'patient' => [
                'name'          => ['nullable', 'string', 'max:150'],
                'nik'           => ['nullable', 'string', 'max:50'],
                'no_rm'         => ['nullable', 'string', 'max:50'],
                'date_of_birth' => ['nullable', 'date'],
                'gender'        => ['nullable', 'in:L,P'],
                'address'       => ['nullable', 'string', 'max:1000'],
                'province'      => ['nullable', 'string', 'max:100'],
                'phone'         => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]*$/'],
                'bpjs_number'   => ['nullable', 'string', 'max:30'],
                'blood_type'    => ['nullable', 'string', 'max:5'],
                'allergy_notes' => ['nullable', 'string', 'max:2000'],
            ],
            'visit' => [
                'planning_follow_up' => ['nullable', 'boolean'],
                'follow_up_date'     => ['nullable', 'date'],
                'follow_up_reason'   => ['nullable', 'string', 'max:1000'],
            ],
            'nurseAssessment' => [
                'td_sistol'        => ['nullable', 'integer', 'between:40,300'],
                'td_diastol'       => ['nullable', 'integer', 'between:20,200'],
                'nadi'             => ['nullable', 'integer', 'between:20,250'],
                'suhu'             => ['nullable', 'numeric', 'between:30,45'],
                'respirasi'        => ['nullable', 'integer', 'between:5,80'],
                'spo2'             => ['nullable', 'numeric', 'between:0,100'],
                'kgd'              => ['nullable', 'numeric', 'between:0,1000'],
                'pain_scale'       => ['nullable', 'integer', 'between:0,10'],
                'berat_badan'      => ['nullable', 'numeric', 'between:0,500'],
                'tinggi_badan'     => ['nullable', 'numeric', 'between:0,300'],
                'bmi'              => ['nullable', 'numeric', 'between:0,100'],
                'has_allergy'      => ['nullable', 'boolean'],
                'allergy_detail'   => ['nullable', 'string', 'max:2000'],
                'chief_complaint'  => ['nullable', 'string', 'max:2000'],
                'rps'              => ['nullable', 'string', 'max:5000'],
                'assessment_notes' => ['nullable', 'string', 'max:5000'],
            ],
            'doctorExamination' => [
                'anamnese'             => ['nullable', 'string', 'max:5000'],
                'soap_subjective'      => ['nullable', 'string', 'max:5000'],
                'soap_objective'       => ['nullable', 'string', 'max:5000'],
                'soap_assessment'      => ['nullable', 'string', 'max:5000'],
                'soap_plan'            => ['nullable', 'string', 'max:5000'],
                'slitlamp_notes'       => ['nullable', 'string', 'max:5000'],
                'diagnosis_utama'      => ['nullable', 'string', 'max:255'],
                'diagnosis_sekunder'   => ['nullable', 'array'],
                'tindakan_codes'       => ['nullable', 'array'],
                'planning'             => ['nullable', 'string', 'max:2000'],
            ],
            'medicalResume' => [
                'resume_s' => ['nullable', 'string', 'max:5000'],
                'resume_o' => ['nullable', 'string', 'max:5000'],
                'resume_a' => ['nullable', 'string', 'max:5000'],
                'resume_p' => ['nullable', 'string', 'max:5000'],
            ],
            default => [],
        };
    }

    private function validationMessages(): array
    {
        return [
            '*.in'      => 'Nilai tidak valid (harus salah satu: :values).',
            '*.date'    => 'Format tanggal tidak valid.',
            '*.numeric' => 'Harus berupa angka.',
            '*.integer' => 'Harus berupa bilangan bulat.',
            '*.boolean' => 'Harus berupa boolean (true/false).',
            '*.between' => 'Nilai harus di antara :min dan :max.',
            '*.max'     => 'Maksimal :max karakter.',
            '*.regex'   => 'Format tidak valid.',
            '*.array'   => 'Harus berupa list.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function flattenFields(array $schema): array
    {
        if (isset($schema['fields']) && is_array($schema['fields'])) {
            return $schema['fields'];
        }
        if (isset($schema['pages']) && is_array($schema['pages'])) {
            $all = [];
            foreach ($schema['pages'] as $page) {
                if (isset($page['fields']) && is_array($page['fields'])) {
                    array_push($all, ...$page['fields']);
                }
            }
            return $all;
        }
        return [];
    }
}
