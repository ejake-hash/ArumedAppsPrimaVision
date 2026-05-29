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
        'patient_id',
        'insurer_id',
        'registered_by_id',
        'doctor_schedule_id',
        'no_antreen',
        'no_registrasi',
        'no_sep',
        'photo_path',
        'visit_date',
        'classification',
        'visit_type',
        'surgery_schedule_id',
        'current_station',
        'guarantor_type',
        'triase_completed_at',
        'refraksi_completed_at',
        'ready_for_doctor',
        'bpjs_booking_code',
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
    ];

    protected $casts = [
        'visit_date'            => 'date',
        'triase_completed_at'   => 'datetime',
        'refraksi_completed_at' => 'datetime',
        'satusehat_synced_at'   => 'datetime',
        'ready_for_doctor'      => 'boolean',
        'planning_follow_up'    => 'boolean',
        'follow_up_date'        => 'date',
        'insurance_verified_at' => 'datetime',
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

    public function visitServices(): HasMany
    {
        return $this->hasMany(VisitService::class);
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
}
