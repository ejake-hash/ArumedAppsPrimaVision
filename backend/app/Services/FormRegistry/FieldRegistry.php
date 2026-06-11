<?php

namespace App\Services\FormRegistry;

/**
 * Whitelist kolom DB yang boleh di-bind dari form.
 *
 * Aturan kunci:
 *   - Tidak ada DB introspection — developer tambah manual saat ada kolom
 *     baru yang boleh di-expose ke UI admin (Section 6 design doc).
 *   - Path "source" mengikuti relasi Eloquent dari Visit, mis. "patient.name"
 *     berarti Visit->patient->name. Diresolve oleh BindingResolver.
 *   - Field "clinic.*" diresolve dari ClinicProfile::first().
 */
final class FieldRegistry
{
    public static function columns(): array
    {
        return [
            'patient' => [
                'name'          => ['label' => 'Nama Pasien',      'type' => 'text'],
                'nik'           => ['label' => 'NIK',              'type' => 'text'],
                'no_rm'         => ['label' => 'No. Rekam Medis',  'type' => 'text'],
                'date_of_birth' => ['label' => 'Tanggal Lahir',    'type' => 'date'],
                'gender'        => ['label' => 'Jenis Kelamin',    'type' => 'enum'],
                'address'       => ['label' => 'Alamat',           'type' => 'longtext'],
                'province'      => ['label' => 'Provinsi',         'type' => 'text'],
                'phone'         => ['label' => 'No. Telepon',      'type' => 'text'],
                'bpjs_number'   => ['label' => 'No. BPJS',         'type' => 'text'],
                'blood_type'    => ['label' => 'Golongan Darah',   'type' => 'text'],
                'allergy_notes' => ['label' => 'Alergi Obat',      'type' => 'longtext'],
            ],

            'visit' => [
                'visit_date'             => ['label' => 'Tanggal Berobat',          'type' => 'date'],
                'classification'         => ['label' => 'Klasifikasi Kunjungan',    'type' => 'text'],
                'current_station'        => ['label' => 'Stasiun Saat Ini',         'type' => 'text'],
                'guarantor_type'         => ['label' => 'Penanggung Pembayaran',    'type' => 'enum'],
                'no_antreen'             => ['label' => 'No. Antrean',              'type' => 'text'],
                'no_sep'                 => ['label' => 'No. SEP BPJS',             'type' => 'text'],
                'planning_follow_up'     => ['label' => 'Rencana Kontrol Ulang',    'type' => 'boolean'],
                'follow_up_date'         => ['label' => 'Tanggal Kontrol',          'type' => 'date'],
                'follow_up_reason'       => ['label' => 'Alasan Kontrol',           'type' => 'longtext'],
                // Relasi nested — diresolve via dot-notation di BindingResolver
                'doctorExamination.doctor.name' => ['label' => 'Dokter yang Merawat', 'type' => 'text'],
                'doctorSchedule.poliklinik'     => ['label' => 'Ruang Poli',          'type' => 'text'],
                'insurer.name'           => ['label' => 'Nama Penjamin',            'type' => 'text'],
            ],

            'doctorExamination' => [
                'anamnese'             => ['label' => 'Anamnese',                  'type' => 'longtext'],
                'soap_subjective'      => ['label' => 'Subjective (SOAP)',         'type' => 'longtext'],
                'soap_objective'       => ['label' => 'Objective (SOAP)',          'type' => 'longtext'],
                'soap_assessment'      => ['label' => 'Assessment (SOAP)',         'type' => 'longtext'],
                'soap_plan'            => ['label' => 'Planning (SOAP)',           'type' => 'longtext'],
                'slitlamp_notes'       => ['label' => 'Catatan Slitlamp',          'type' => 'longtext'],
                'diagnosis_utama'      => ['label' => 'Diagnosa Utama (ICD-10)',   'type' => 'text'],
                'diagnosis_sekunder'   => ['label' => 'Diagnosa Sekunder (array)', 'type' => 'json'],
                'tindakan_codes'       => ['label' => 'Kode Tindakan (ICD-9)',     'type' => 'json'],
                'planning'             => ['label' => 'Rencana Tatalaksana',       'type' => 'text'],
            ],

            'nurseAssessment' => [
                'td_sistol'        => ['label' => 'TD Sistolik',       'type' => 'integer'],
                'td_diastol'       => ['label' => 'TD Diastolik',      'type' => 'integer'],
                'nadi'             => ['label' => 'Nadi',              'type' => 'integer'],
                'suhu'             => ['label' => 'Suhu Tubuh',        'type' => 'decimal'],
                'respirasi'        => ['label' => 'Respirasi',         'type' => 'integer'],
                'spo2'             => ['label' => 'SpO2',              'type' => 'decimal'],
                'kgd'              => ['label' => 'Gula Darah (KGD)',  'type' => 'decimal'],
                'pain_scale'       => ['label' => 'Skala Nyeri (0-10)','type' => 'integer'],
                'berat_badan'      => ['label' => 'Berat Badan',       'type' => 'decimal'],
                'tinggi_badan'     => ['label' => 'Tinggi Badan',      'type' => 'decimal'],
                'bmi'              => ['label' => 'BMI',               'type' => 'decimal'],
                'has_allergy'      => ['label' => 'Punya Alergi',      'type' => 'boolean'],
                'allergy_detail'   => ['label' => 'Detail Alergi',     'type' => 'longtext'],
                'chief_complaint'  => ['label' => 'Keluhan Utama',     'type' => 'longtext'],
                'rps'              => ['label' => 'Riwayat Penyakit Sekarang', 'type' => 'longtext'],
                'assessment_notes' => ['label' => 'Catatan Asesmen',   'type' => 'longtext'],
            ],

            'medicalResume' => [
                'resume_s'    => ['label' => 'Resume — S',  'type' => 'longtext'],
                'resume_o'    => ['label' => 'Resume — O',  'type' => 'longtext'],
                'resume_a'    => ['label' => 'Resume — A',  'type' => 'longtext'],
                'resume_p'    => ['label' => 'Resume — P',  'type' => 'longtext'],
            ],

            'clinic' => [
                'clinic_name'    => ['label' => 'Nama Klinik',       'type' => 'text'],
                'clinic_code'    => ['label' => 'Kode Klinik',       'type' => 'text'],
                'address'        => ['label' => 'Alamat Klinik',     'type' => 'longtext'],
                'phone'          => ['label' => 'Telp Klinik',       'type' => 'text'],
                'email'          => ['label' => 'Email Klinik',      'type' => 'text'],
                'director_name'  => ['label' => 'Direktur Klinik',   'type' => 'text'],
                'director_sip'   => ['label' => 'SIP Direktur',      'type' => 'text'],
                'logo_path'      => ['label' => 'Logo (path)',       'type' => 'image_url'],
            ],
        ];
    }

    public static function aggregates(): array
    {
        return [
            'doctorExamination.icd10_diagnoses' => [
                'label'   => 'Diagnosa ICD-10 (gabungan utama + sekunder)',
                'formats' => ['icd_with_desc_join_newline', 'icd_only_join_comma'],
            ],
            'doctorExamination.icd9_procedures' => [
                'label'   => 'Tindakan/Prosedur ICD-9 dokter (tindakan_codes) — untuk Resume Medis',
                'formats' => ['icd_with_desc_join_newline', 'icd_only_join_comma'],
            ],
            'claim.icd10_diagnoses' => [
                'label'   => 'Diagnosa ICD-10 dari KODING KLAIM (koder) — untuk Lembar Klaim',
                'formats' => ['icd_with_desc_join_newline', 'icd_only_join_comma'],
            ],
            'claim.icd9_procedures' => [
                'label'   => 'Prosedur ICD-9 dari KODING KLAIM (koder) — untuk Lembar Klaim',
                'formats' => ['icd_with_desc_join_newline', 'icd_only_join_comma'],
            ],
            'prescriptions' => [
                'label'   => 'Daftar Resep Obat',
                'formats' => ['items_pretty', 'items_table_html'],
            ],
            'visitServices' => [
                'label'   => 'Daftar Tindakan',
                'formats' => ['list_simple', 'list_with_tarif'],
            ],
            'diagnosticResults.summary' => [
                'label'   => 'Ringkasan Hasil Penunjang',
                'formats' => ['summary_per_jenis'],
            ],
            'surgery_iol_usage' => [
                'label'   => 'IOL/Implan Terpasang (scan UDI) — Stiker Implant',
                'formats' => ['implant_lines'],
            ],
            'surgery_operation_summary' => [
                'label'   => 'Ringkasan Operasi (teknik/temuan/komplikasi) — narasi RM 2.2',
                'formats' => ['narrative'],
            ],
            'surgery_identity' => [
                'label'   => 'Identitas Operasi (operator/asisten/jam/durasi/anestesi) — dari BedahView',
                'formats' => ['operator', 'asisten', 'asisten1', 'asisten2', 'anesthesiologist', 'anesthesia_type', 'procedure', 'diagnosis_post', 'time_in', 'time_out', 'duration'],
            ],
            'planning_instruction' => [
                'label'   => 'Instruksi/Anjuran dari Rencana Tatalaksana (planning) — kalimat siap-pakai',
                'formats' => ['text'],
            ],
            'physical_exam' => [
                'label'   => 'Pemeriksaan Fisik = TTV triase (fallback) + refraksi objektif (RO/soap_o) + segmen mata dokter (soap_objective)',
                'formats' => ['text'],
            ],
            'allergy' => [
                'label'   => 'Alergi Obat = detail alergi triase, fallback catatan alergi master pasien',
                'formats' => ['text'],
            ],
        ];
    }

    /**
     * Cek apakah path binding `db` valid (terdaftar di whitelist).
     * Format path: "resource.column" atau "resource.relation.column".
     */
    public static function isValidDbPath(string $path): bool
    {
        $parts = explode('.', $path, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$resource, $rest] = $parts;
        $columns = self::columns();
        return isset($columns[$resource][$rest]);
    }

    public static function isValidAggregate(string $key): bool
    {
        return array_key_exists($key, self::aggregates());
    }
}
