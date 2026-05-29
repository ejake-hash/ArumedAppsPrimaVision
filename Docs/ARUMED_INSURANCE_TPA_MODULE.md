# Arumed Apps — Modul Asuransi / TPA (Non-BPJS)

> **Status:** Spesifikasi implementasi baru — belum ada di codebase saat ini.
> **Target:** Extend project yang sudah ada tanpa breaking existing flow.
> **Dibuat:** 2026-05-26

---

## Daftar Isi

1. [Konteks & Gap Analysis](#1-konteks--gap-analysis)
2. [Ringkasan Alur Penggunaan](#2-ringkasan-alur-penggunaan)
3. [Database — Migration Baru](#3-database--migration-baru)
4. [Perubahan Tabel Existing](#4-perubahan-tabel-existing)
5. [Models Baru](#5-models-baru)
6. [Services Baru](#6-services-baru)
7. [Controller & Endpoints Baru](#7-controller--endpoints-baru)
8. [Perubahan di Controller Existing](#8-perubahan-di-controller-existing)
9. [Frontend — Komponen & Store Baru](#9-frontend--komponen--store-baru)
10. [Skenario Edge Cases](#10-skenario-edge-cases)
11. [Urutan Implementasi (Development Order)](#11-urutan-implementasi-development-order)

---

## 1. Konteks & Gap Analysis

### Yang Sudah Ada (Jangan Dibuat Ulang)

| Tabel / Komponen | Status | Catatan |
|---|---|---|
| `insurers` | ✅ Ada | Master penjamin. Perlu tambah 3 kolom saja |
| `visit_cob` | ✅ Ada | COB multi-penjamin sudah handle |
| `procedure_tariffs` / `medication_tariffs` | ✅ Ada | Tarif per insurer sudah ada |
| `billing_invoices` | ✅ Ada | Basis untuk draft klaim |
| `bpjs_claims` | ⚠️ Ada tapi khusus BPJS | Tidak bisa di-reuse untuk TPA swasta |
| `KlaimController` | ⚠️ Ada tapi khusus BPJS | Buat controller terpisah `AsuransiController` |

### Yang Belum Ada (Harus Dibuat)

| Item | Keterangan |
|---|---|
| `insurance_verifications` | Tabel baru: track hasil eligibility per kunjungan |
| `insurance_claims` | Tabel baru: workflow klaim TPA non-BPJS |
| `insurer_document_requirements` | Tabel baru: checklist dokumen per TPA |
| `insurance_claim_logs` | Tabel baru: audit trail status klaim |
| `insurance_verification_status` | Kolom tambahan di `visits` |
| `portal_url`, `pic_contact`, `claim_notes` | Kolom tambahan di `insurers` |
| `AsuransiService` | Service baru |
| `AsuransiController` | Controller baru, route prefix `/asuransi` |
| `asuransiStore` (Pinia) | Store baru di frontend |
| UI flag di AdmisiView | Status badge verifikasi asuransi |
| UI modul klaim tracking | View baru `AsuransiView.vue` |

---

## 2. Ringkasan Alur Penggunaan

### 2.1 Alur Pasien Asuransi (Happy Path)

```
[KIOSK]
Pasien datang → ambil nomor antrian ke ADMISI
(Kiosk tidak input data apapun — tidak berubah dari alur existing)
    ↓
[ADMISI — petugas admisi eksekusi semua input]
Panggil nomor antrian pasien
    ↓
Input data registrasi seperti biasa (nama, NIK, dll)
    ↓
Pilih guarantor_type = ASURANSI / PERUSAHAAN
    ↓
Input: insurer_id, nomor polis, nama peserta, nomor kartu
    ↓
Sistem otomatis set:
  visits.insurance_verification_status = PENDING
    ↓
Petugas admisi tekan "Selesai" → pasien masuk antrian TR
    ↓
[VERIFIKASI PARALEL — petugas admisi atau billing, sambil pasien menunggu dokter]
Buka portal TPA secara manual (di luar sistem)
    ↓
Cek eligibility: aktif? plafon berapa? ada exclusion?
    ↓
Input hasil verifikasi ke sistem:
  POST /asuransi/verifikasi
  → buat insurance_verifications record
  → update visits.insurance_verification_status = VERIFIED / ISSUE
    ↓
Jika ISSUE → sistem kirim alert ke supervisor (via notifikasi internal)
    ↓
[KONSULTASI — dokter tidak berubah sama sekali]
Dokter input diagnosa + tindakan seperti biasa
    ↓
[KASIR]
KasirService generate invoice seperti biasa
    ↓
Jika insurance_verification_status = VERIFIED:
  - hitung co-payment (jika ada)
  - pasien bayar selisih saja / gratis jika fully covered
  - cetak bukti klaim sementara
  - PASIEN PULANG
    ↓
[BACK-OFFICE — setelah pasien pulang]
Billing lengkapi dokumen sesuai checklist TPA
    ↓
Input ke portal TPA (di luar sistem)
    ↓
Catat di sistem:
  POST /asuransi/klaim/{id}/submit
  → update status = SUBMITTED
  → simpan nomor referensi portal TPA
    ↓
Monitor approval:
  PUT /asuransi/klaim/{id}/status
  → APPROVED / REJECTED
    ↓
Jika REJECTED:
  → catat alasan
  → revisi dokumen
  → resubmit (resubmission_count + 1)
    ↓
Jika APPROVED:
  → input approved_amount
  → rekonsiliasi dengan billing_invoices
```

### 2.2 Alur Pasien — Kartu Tidak Bisa Cover

```
[Titik 1: Ketahuan saat verifikasi paralel — TERBAIK]
Status = ISSUE → sistem alert supervisor
    ↓
Supervisor komunikasi ke pasien SEBELUM masuk dokter
    ↓
Pasien setuju bayar mandiri → update guarantor_type = UMUM di visit
atau pasien reschedule → cancel kunjungan

[Titik 2: Ketahuan setelah konsultasi, sebelum kasir]
Kasir lihat status = ISSUE / PENDING
    ↓
Supervisor komunikasi ke pasien dengan breakdown biaya
    ↓
Pasien setuju → update invoice ke UMUM
Pasien keberatan → hubungi TPA untuk klarifikasi, beri waktu

[Titik 3: Ketahuan setelah klaim disubmit — TERBURUK]
Status klaim = REJECTED
    ↓
Billing triage alasan reject:
  - Data salah → resubmit dengan koreksi
  - Exclusion → tagih ke pasien
  - Plafon habis → tagih selisih ke pasien
    ↓
Hubungi pasien via WA/telepon
    ↓
Beri waktu pembayaran yang wajar
    ↓
Update insurance_claim_logs dengan resolusi
```

### 2.3 Status State Machine

```
insurance_verifications.status:
  PENDING → VERIFIED
  PENDING → NEEDS_CLARIFICATION
  NEEDS_CLARIFICATION → VERIFIED
  NEEDS_CLARIFICATION → REJECTED (eligibility tidak aktif)

insurance_claims.status:
  DRAFT → SUBMITTED → APPROVED
  DRAFT → SUBMITTED → REJECTED → DRAFT (revisi)
  SUBMITTED → APPEALED → APPROVED
  SUBMITTED → APPEALED → REJECTED

visits.insurance_verification_status:
  NONE     → default; hanya untuk guarantor_type = UMUM / BPJS
  PENDING  → di-set otomatis oleh AdmisiService saat petugas admisi finalize
             dengan guarantor_type = ASURANSI / PERUSAHAAN
  VERIFIED → setelah billing input hasil cek portal TPA (status OK)
  ISSUE    → setelah billing input hasil cek portal TPA (ada masalah)

  ⚠ Kiosk tidak menyentuh insurance_verification_status sama sekali.
     Kiosk hanya terbitkan nomor antrian ke stasiun ADMISI.
```

---

## 3. Database — Migration Baru

> **Aturan:** UUID primary key, soft delete, timestamps — konsisten dengan konvensi existing.
> **Urutan migration harus diikuti** karena ada foreign key dependency.

### Migration 1: Tambah kolom di `insurers`

```php
// database/migrations/XXXX_add_tpa_fields_to_insurers_table.php

Schema::table('insurers', function (Blueprint $table) {
    $table->string('portal_url')->nullable()->after('name');
    $table->string('pic_name')->nullable()->after('portal_url');
    $table->string('pic_phone')->nullable()->after('pic_name');
    $table->string('pic_email')->nullable()->after('pic_phone');
    $table->text('claim_submission_notes')->nullable()->after('pic_email');
    // Berapa hari SLA approval klaim (untuk aging alert)
    $table->integer('sla_days')->default(14)->after('claim_submission_notes');
});
```

### Migration 2: Tambah kolom di `visits`

```php
// database/migrations/XXXX_add_insurance_verification_status_to_visits_table.php

Schema::table('visits', function (Blueprint $table) {
    // NONE = tidak perlu verifikasi (UMUM/BPJS)
    // PENDING = menunggu verifikasi billing
    // VERIFIED = sudah terkonfirmasi aktif & cover
    // ISSUE = ada masalah (tidak aktif / tidak cover)
    $table->enum('insurance_verification_status', [
        'NONE', 'PENDING', 'VERIFIED', 'ISSUE'
    ])->default('NONE')->after('insurer_id');

    $table->timestamp('insurance_verified_at')->nullable()->after('insurance_verification_status');
});
```

### Migration 3: Tabel `insurer_document_requirements`

```php
// database/migrations/XXXX_create_insurer_document_requirements_table.php

Schema::create('insurer_document_requirements', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->uuid('insurer_id');
    $table->foreign('insurer_id')->references('id')->on('insurers')->onDelete('cascade');

    $table->string('document_name');           // "Surat Rujukan", "Resume Medis", "Kwitansi Asli", dll
    $table->boolean('is_required')->default(true);
    $table->text('notes')->nullable();         // Keterangan tambahan (format, jumlah rangkap, dll)
    $table->integer('sort_order')->default(0);

    $table->timestamps();
    $table->softDeletes();
});
```

### Migration 4: Tabel `insurance_verifications`

```php
// database/migrations/XXXX_create_insurance_verifications_table.php

Schema::create('insurance_verifications', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->uuid('visit_id');
    $table->foreign('visit_id')->references('id')->on('visits')->onDelete('cascade');
    $table->uuid('insurer_id');
    $table->foreign('insurer_id')->references('id')->on('insurers');
    $table->uuid('verified_by')->nullable();   // FK ke users (billing yang verifikasi)
    $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();

    // Status eligibility
    $table->enum('status', [
        'PENDING',
        'VERIFIED',
        'NEEDS_CLARIFICATION',
        'REJECTED'                             // Kartu tidak aktif / tidak cover sama sekali
    ])->default('PENDING');

    // Coverage info
    $table->string('policy_number')->nullable();
    $table->string('member_name')->nullable();
    $table->decimal('plafon_amount', 15, 2)->nullable();    // NULL = unlimited / tidak diketahui
    $table->decimal('copayment_percent', 5, 2)->default(0); // % yang ditanggung pasien
    $table->decimal('copayment_amount', 15, 2)->default(0); // Nominal co-payment jika fix

    // Coverage notes & exclusion
    $table->text('coverage_notes')->nullable();
    $table->jsonb('exclusion_flags')->nullable();            // ["KACAMATA", "LENSA_KONTAK", "REFRACTIVE_SURGERY"]

    // Jika ada issue
    $table->text('issue_notes')->nullable();                 // Penjelasan jika status = ISSUE / NEEDS_CLARIFICATION

    $table->timestamp('verified_at')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

### Migration 5: Tabel `insurance_claims`

```php
// database/migrations/XXXX_create_insurance_claims_table.php

Schema::create('insurance_claims', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->uuid('visit_id');
    $table->foreign('visit_id')->references('id')->on('visits')->onDelete('cascade');
    $table->uuid('insurer_id');
    $table->foreign('insurer_id')->references('id')->on('insurers');
    $table->uuid('billing_invoice_id')->nullable();
    $table->foreign('billing_invoice_id')->references('id')->on('billing_invoices')->nullOnDelete();
    $table->uuid('insurance_verification_id')->nullable();
    $table->foreign('insurance_verification_id')->references('id')->on('insurance_verifications')->nullOnDelete();
    $table->uuid('submitted_by')->nullable();
    $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();

    // Status workflow
    $table->enum('status', [
        'DRAFT',
        'SUBMITTED',
        'APPROVED',
        'REJECTED',
        'APPEALED'
    ])->default('DRAFT');

    // Nominal
    $table->decimal('claim_amount', 15, 2)->default(0);       // Total yang diklaim ke TPA
    $table->decimal('approved_amount', 15, 2)->nullable();     // Yang disetujui TPA (diisi saat APPROVED)
    $table->decimal('patient_responsibility', 15, 2)->default(0); // Copay / selisih yang ditanggung pasien

    // Submission tracking
    $table->string('submission_ref')->nullable();              // Nomor referensi dari portal TPA
    $table->timestamp('submitted_at')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->timestamp('rejected_at')->nullable();

    // Dokumen checklist — JSONB, key = document_name, value = boolean (sudah dilengkapi?)
    // Contoh: {"Resume Medis": true, "Kwitansi Asli": false, "Surat Rujukan": true}
    $table->jsonb('documents_checklist')->nullable();

    // Reject & appeal
    $table->string('rejection_code')->nullable();
    $table->text('rejection_reason')->nullable();
    $table->integer('resubmission_count')->default(0);         // Berapa kali sudah disubmit ulang
    $table->text('appeal_notes')->nullable();

    $table->text('notes')->nullable();                         // Catatan internal billing

    $table->timestamps();
    $table->softDeletes();
});
```

### Migration 6: Tabel `insurance_claim_logs`

```php
// database/migrations/XXXX_create_insurance_claim_logs_table.php

Schema::create('insurance_claim_logs', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->uuid('insurance_claim_id');
    $table->foreign('insurance_claim_id')->references('id')->on('insurance_claims')->onDelete('cascade');
    $table->uuid('performed_by')->nullable();
    $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();

    $table->string('action');            // CREATED, SUBMITTED, APPROVED, REJECTED, APPEALED, RESUBMITTED, NOTE_ADDED
    $table->string('from_status')->nullable();
    $table->string('to_status')->nullable();
    $table->text('notes')->nullable();
    $table->jsonb('metadata')->nullable(); // Data tambahan (submission_ref, rejection_code, dll)

    $table->timestamp('performed_at');
    $table->timestamps();
    // Tidak ada softDeletes — log tidak boleh dihapus
});
```

---

## 4. Perubahan Tabel Existing

### `visits` — Tambah kolom

Sudah tercantum di Migration 2 di atas. Pastikan `AdmisiService` diupdate:

```php
// Di AdmisiService::createVisit() — setelah set insurer_id
if (in_array($data['guarantor_type'], ['ASURANSI', 'PERUSAHAAN'])) {
    $visit->insurance_verification_status = 'PENDING';
} else {
    $visit->insurance_verification_status = 'NONE';
}
```

### `insurers` — Tambah kolom

Sudah tercantum di Migration 1. `MasterDataController` perlu diupdate untuk CRUD kolom baru (portal_url, pic_*, sla_days).

---

## 5. Models Baru

### `InsuranceVerification.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InsuranceVerification extends Model
{
    use SoftDeletes, HasUuids;

    protected $fillable = [
        'visit_id', 'insurer_id', 'verified_by', 'status',
        'policy_number', 'member_name', 'plafon_amount',
        'copayment_percent', 'copayment_amount',
        'coverage_notes', 'exclusion_flags', 'issue_notes', 'verified_at',
    ];

    protected $casts = [
        'exclusion_flags'   => 'array',
        'plafon_amount'     => 'decimal:2',
        'copayment_percent' => 'decimal:2',
        'copayment_amount'  => 'decimal:2',
        'verified_at'       => 'datetime',
    ];

    public function visit()       { return $this->belongsTo(Visit::class); }
    public function insurer()     { return $this->belongsTo(Insurer::class); }
    public function verifiedBy()  { return $this->belongsTo(User::class, 'verified_by'); }
    public function claims()      { return $this->hasMany(InsuranceClaim::class); }
}
```

### `InsuranceClaim.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InsuranceClaim extends Model
{
    use SoftDeletes, HasUuids;

    protected $fillable = [
        'visit_id', 'insurer_id', 'billing_invoice_id',
        'insurance_verification_id', 'submitted_by', 'status',
        'claim_amount', 'approved_amount', 'patient_responsibility',
        'submission_ref', 'submitted_at', 'approved_at', 'rejected_at',
        'documents_checklist', 'rejection_code', 'rejection_reason',
        'resubmission_count', 'appeal_notes', 'notes',
    ];

    protected $casts = [
        'documents_checklist'   => 'array',
        'claim_amount'          => 'decimal:2',
        'approved_amount'       => 'decimal:2',
        'patient_responsibility'=> 'decimal:2',
        'submitted_at'          => 'datetime',
        'approved_at'           => 'datetime',
        'rejected_at'           => 'datetime',
    ];

    public function visit()        { return $this->belongsTo(Visit::class); }
    public function insurer()      { return $this->belongsTo(Insurer::class); }
    public function invoice()      { return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id'); }
    public function verification() { return $this->belongsTo(InsuranceVerification::class, 'insurance_verification_id'); }
    public function submittedBy()  { return $this->belongsTo(User::class, 'submitted_by'); }
    public function logs()         { return $this->hasMany(InsuranceClaimLog::class); }
}
```

### `InsuranceClaimLog.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InsuranceClaimLog extends Model
{
    use HasUuids;
    // Tidak ada SoftDeletes — log immutable

    protected $fillable = [
        'insurance_claim_id', 'performed_by', 'action',
        'from_status', 'to_status', 'notes', 'metadata', 'performed_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'performed_at' => 'datetime',
    ];

    public function claim()       { return $this->belongsTo(InsuranceClaim::class); }
    public function performedBy() { return $this->belongsTo(User::class, 'performed_by'); }
}
```

### `InsurerDocumentRequirement.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InsurerDocumentRequirement extends Model
{
    use SoftDeletes, HasUuids;

    protected $fillable = [
        'insurer_id', 'document_name', 'is_required', 'notes', 'sort_order',
    ];

    protected $casts = ['is_required' => 'boolean'];

    public function insurer() { return $this->belongsTo(Insurer::class); }
}
```

---

## 6. Services Baru

### `AsuransiService.php` — Struktur & Method

```php
namespace App\Services;

use App\Models\{InsuranceVerification, InsuranceClaim, InsuranceClaimLog, Visit, Notification};

class AsuransiService
{
    // ─── VERIFIKASI ───────────────────────────────────────────────

    /**
     * Input hasil verifikasi eligibility dari portal TPA.
     * Dipanggil oleh billing setelah cek manual ke portal TPA.
     */
    public function createVerifikasi(array $data, string $userId): InsuranceVerification
    {
        $verif = InsuranceVerification::create([
            ...$data,
            'verified_by' => $userId,
            'verified_at' => now(),
        ]);

        // Update status di visits
        $statusMap = [
            'VERIFIED'           => 'VERIFIED',
            'NEEDS_CLARIFICATION'=> 'ISSUE',
            'REJECTED'           => 'ISSUE',
        ];

        Visit::where('id', $data['visit_id'])->update([
            'insurance_verification_status' => $statusMap[$data['status']] ?? 'ISSUE',
            'insurance_verified_at'         => now(),
        ]);

        // Jika ada issue → kirim notifikasi ke supervisor
        if (in_array($data['status'], ['NEEDS_CLARIFICATION', 'REJECTED'])) {
            $this->notifySupervisor($data['visit_id'], $data['issue_notes'] ?? '');
        }

        return $verif;
    }

    public function updateVerifikasi(string $verifId, array $data): InsuranceVerification
    {
        $verif = InsuranceVerification::findOrFail($verifId);
        $verif->update($data);

        // Sync status ke visits jika status berubah
        if (isset($data['status'])) {
            $statusMap = ['VERIFIED' => 'VERIFIED', 'NEEDS_CLARIFICATION' => 'ISSUE', 'REJECTED' => 'ISSUE'];
            Visit::where('id', $verif->visit_id)->update([
                'insurance_verification_status' => $statusMap[$data['status']] ?? 'ISSUE',
            ]);
        }

        return $verif->fresh();
    }

    // ─── KLAIM ───────────────────────────────────────────────────

    /**
     * Buat draft klaim dari data billing_invoice yang sudah ada.
     * Otomatis populate checklist dokumen dari insurer_document_requirements.
     */
    public function createDraftKlaim(array $data, string $userId): InsuranceClaim
    {
        // Auto-populate checklist dari master requirement TPA
        $requirements = \App\Models\InsurerDocumentRequirement::where('insurer_id', $data['insurer_id'])
            ->orderBy('sort_order')
            ->get();

        $checklist = [];
        foreach ($requirements as $req) {
            $checklist[$req->document_name] = false; // Semua belum dilengkapi
        }

        $claim = InsuranceClaim::create([
            ...$data,
            'status'              => 'DRAFT',
            'documents_checklist' => $checklist,
        ]);

        $this->addLog($claim->id, $userId, 'CREATED', null, 'DRAFT');

        return $claim;
    }

    /**
     * Submit klaim ke TPA.
     * Panggil setelah billing input klaim ke portal TPA dan dapat nomor referensi.
     */
    public function submitKlaim(string $claimId, array $data, string $userId): InsuranceClaim
    {
        $claim = InsuranceClaim::findOrFail($claimId);

        // Validasi: semua dokumen required harus dilengkapi
        $this->validateDocumentChecklist($claim);

        $claim->update([
            'status'         => 'SUBMITTED',
            'submission_ref' => $data['submission_ref'],
            'submitted_by'   => $userId,
            'submitted_at'   => now(),
            'notes'          => $data['notes'] ?? $claim->notes,
        ]);

        $this->addLog($claim->id, $userId, 'SUBMITTED', 'DRAFT', 'SUBMITTED', [
            'submission_ref' => $data['submission_ref'],
        ]);

        return $claim->fresh();
    }

    /**
     * Update status klaim (APPROVED / REJECTED).
     * Dipanggil billing setelah monitor portal TPA.
     */
    public function updateStatusKlaim(string $claimId, array $data, string $userId): InsuranceClaim
    {
        $claim = InsuranceClaim::findOrFail($claimId);
        $fromStatus = $claim->status;

        $updateData = ['status' => $data['status']];

        if ($data['status'] === 'APPROVED') {
            $updateData['approved_amount'] = $data['approved_amount'];
            $updateData['approved_at']     = now();
        }

        if ($data['status'] === 'REJECTED') {
            $updateData['rejection_code']   = $data['rejection_code'] ?? null;
            $updateData['rejection_reason'] = $data['rejection_reason'];
            $updateData['rejected_at']      = now();
        }

        $claim->update($updateData);

        $this->addLog($claim->id, $userId, $data['status'], $fromStatus, $data['status'], [
            'rejection_code'   => $data['rejection_code'] ?? null,
            'rejection_reason' => $data['rejection_reason'] ?? null,
            'approved_amount'  => $data['approved_amount'] ?? null,
        ]);

        return $claim->fresh();
    }

    /**
     * Resubmit klaim yang ditolak (setelah revisi dokumen).
     */
    public function resubmitKlaim(string $claimId, array $data, string $userId): InsuranceClaim
    {
        $claim = InsuranceClaim::findOrFail($claimId);

        $claim->update([
            'status'              => 'SUBMITTED',
            'submission_ref'      => $data['submission_ref'],
            'submitted_at'        => now(),
            'resubmission_count'  => $claim->resubmission_count + 1,
            'rejection_code'      => null,
            'rejection_reason'    => null,
            'rejected_at'         => null,
            'documents_checklist' => $data['documents_checklist'] ?? $claim->documents_checklist,
            'notes'               => $data['notes'] ?? $claim->notes,
        ]);

        $this->addLog($claim->id, $userId, 'RESUBMITTED', 'REJECTED', 'SUBMITTED', [
            'submission_ref'     => $data['submission_ref'],
            'resubmission_count' => $claim->resubmission_count,
        ]);

        return $claim->fresh();
    }

    // ─── LAPORAN & AGING ─────────────────────────────────────────

    /**
     * Klaim outstanding dengan aging.
     * Dipakai oleh DashboardController dan AsuransiController.
     */
    public function getAgingReport(): array
    {
        $claims = InsuranceClaim::with(['insurer', 'visit.patient'])
            ->whereIn('status', ['DRAFT', 'SUBMITTED'])
            ->get();

        return $claims->map(function ($claim) {
            $age = now()->diffInDays($claim->submitted_at ?? $claim->created_at);
            $sla = $claim->insurer->sla_days ?? 14;

            return [
                'id'           => $claim->id,
                'visit_id'     => $claim->visit_id,
                'patient_name' => $claim->visit->patient->name ?? '-',
                'insurer_name' => $claim->insurer->name ?? '-',
                'claim_amount' => $claim->claim_amount,
                'status'       => $claim->status,
                'age_days'     => $age,
                'is_overdue'   => $age > $sla,
                'submitted_at' => $claim->submitted_at,
            ];
        })->toArray();
    }

    // ─── HELPERS ─────────────────────────────────────────────────

    private function addLog(
        string $claimId, string $userId, string $action,
        ?string $from, ?string $to, array $metadata = []
    ): void {
        InsuranceClaimLog::create([
            'insurance_claim_id' => $claimId,
            'performed_by'       => $userId,
            'action'             => $action,
            'from_status'        => $from,
            'to_status'          => $to,
            'metadata'           => $metadata,
            'performed_at'       => now(),
        ]);
    }

    private function validateDocumentChecklist(InsuranceClaim $claim): void
    {
        $requirements = \App\Models\InsurerDocumentRequirement::where('insurer_id', $claim->insurer_id)
            ->where('is_required', true)
            ->pluck('document_name');

        $checklist = $claim->documents_checklist ?? [];

        foreach ($requirements as $docName) {
            if (empty($checklist[$docName])) {
                throw new \Exception("Dokumen wajib belum dilengkapi: {$docName}");
            }
        }
    }

    private function notifySupervisor(string $visitId, string $notes): void
    {
        // Kirim ke semua user dengan role Supervisor / Kasir
        // Gunakan pola Notification yang sudah ada di sistem
        // Sesuaikan dengan model Notification yang ada
    }
}
```

---

## 7. Controller & Endpoints Baru

### `AsuransiController.php`

```php
namespace App\Http\Controllers;

use App\Services\AsuransiService;
use Illuminate\Http\Request;

class AsuransiController extends Controller
{
    public function __construct(private AsuransiService $service) {}
    
    // Semua method: validate → delegate ke service → return response
}
```

### Endpoints — Route prefix `/api/v1/asuransi`

Tambahkan di `routes/api.php` di dalam group middleware `auth:api`:

```php
// Verifikasi Eligibility
Route::get('/asuransi/verifikasi/{visitId}',          'AsuransiController@showVerifikasi');
Route::post('/asuransi/verifikasi',                   'AsuransiController@storeVerifikasi');
Route::put('/asuransi/verifikasi/{id}',               'AsuransiController@updateVerifikasi');

// Klaim
Route::get('/asuransi/klaim',                         'AsuransiController@indexKlaim');      // list + filter
Route::get('/asuransi/klaim/{id}',                    'AsuransiController@showKlaim');
Route::post('/asuransi/klaim',                        'AsuransiController@storeKlaim');      // buat draft
Route::put('/asuransi/klaim/{id}',                    'AsuransiController@updateKlaim');     // update draft / checklist
Route::post('/asuransi/klaim/{id}/submit',            'AsuransiController@submitKlaim');
Route::put('/asuransi/klaim/{id}/status',             'AsuransiController@updateStatusKlaim'); // APPROVED / REJECTED
Route::post('/asuransi/klaim/{id}/resubmit',          'AsuransiController@resubmitKlaim');
Route::get('/asuransi/klaim/{id}/logs',               'AsuransiController@logsKlaim');

// Laporan
Route::get('/asuransi/aging',                         'AsuransiController@agingReport');
Route::get('/asuransi/outstanding',                   'AsuransiController@outstandingReport');

// Master — Document Requirements per TPA
Route::get('/asuransi/insurer/{insurerId}/dokumen-requirement',   'AsuransiController@indexDocRequirement');
Route::post('/asuransi/insurer/{insurerId}/dokumen-requirement',  'AsuransiController@storeDocRequirement');
Route::put('/asuransi/dokumen-requirement/{id}',                  'AsuransiController@updateDocRequirement');
Route::delete('/asuransi/dokumen-requirement/{id}',               'AsuransiController@deleteDocRequirement');
```

---

## 8. Perubahan di Controller Existing

### `AdmisiController` / `AdmisiService`

> **Prinsip:** Kiosk tidak berubah sama sekali. Kiosk hanya menerbitkan nomor antrian
> ke stasiun ADMISI seperti biasa. Semua input data pasien — termasuk pilihan penjamin,
> nomor polis, dan data kartu asuransi — dilakukan oleh **petugas admisi** saat memanggil
> pasien di loket.

**Tambahkan field baru di form admisi** (`PUT /admisi/kunjungan/{id}` atau endpoint finalize):

```php
// Field tambahan yang petugas admisi isi jika guarantor_type = ASURANSI / PERUSAHAAN:
// - insurer_id          (sudah ada di visit_cob, tinggal pastikan sync ke visits)
// - policy_number       → disimpan di insurance_verifications sebagai data awal
// - member_name         → nama peserta di kartu asuransi
// - member_card_number  → nomor kartu fisik

// Setelah petugas admisi tekan "Selesai":
// Jika guarantor_type = ASURANSI / PERUSAHAAN
//   → set visits.insurance_verification_status = PENDING
//   → broadcast AdmisiQueueUpdated dengan flag insurance_pending = true
//   → buat insurance_verifications record awal (status = PENDING, isi policy_number + member_name)
// Jika UMUM / BPJS
//   → insurance_verification_status = NONE (tidak ada perubahan dari alur existing)
```

**Tambahkan** endpoint monitoring untuk billing/supervisor:

```php
// GET /admisi/antrian/insurance-pending
// → return semua kunjungan hari ini dengan insurance_verification_status = PENDING
// → include: nama pasien, insurer, waktu masuk antrian (untuk hitung durasi menunggu)
// → dipakai billing untuk tahu siapa yang harus segera diverifikasi ke portal TPA
```

**Catatan penting:** Petugas admisi tidak perlu buka portal TPA. Tugasnya hanya input
data kartu/polis dari fisik kartu yang pasien bawa. Verifikasi eligibility (cek ke portal)
dilakukan terpisah oleh billing sebagai proses paralel.

### `KasirController` / `KasirService`

**Tambahkan** validasi sebelum proses invoice:

```php
// Sebelum generate invoice:
// Cek visits.insurance_verification_status
// Jika PENDING → return warning (bukan blocker keras, tapi flagged)
// Jika ISSUE → return warning + minta konfirmasi supervisor
// Jika VERIFIED → lanjut normal, gunakan copayment_percent dari insurance_verifications
```

**Tambahkan** otomatisasi draft klaim:

```php
// Setelah invoice.status = PAID / FINALIZED
// Jika visit.guarantor_type = ASURANSI/PERUSAHAAN && insurance_verification_status = VERIFIED
// → AsuransiService::createDraftKlaim() otomatis
// → billing tidak perlu buat klaim manual dari nol
```

### `MasterDataController`

**Extend** CRUD `insurers` untuk kolom baru:

```php
// Tambahkan: portal_url, pic_name, pic_phone, pic_email, claim_submission_notes, sla_days
// ke $request->validated() di store/update insurer
```

### `DashboardController`

**Tambahkan** widget klaim asuransi:

```php
// GET /dashboard — tambahkan key 'insurance_summary':
// {
//   "pending_verification": 3,    // kunjungan hari ini belum diverifikasi
//   "draft_claims": 5,            // klaim belum disubmit
//   "overdue_claims": 2,          // klaim melebihi SLA TPA
//   "submitted_this_month": 12,
//   "approved_this_month": 9,
//   "rejected_this_month": 1
// }
```

---

## 9. Frontend — Komponen & Store Baru

### Store Baru: `asuransiStore.js`

```javascript
// src/stores/asuransiStore.js
import { defineStore } from 'pinia'
import { asuransiApi } from '@/services/api'

export const useAsuransiStore = defineStore('asuransi', {
  state: () => ({
    verifikasi: null,
    klaims: [],
    agingReport: [],
    isLoading: false,
    error: null,
  }),

  actions: {
    async fetchVerifikasi(visitId) { /* ... */ },
    async createVerifikasi(data) { /* ... */ },
    async updateVerifikasi(id, data) { /* ... */ },
    async fetchKlaims(filters = {}) { /* ... */ },
    async createDraftKlaim(data) { /* ... */ },
    async submitKlaim(id, data) { /* ... */ },
    async updateStatusKlaim(id, data) { /* ... */ },
    async resubmitKlaim(id, data) { /* ... */ },
    async fetchAgingReport() { /* ... */ },
  },
})
```

### API Helper — Tambahkan di `services/api.js`

```javascript
export const asuransiApi = {
  // Verifikasi
  getVerifikasi: (visitId) => api.get(`/asuransi/verifikasi/${visitId}`),
  createVerifikasi: (data)  => api.post('/asuransi/verifikasi', data),
  updateVerifikasi: (id, data) => api.put(`/asuransi/verifikasi/${id}`, data),

  // Klaim
  getKlaims: (params)    => api.get('/asuransi/klaim', { params }),
  getKlaim: (id)         => api.get(`/asuransi/klaim/${id}`),
  createKlaim: (data)    => api.post('/asuransi/klaim', data),
  updateKlaim: (id, data) => api.put(`/asuransi/klaim/${id}`, data),
  submitKlaim: (id, data) => api.post(`/asuransi/klaim/${id}/submit`, data),
  updateStatus: (id, data) => api.put(`/asuransi/klaim/${id}/status`, data),
  resubmit: (id, data)   => api.post(`/asuransi/klaim/${id}/resubmit`, data),
  getLogs: (id)          => api.get(`/asuransi/klaim/${id}/logs`),

  // Laporan
  getAging: ()           => api.get('/asuransi/aging'),
  getOutstanding: ()     => api.get('/asuransi/outstanding'),
}
```

### View Baru: `AsuransiView.vue`

Tambahkan di `src/views/` dengan 3 tab utama:

```
Tab 1: Verifikasi Pending
  - Tabel: kunjungan hari ini dengan status PENDING
  - Action: klik → isi form verifikasi eligibility
  - Badge: durasi menunggu (merah jika > 10 menit)

Tab 2: Klaim Management
  - Filter: status, tanggal, insurer
  - Tabel: list semua klaim dengan status badge
  - Action per baris: Submit | Update Status | Resubmit | Lihat Log
  - Checklist dokumen inline per klaim

Tab 3: Aging Report
  - Tabel: klaim outstanding dengan kolom "Usia Klaim"
  - Highlight merah: melebihi SLA TPA
  - Export CSV
```

### Perubahan `AdmisiView.vue`

**A. Form input data asuransi** — muncul saat petugas pilih guarantor_type = ASURANSI / PERUSAHAAN:

```vue
<!-- Section ini muncul conditional saat guarantor_type = ASURANSI / PERUSAHAAN -->
<div v-if="isAsuransi" class="insurance-input-section">
  <h4>Data Asuransi / Penjamin</h4>

  <!-- Dropdown dari master insurers -->
  <select v-model="form.insurer_id" required>
    <option v-for="ins in insurerList" :key="ins.id" :value="ins.id">
      {{ ins.name }}
    </option>
  </select>

  <input v-model="form.policy_number"    placeholder="Nomor Polis / Nomor Peserta" />
  <input v-model="form.member_name"      placeholder="Nama Peserta di Kartu" />
  <input v-model="form.member_card_number" placeholder="Nomor Kartu (opsional)" />

  <!-- Info TPA yang relevan (readonly, dari master) -->
  <div v-if="selectedInsurer" class="insurer-info">
    <small>PIC: {{ selectedInsurer.pic_name }} · {{ selectedInsurer.pic_phone }}</small>
    <small v-if="selectedInsurer.claim_submission_notes">
      Catatan: {{ selectedInsurer.claim_submission_notes }}
    </small>
  </div>
</div>
```

**B. Badge status verifikasi** — tampil di list antrian admisi (setelah pasien selesai didaftarkan):

```vue
<!-- Tampil hanya jika guarantor_type = ASURANSI/PERUSAHAAN -->
<span v-if="visit.insurance_verification_status === 'PENDING'"
      class="badge badge-warning">
  ⚠ Verifikasi Asuransi Pending
</span>
<span v-if="visit.insurance_verification_status === 'ISSUE'"
      class="badge badge-danger">
  ✗ Ada Masalah Asuransi
</span>
<span v-if="visit.insurance_verification_status === 'VERIFIED'"
      class="badge badge-success">
  ✓ Asuransi Verified
</span>
```

> **Catatan UX:** Petugas admisi hanya input data dari kartu fisik. Tidak perlu
> buka portal TPA. Tombol "Selesai" tetap bisa ditekan meskipun status masih PENDING —
> verifikasi adalah proses paralel yang dikerjakan billing.

### Perubahan `KasirView.vue`

Tambahkan warning panel sebelum proses pembayaran:

```vue
<!-- Tampil jika guarantor_type = ASURANSI && status != VERIFIED -->
<div v-if="showInsuranceWarning" class="alert alert-warning">
  <strong>Perhatian:</strong> Verifikasi asuransi belum selesai ({{ verificationStatus }}).
  Pastikan sudah dikonfirmasi sebelum memproses pembayaran.
</div>
```

---

## 10. Skenario Edge Cases

### E1: Pasien Ganti Penjamin di Tengah Jalan

Jika setelah verifikasi ternyata pasien ingin bayar mandiri:

```
1. Billing/Supervisor update visits.guarantor_type = UMUM
2. visits.insurance_verification_status = NONE
3. Klaim yang sudah di-DRAFT → soft delete
4. KasirService hitung ulang invoice dengan tarif UMUM
5. Catat alasan di insurance_claim_logs
```

### E2: Plafon Habis di Tengah Pengobatan

```
1. Verifikasi menemukan sisa plafon < estimasi biaya
2. Status = NEEDS_CLARIFICATION
3. issue_notes = "Sisa plafon Rp X, estimasi biaya Rp Y — selisih ditanggung pasien"
4. Supervisor komunikasi ke pasien sebelum masuk dokter
5. Pasien setuju → VERIFIED dengan copayment_amount = selisih
6. Kasir hitung: pasien bayar copayment_amount
```

### E3: Klaim Ditolak Berulang (> 2x Resubmit)

```
1. resubmission_count > 2
2. Sistem beri warning: "Klaim ini sudah disubmit 3x, pertimbangkan appeal atau tagih ke pasien"
3. Option: APPEALED status → billing buat surat keberatan ke TPA
4. Jika tetap ditolak → tagih ke pasien, update patient_responsibility
```

### E4: Pasien COB (2 Penjamin)

```
- visit_cob sudah menangani ini
- Prioritas: penjamin 1 diverifikasi dulu
- Jika tidak fully cover → penjamin 2 diverifikasi untuk sisanya
- Buat 2 insurance_claims terpisah (satu per penjamin)
- KasirService sudah support COB, tinggal link ke klaim yang benar
```

---

## 11. Urutan Implementasi (Development Order)

Ikuti urutan ini untuk menghindari broken dependencies:

```
SPRINT 1 — Fondasi Database (tidak ada perubahan alur)
  □ Migration 1: add columns to insurers
  □ Migration 2: add columns to visits
  □ Migration 3: create insurer_document_requirements
  □ Migration 4: create insurance_verifications
  □ Migration 5: create insurance_claims
  □ Migration 6: create insurance_claim_logs
  □ Buat 4 Models baru (InsuranceVerification, InsuranceClaim, dll)
  □ Extend model Insurer dengan fillable + relasi baru
  □ Extend model Visit dengan fillable + relasi baru

SPRINT 2 — Backend Service & Controller
  □ Buat AsuransiService (lengkap semua method)
  □ Buat AsuransiController
  □ Tambahkan routes di api.php
  □ Update AdmisiService: set insurance_verification_status saat createVisit
  □ Update KasirService: warning jika status PENDING/ISSUE, auto createDraftKlaim
  □ Update MasterDataController: CRUD kolom insurer baru

SPRINT 3 — Frontend Core
  □ Tambah asuransiApi di services/api.js
  □ Buat asuransiStore.js
  □ Tambah route di router/index.js
  □ Buat AsuransiView.vue (3 tab)
  □ Tambah badge di AdmisiView.vue
  □ Tambah warning panel di KasirView.vue

SPRINT 4 — Dashboard & Laporan
  □ Update DashboardController: tambah insurance_summary
  □ Update DashboardView.vue: widget klaim asuransi
  □ Implementasi aging report dengan export CSV

SPRINT 5 — Testing & Hardening
  □ Test alur happy path pasien asuransi end-to-end
  □ Test edge case COB
  □ Test klaim rejected → resubmit
  □ Validasi semua status transition
  □ Pastikan existing BPJS flow tidak terpengaruh
```

---

> **Catatan Penting:**
> - Modul ini **tidak menyentuh** `bpjs_claims`, `KlaimController`, atau alur BPJS sama sekali
> - Semua integrasi ke portal TPA dilakukan **manual oleh billing di luar sistem** — sistem hanya mencatat hasilnya
> - Jika di masa depan ada TPA yang buka API, cukup tambahkan method di `AsuransiService` tanpa mengubah struktur data
> - Pastikan jalankan `php artisan migrate` di environment dev sebelum mulai Sprint 2
