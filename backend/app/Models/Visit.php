<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Visit extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'legacy_uuid',
        'patient_id',
        'insurer_id',
        'registered_by_id',
        'doctor_schedule_id',
        'no_antreen',
        'no_registrasi',
        'no_sep',
        'sep_data',
        'sep_status',
        'sep_issuing_at',
        'photo_path',
        'visit_date',
        'classification',
        'visit_type',
        'surgery_schedule_id',
        'parent_visit_id',
        'internal_referral_from_schedule_id',
        'internal_referral_reason',
        'current_station',
        'guarantor_type',
        // --- Rawat Inap (RANAP) + IGD (data-only) ---
        'jenis_pelayanan',
        'inpatient_reason',
        'kelas_rawat_hak',
        'kelas_rawat',
        'admission_at',
        'discharge_at',
        'discharge_type',
        'discharge_summary',
        'triase_level',
        'triase_color',
        'igd_arrival_at',
        'igd_disposition',
        'ranap_room_id',
        'ranap_bed_id',
        'dpjp_employee_id',
        // --- end RANAP/IGD ---
        'triase_completed_at',
        'refraksi_completed_at',
        'ready_for_doctor',
        'bpjs_booking_code',
        'no_rujukan',
        'no_surat_kontrol',
        'diagnosa_awal',
        'diagnosa_awal_nama',
        'bpjs_antrean_number',
        'bpjs_referral_in_id',
        'bpjs_control_letter_id',
        'satusehat_encounter_id',
        'satusehat_sync_status',
        'satusehat_synced_at',
        'planning_follow_up',
        'follow_up_date',
        'follow_up_reason',
        'insurance_verification_status',
        'insurance_verified_at',
        // --- Screening pra-klaim (Rekap Kunjungan BPJS) ---
        'berkas_lengkap',
        'berkas_lengkap_by',
        'berkas_lengkap_at',
        'rekap_keterangan',
        // --- Pipeline berkas klaim (Rekap → Kirim → Berkas → Kembalikan) ---
        'klaim_sent_at',
        'klaim_sent_by',
        'klaim_returned_at',
        'klaim_return_note',
    ];

    protected $casts = [
        'visit_date'            => 'date',
        'sep_data'              => 'array',
        'sep_issuing_at'        => 'datetime',
        'triase_completed_at'   => 'datetime',
        'refraksi_completed_at' => 'datetime',
        'satusehat_synced_at'   => 'datetime',
        'ready_for_doctor'      => 'boolean',
        'planning_follow_up'    => 'boolean',
        'follow_up_date'        => 'date',
        'insurance_verified_at' => 'datetime',
        'klaim_sent_at'         => 'datetime',
        'klaim_returned_at'     => 'datetime',
        // --- Rawat Inap (RANAP) + IGD ---
        'admission_at'          => 'datetime',
        'discharge_at'          => 'datetime',
        'igd_arrival_at'        => 'datetime',
        'berkas_lengkap'        => 'boolean',
        'berkas_lengkap_at'     => 'datetime',
    ];

    /** Foto kunjungan ini ikut diserialisasi (riwayat kunjungan per-tanggal). */
    protected $appends = ['photo_url'];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? Storage::disk('public')->url($this->photo_path)
            : null;
    }

    // --- Scopes ---

    public function scopeHasFollowUp($query)
    {
        return $query->where('planning_follow_up', true);
    }

    public function scopeFollowUpToday($query)
    {
        return $query->where('planning_follow_up', true)
                     ->whereDate('follow_up_date', today());
    }

    public function scopeFollowUpThisWeek($query)
    {
        return $query->where('planning_follow_up', true)
                     ->whereBetween('follow_up_date', [today(), today()->addDays(7)]);
    }

    // --- Relationships ---

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'registered_by_id');
    }

    public function doctorSchedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class);
    }

    public function surgerySchedule(): BelongsTo
    {
        return $this->belongsTo(SurgerySchedule::class);
    }

    /** Visit induk (jika kunjungan ini hasil rujukan internal antar-poli). */
    public function parentVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'parent_visit_id');
    }

    /** Visit anak hasil rujukan internal dari kunjungan ini. */
    public function childVisits(): HasMany
    {
        return $this->hasMany(Visit::class, 'parent_visit_id');
    }

    /** Jadwal dokter/poli ASAL rujukan internal (untuk label "Rujukan dari Poli X"). */
    public function internalReferralFromSchedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class, 'internal_referral_from_schedule_id');
    }

    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class);
    }

    public function visitCob(): HasOne
    {
        return $this->hasOne(VisitCob::class);
    }

    public function nurseAssessment(): HasOne
    {
        return $this->hasOne(NurseAssessment::class);
    }

    public function cpptEntries(): HasMany
    {
        return $this->hasMany(NurseCpptEntry::class)->orderByDesc('created_at');
    }

    public function refractionRecord(): HasOne
    {
        return $this->hasOne(RefractionRecord::class);
    }

    public function doctorExamination(): HasOne
    {
        return $this->hasOne(DoctorExamination::class);
    }

    /**
     * Semua snapshot paket pasien (bedah/pemeriksaan/anestesi) untuk visit ini.
     * Satu visit boleh punya >1 paket (mis. Phaco + TIVA) — dasar diskon paket
     * per-paket di kwitansi.
     */
    public function surgeryPackageSnapshots(): HasMany
    {
        return $this->hasMany(VisitSurgeryPackage::class);
    }

    /**
     * Snapshot paket TUNGGAL (terbaru) — backward-compat alur lama 1 paket/visit.
     * Builder kasir & UI multi-paket memakai surgeryPackageSnapshots() (jamak).
     * PG tidak punya MAX(uuid) → pakai orderByDesc(created_at), bukan latestOfMany().
     */
    public function surgeryPackageSnapshot(): HasOne
    {
        return $this->hasOne(VisitSurgeryPackage::class)->orderByDesc('created_at');
    }

    public function visitServices(): HasMany
    {
        return $this->hasMany(VisitService::class);
    }

    /** BHP yang diinput dokter pada kunjungan (sumber tagihan BHP non-bedah). */
    public function bhpUsages(): HasMany
    {
        return $this->hasMany(VisitBhpUsage::class);
    }

    public function medicalResume(): HasOne
    {
        return $this->hasOne(MedicalResume::class);
    }

    public function diagnosticOrders(): HasMany
    {
        return $this->hasMany(DiagnosticOrder::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function billingInvoice(): HasOne
    {
        return $this->hasOne(BillingInvoice::class);
    }

    public function bpjsClaim(): HasOne
    {
        return $this->hasOne(BpjsClaim::class);
    }

    public function patientDocuments(): HasMany
    {
        return $this->hasMany(PatientDocument::class);
    }

    public function surgeryRequests(): HasMany
    {
        return $this->hasMany(SurgeryRequest::class);
    }

    public function equipmentUsages(): HasMany
    {
        return $this->hasMany(MedicalEquipmentUsage::class);
    }

    public function iolRecommendations(): HasMany
    {
        return $this->hasMany(IolRecommendation::class);
    }

    public function insuranceVerifications(): HasMany
    {
        return $this->hasMany(InsuranceVerification::class)->orderByDesc('created_at');
    }

    public function latestInsuranceVerification(): HasOne
    {
        // PG tidak punya MAX(uuid) → tidak bisa pakai latestOfMany().
        // Pakai hasOne + orderByDesc(created_at) sebagai workaround.
        return $this->hasOne(InsuranceVerification::class)->orderByDesc('created_at');
    }

    public function insuranceClaims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }

    // --- Rawat Inap (RANAP) ---

    /** Room tempat dirawat (cache denormalized via ranap_room_id). */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'ranap_room_id');
    }

    /** Bed tempat dirawat (cache denormalized via ranap_bed_id). */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'ranap_bed_id');
    }

    /** DPJP rawat inap. */
    public function dpjp(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'dpjp_employee_id');
    }

    /**
     * Nama dokter penanggung jawab (DPJP) terpadu: RANAP pakai kolom dpjp,
     * RAJAL/IGD pakai dokter pemeriksa (doctorExamination) lalu dokter jadwal.
     * Tidak di-append global — panggil ->append('dpjp_name') setelah eager-load
     * relasi (dpjp, doctorExamination.doctor, doctorSchedule.employee) agar bebas N+1.
     */
    public function getDpjpNameAttribute(): ?string
    {
        return $this->dpjp?->name
            ?? $this->doctorExamination?->doctor?->name
            ?? $this->doctorSchedule?->employee?->name;
    }

    /**
     * Status obat untuk badge Kasir — bedakan kunjungan yang ADA resep obat
     * (lewat antrean Farmasi) dari yang tidak, sekaligus apakah Farmasi sudah
     * memverifikasi & mengunci resepnya (gate sebelum tagihan; lihat
     * KasirService::consolidateBilling). Resep RANAP (ditagih via inpatient_charges)
     * & CANCELLED diabaikan — selaras logika gate.
     *
     * Nilai: null = tidak ada resep obat | 'VERIFIED' = ada & SEMUA terverifikasi
     * Farmasi | 'PENDING' = ada tapi sebagian/semua belum diverifikasi.
     *
     * Tidak di-append global — panggil ->append('obat_status') setelah eager-load
     * relasi `prescriptions` agar bebas N+1.
     */
    public function getObatStatusAttribute(): ?string
    {
        $relevan = $this->prescriptions
            ->filter(fn ($p) => $p->type !== Prescription::TYPE_RANAP && $p->status !== 'CANCELLED');

        if ($relevan->isEmpty()) {
            return null;
        }

        return $relevan->every(fn ($p) => ! is_null($p->verified_at)) ? 'VERIFIED' : 'PENDING';
    }

    public function bedAssignments(): HasMany
    {
        return $this->hasMany(BedAssignment::class)->orderBy('assigned_at');
    }

    /** Penempatan bed aktif saat ini (released_at null). */
    public function activeBedAssignment(): HasOne
    {
        return $this->hasOne(BedAssignment::class)->whereNull('released_at');
    }

    public function inpatientCharges(): HasMany
    {
        return $this->hasMany(InpatientCharge::class)->orderBy('charge_date');
    }

    public function igdTriageRecord(): HasOne
    {
        return $this->hasOne(IgdTriageRecord::class);
    }

    /** Asesmen Gawat Darurat (RM 3.7) terstruktur untuk kunjungan IGD. */
    public function igdAssessment(): HasOne
    {
        return $this->hasOne(IgdAssessment::class);
    }

    /** SPRI (Surat Perintah Rawat Inap) BPJS yang pernah dibuat untuk kunjungan ini. */
    public function spris(): HasMany
    {
        return $this->hasMany(BpjsSpri::class)->orderByDesc('created_at');
    }

    public function latestSpri(): HasOne
    {
        // Plain hasOne diurutkan created_at desc — hindari latestOfMany() yang
        // memakai MAX(id) (id = UUID, Postgres tak punya MAX(uuid)).
        return $this->hasOne(BpjsSpri::class)->latest('created_at');
    }
}
