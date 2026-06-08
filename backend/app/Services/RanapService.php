<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\BpjsPoliMapping;
use App\Models\BpjsSpri;
use App\Models\ClinicProfile;
use App\Models\Employee;
use App\Models\InpatientCharge;
use App\Models\Medication;
use App\Models\NurseCpptEntry;
use App\Models\Procedure;
use App\Models\Queue;
use App\Models\Room;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orkestrasi Rawat Inap (RANAP). Thin-wrapper di atas QueueService —
 * routing antrean SELALU lewat QueueService (sumber tunggal, lihat memory
 * queue-advance-station-pattern). Service ini hanya mengelola domain inap:
 * admit/transfer bed, charge harian, dan discharge.
 *
 * Model station RANAP: 1 baris queues station=RANAP status=IN_PROGRESS yang
 * bertahan berhari-hari ("kartu pasien di papan room"). Visite/tindakan/obat =
 * sub-aktivitas (menulis inpatient_charges), BUKAN advanceFromStation.
 */
class RanapService
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly KasirService $kasir,
    ) {}

    // =========================================================================
    // QUERY (papan room, menunggu kamar, detail pasien, running bill)
    // =========================================================================

    /** Papan bed dikelompokkan per Room (status occupancy real-time). */
    public function bedBoard(): array
    {
        $rooms = Room::with(['beds' => fn ($q) => $q->where('is_active', true)->orderBy('code')])
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        // Map bed → pasien aktif (visit + patient) untuk bed OCCUPIED.
        $occupied = BedAssignment::with(['visit.patient'])
            ->whereNull('released_at')
            ->get()
            ->keyBy('bed_id');

        return $rooms->map(function (Room $room) use ($occupied) {
            $beds = $room->beds->map(function (Bed $bed) use ($occupied) {
                $asg = $occupied->get($bed->id);
                return [
                    'id'      => $bed->id,
                    'code'    => $bed->code,
                    'label'   => $bed->label,
                    'status'  => $bed->status,
                    'patient' => $asg?->visit?->patient ? [
                        'visit_id'        => $asg->visit_id,
                        'name'            => $asg->visit->patient->name,
                        'no_rm'           => $asg->visit->patient->no_rm,
                        'kelas_rawat_hak' => $asg->kelas_rawat_hak,
                        'admission_at'    => $asg->visit->admission_at,
                    ] : null,
                ];
            });

            return [
                'id'          => $room->id,
                'code'        => $room->code,
                'name'        => $room->name,
                'kelas_rawat' => $room->kelas_rawat,
                'type'        => $room->type,
                'beds'        => $beds->values(),
                'occupied'    => $beds->where('status', Bed::STATUS_OCCUPIED)->count(),
                'total'       => $beds->count(),
            ];
        })->values()->all();
    }

    /** Pasien yang dokter-nya set RAWAT_INAP, menunggu admit bed. */
    public function waitingForBed(): array
    {
        return Visit::with('patient')
            ->where('current_station', 'MENUNGGU_RANAP')
            ->orderBy('updated_at')
            ->get()
            ->map(fn (Visit $v) => [
                'visit_id'      => $v->id,
                'name'          => $v->patient?->name,
                'no_rm'         => $v->patient?->no_rm,
                'guarantor_type' => $v->guarantor_type,
                'since'         => $v->updated_at,
                // Fase 8B — penanda alasan inap untuk badge papan (PRE_OP vs OBSERVASI).
                'inpatient_reason' => $v->inpatient_reason,
            ])->all();
    }

    /** Pasien rawat inap aktif (current_station=RANAP). */
    public function activeInpatients(): array
    {
        return Visit::with(['patient', 'room', 'bed', 'activeBedAssignment'])
            ->where('jenis_pelayanan', 'RANAP')
            ->where('current_station', Queue::STATION_RANAP)
            ->whereNull('discharge_at')
            ->get()
            ->map(fn (Visit $v) => [
                'visit_id'        => $v->id,
                'name'            => $v->patient?->name,
                'no_rm'           => $v->patient?->no_rm,
                'room'            => $v->room?->name,
                'bed'             => $v->bed?->label,
                'kelas_rawat_hak' => $v->kelas_rawat_hak,
                'admission_at'    => $v->admission_at,
                'guarantor_type'  => $v->guarantor_type,
                'no_sep'          => $v->no_sep,
                'inpatient_reason' => $v->inpatient_reason,
                // Fase 8C — penanda pasien punya jadwal operasi (pre-op) untuk badge.
                'has_surgery_schedule' => ! empty($v->surgery_schedule_id),
            ])->all();
    }

    /** Detail pasien inap + running bill. */
    public function detail(string $visitId): array
    {
        $visit = Visit::with([
            'patient', 'room', 'bed', 'dpjp',
            'bedAssignments.room', 'inpatientCharges',
            // Fase 8C — jadwal bedah pre-op (dari planning dokter) untuk ditampilkan
            // read-only di modal "Kirim ke Bedah" RANAP (tak perlu input paket ulang).
            'surgerySchedule.surgeryPackage:id,name,code',
        ])->findOrFail($visitId);

        $charges = $visit->inpatientCharges;

        return [
            'visit'   => $visit,
            'charges' => $charges,
            'running_bill' => [
                'total'  => (float) $charges->sum('total_price'),
                'billed' => (float) $charges->where('is_billed', true)->sum('total_price'),
            ],
        ];
    }

    /**
     * Admit pasien ke bed → buka periode rawat inap.
     *
     * @param  string       $bedId      Bed kosong yang dipilih petugas.
     * @param  string       $kelasHak   Kelas HAK pasien (basis tarif kamar).
     * @param  string|null  $dpjpId     Employee DPJP rawat inap.
     * @param  string|null  $admissionAt ISO datetime; default now().
     */
    public function admit(
        Visit $visit,
        string $bedId,
        string $kelasHak,
        ?string $dpjpId = null,
        ?string $admissionAt = null
    ): Visit {
        return DB::transaction(function () use ($visit, $bedId, $kelasHak, $dpjpId, $admissionAt) {
            $bed = Bed::with('room')->lockForUpdate()->findOrFail($bedId);

            if ($bed->status !== Bed::STATUS_AVAILABLE) {
                throw new \Exception("Bed {$bed->label} tidak tersedia (status: {$bed->status}).", 422);
            }

            $room    = $bed->room;
            $admitAt = $admissionAt ? \Illuminate\Support\Carbon::parse($admissionAt) : now();

            // Guard kebijakan gender room ('L'/'P') vs gender pasien.
            $this->assertGenderPolicy($room, $visit);

            // Boleh "titip kelas": bed di room kelas != hak. Sistem hanya mencatat,
            // tidak menolak. Tarif tetap mengikuti kelas hak.
            BedAssignment::create([
                'visit_id'         => $visit->id,
                'bed_id'           => $bed->id,
                'room_id'          => $room->id,
                'kelas_rawat_hak'  => $kelasHak,
                'kelas_rawat_room' => $room->kelas_rawat,
                'assigned_at'      => $admitAt,
                'assigned_by_id'   => auth('api')->user()?->employee_id,
                'reason'           => BedAssignment::REASON_ADMISSION,
            ]);

            $bed->update(['status' => Bed::STATUS_OCCUPIED]);

            $visit->update([
                'jenis_pelayanan' => 'RANAP',
                'kelas_rawat_hak' => $kelasHak,
                'kelas_rawat'     => $room->kelas_rawat,
                'ranap_room_id'   => $room->id,
                'ranap_bed_id'    => $bed->id,
                'dpjp_employee_id' => $dpjpId,
                'admission_at'    => $admitAt,
                'current_station' => Queue::STATION_RANAP,
            ]);

            // Enqueue baris RANAP long-lived (langsung IN_PROGRESS = kartu di papan).
            $q = $this->queue->enqueue($visit->id, Queue::STATION_RANAP);
            $q->update(['status' => Queue::STATUS_IN_PROGRESS, 'started_at' => now()]);

            return $visit->fresh(['room', 'bed', 'activeBedAssignment']);
        });
    }

    /**
     * Pindah kamar / kelas. Tutup assignment aktif, buka assignment baru.
     *
     * reason:
     *   TRANSFER       → pindah bed/room sekelas; kelas hak tetap (tarif tetap).
     *   TITIP_KELAS    → room hak penuh; kelas hak TETAP (tarif tetap).
     *   UPGRADE_KELAS  → atas permintaan; kelas hak BERUBAH ke kelas room baru.
     *   DOWNGRADE_KELAS→ idem (turun).
     */
    public function transferBed(Visit $visit, string $newBedId, string $reason): Visit
    {
        return DB::transaction(function () use ($visit, $newBedId, $reason) {
            $active = $visit->activeBedAssignment()->lockForUpdate()->first();
            if (! $active) {
                throw new \Exception('Pasien tidak punya penempatan bed aktif.', 422);
            }

            $newBed = Bed::with('room')->lockForUpdate()->findOrFail($newBedId);
            if ($newBed->status !== Bed::STATUS_AVAILABLE) {
                throw new \Exception("Bed {$newBed->label} tidak tersedia (status: {$newBed->status}).", 422);
            }

            $now     = now();
            $newRoom = $newBed->room;

            // Guard kebijakan gender room tujuan vs gender pasien.
            $this->assertGenderPolicy($newRoom, $visit);

            // Kelas hak berubah hanya untuk UPGRADE/DOWNGRADE (atas permintaan pasien).
            $changesKelas = in_array($reason, [
                BedAssignment::REASON_UPGRADE,
                BedAssignment::REASON_DOWNGRADE,
            ], true);
            $newKelasHak = $changesKelas ? $newRoom->kelas_rawat : $active->kelas_rawat_hak;

            // Tutup periode lama.
            $active->update(['released_at' => $now]);

            // Lepas bed lama → CLEANING.
            $oldBed = Bed::lockForUpdate()->find($active->bed_id);
            $oldBed?->update(['status' => Bed::STATUS_CLEANING]);

            // Buka periode baru.
            BedAssignment::create([
                'visit_id'         => $visit->id,
                'bed_id'           => $newBed->id,
                'room_id'          => $newRoom->id,
                'kelas_rawat_hak'  => $newKelasHak,
                'kelas_rawat_room' => $newRoom->kelas_rawat,
                'assigned_at'      => $now,
                'assigned_by_id'   => auth('api')->user()?->employee_id,
                'reason'           => $reason,
            ]);

            $newBed->update(['status' => Bed::STATUS_OCCUPIED]);

            $visit->update([
                'ranap_room_id'   => $newRoom->id,
                'ranap_bed_id'    => $newBed->id,
                'kelas_rawat_hak' => $newKelasHak,
                'kelas_rawat'     => $newRoom->kelas_rawat,
            ]);

            return $visit->fresh(['room', 'bed', 'activeBedAssignment']);
        });
    }

    /**
     * Tandai bed selesai dibersihkan (CLEANING → AVAILABLE). Dipakai dari papan
     * RANAP oleh perawat ward — tidak boleh memaksa bed OCCUPIED jadi available.
     */
    public function markBedAvailable(string $bedId): array
    {
        return DB::transaction(function () use ($bedId) {
            $bed = Bed::lockForUpdate()->findOrFail($bedId);
            if ($bed->status === Bed::STATUS_OCCUPIED) {
                throw new \Exception("Bed {$bed->label} sedang ditempati — tidak bisa ditandai siap.", 422);
            }
            $bed->update(['status' => Bed::STATUS_AVAILABLE]);
            return ['id' => $bed->id, 'label' => $bed->label, 'status' => $bed->status];
        });
    }

    /**
     * Guard kebijakan gender room. Room dengan gender_policy 'L'/'P' hanya boleh
     * menampung pasien dengan gender sesuai. Room 'MIX'/null (bebas) atau pasien
     * tanpa data gender → selalu lolos (tidak memblokir admit darurat).
     *
     * Patient.gender: 'L' (laki-laki) / 'P' (perempuan).
     * Room.gender_policy: 'L' | 'P' | 'MIX' (null = bebas).
     */
    private function assertGenderPolicy(Room $room, Visit $visit): void
    {
        $policy = $room->gender_policy;
        // Bebas: null atau MIX → tidak ada batasan.
        if (empty($policy) || $policy === 'MIX') {
            return;
        }

        $gender = $visit->patient?->gender; // 'L' | 'P' | null
        if (! $gender) {
            return; // data gender tak ada → jangan blokir
        }

        if ($gender !== $policy) {
            $label = $policy === 'L' ? 'laki-laki' : 'perempuan';
            throw new \Exception(
                "Kamar {$room->name} khusus pasien {$label}. Pilih kamar lain atau ubah kebijakan gender kamar.",
                422
            );
        }
    }

    /**
     * Catat biaya berjalan (visite/tindakan/obat/penunjang/lainnya).
     * Bukan transisi antrean — hanya menulis inpatient_charges.
     */
    public function addCharge(Visit $visit, array $data): InpatientCharge
    {
        $qty   = (float) ($data['quantity'] ?? 1);
        $price = (float) ($data['unit_price'] ?? 0);

        return InpatientCharge::create([
            'visit_id'       => $visit->id,
            'charge_date'    => $data['charge_date'] ?? today(),
            'charge_type'    => $data['charge_type'] ?? InpatientCharge::TYPE_LAINNYA,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id'   => $data['reference_id'] ?? null,
            'description'    => $data['description'],
            'quantity'       => $qty,
            'unit_price'     => $price,
            'total_price'    => $qty * $price,
            'is_billed'      => false,
            'created_by_id'  => auth('api')->user()?->employee_id,
        ]);
    }

    /** Visite dokter = charge bertipe VISITE (shortcut addCharge). */
    public function addVisite(Visit $visit, array $data): InpatientCharge
    {
        return $this->addCharge($visit, array_merge($data, [
            'charge_type' => InpatientCharge::TYPE_VISITE,
        ]));
    }

    // =========================================================================
    // PICKER TINDAKAN / OBAT (harga resolve per visit via getPrice)
    // =========================================================================

    /** Daftar tindakan (procedures) + harga ter-resolve untuk penjamin pasien. */
    public function tarifTindakan(Visit $visit): array
    {
        return Procedure::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category'])
            ->map(fn ($p) => [
                'id'       => $p->id,
                'code'     => $p->code,
                'name'     => $p->name,
                'category' => $p->category,
                'price'    => $this->kasir->getPrice('procedure', $p->id, $visit->guarantor_type, $visit->insurer_id),
            ])
            ->all();
    }

    /** Daftar obat + harga ter-resolve untuk penjamin pasien. */
    public function daftarObat(Visit $visit, ?string $search = null): array
    {
        // Tampilkan SEMUA obat (selaras sumber Farmasi yg tak filter is_active); nonaktif ditandai.
        return Medication::query()
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%");
            }))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'code', 'name', 'unit', 'is_active'])
            ->map(fn ($m) => [
                'id'        => $m->id,
                'code'      => $m->code,
                'name'      => $m->name,
                'unit'      => $m->unit ?? null,
                'price'     => $this->kasir->getPrice('medication', $m->id, $visit->guarantor_type, $visit->insurer_id),
                'is_active' => (bool) $m->is_active,
            ])
            ->all();
    }

    /**
     * Catat TINDAKAN pasien inap. Harga di-resolve OTOMATIS via getPrice
     * (bukan input manual) → konsisten dengan tarif master per penjamin.
     */
    public function addTindakan(Visit $visit, string $procedureId, float $qty = 1): InpatientCharge
    {
        $proc = Procedure::findOrFail($procedureId);
        $price = $this->kasir->getPrice('procedure', $procedureId, $visit->guarantor_type, $visit->insurer_id);

        return $this->addCharge($visit, [
            'charge_type'    => InpatientCharge::TYPE_TINDAKAN,
            'reference_type' => 'procedure',
            'reference_id'   => $proc->id,
            'description'    => $proc->name,
            'quantity'       => $qty,
            'unit_price'     => $price,
        ]);
    }

    /**
     * Catat OBAT pasien inap. Harga di-resolve OTOMATIS via getPrice.
     */
    public function addObat(Visit $visit, string $medicationId, float $qty = 1): InpatientCharge
    {
        $med = Medication::findOrFail($medicationId);
        $price = $this->kasir->getPrice('medication', $medicationId, $visit->guarantor_type, $visit->insurer_id);

        return $this->addCharge($visit, [
            'charge_type'    => InpatientCharge::TYPE_OBAT,
            'reference_type' => 'medication',
            'reference_id'   => $med->id,
            'description'    => $med->name . ($med->unit ? " ({$med->unit})" : ''),
            'quantity'       => $qty,
            'unit_price'     => $price,
        ]);
    }

    /** Hapus charge yang belum di-billing (koreksi input). */
    public function deleteCharge(Visit $visit, string $chargeId): void
    {
        $charge = InpatientCharge::where('visit_id', $visit->id)->findOrFail($chargeId);
        if ($charge->is_billed) {
            throw new \Exception('Biaya sudah masuk invoice — tidak bisa dihapus.', 422);
        }
        $charge->delete();
    }

    // =========================================================================
    // DOKUMEN/HASIL EKSTERNAL (Fase 8C) — lab/radiologi pihak ke-3 pre-op.
    // Hanya tempel HASIL (RS bayar pihak ke-3 di luar alur); tagihan tindakan
    // terkait tetap lewat alur procedures biasa.
    // =========================================================================

    /** Daftar dokumen/hasil eksternal pasien inap (terbaru dulu). */
    public function documents(Visit $visit): array
    {
        return \App\Models\InpatientDocument::with('uploadedBy:id,name')
            ->where('visit_id', $visit->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id'         => $d->id,
                'category'   => $d->category,
                'title'      => $d->title,
                'file_name'  => $d->file_name,
                'file_url'   => $d->file_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($d->file_path) : null,
                'mime_type'  => $d->mime_type,
                'by'         => $d->uploadedBy?->name,
                'at'         => $d->created_at,
            ])->all();
    }

    /** Upload hasil eksternal (PDF/gambar) untuk pasien inap. */
    public function uploadDocument(Visit $visit, array $data, $file): \App\Models\InpatientDocument
    {
        $category = in_array($data['category'] ?? null, \App\Models\InpatientDocument::CATEGORIES, true)
            ? $data['category']
            : 'LAINNYA';

        $path = $file->store('inpatient-documents', 'public');

        return \App\Models\InpatientDocument::create([
            'visit_id'       => $visit->id,
            'category'       => $category,
            'title'          => $data['title'] ?? $file->getClientOriginalName(),
            'file_path'      => $path,
            'file_name'      => $file->getClientOriginalName(),
            'mime_type'      => $file->getClientMimeType(),
            'uploaded_by_id' => auth('api')->user()?->employee_id,
        ]);
    }

    /** Hapus dokumen eksternal (beserta file fisik). */
    public function deleteDocument(Visit $visit, string $documentId): void
    {
        $doc = \App\Models\InpatientDocument::where('visit_id', $visit->id)->findOrFail($documentId);

        if ($doc->file_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($doc->file_path);
        }
        $doc->delete();
    }

    // =========================================================================
    // CPPT (Catatan Perkembangan Pasien Terintegrasi) harian — append-only.
    // Reuse tabel nurse_cppt_entries (nurse_assessment_id nullable utk inap).
    // =========================================================================

    /** Daftar CPPT pasien inap (terbaru dulu). Terintegrasi multi-PPA. */
    public function cpptEntries(Visit $visit): array
    {
        return NurseCpptEntry::with(['createdBy:id,name,profession', 'verifiedBy:id,name'])
            ->where('visit_id', $visit->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($e) => $this->formatCpptEntry($e))
            ->all();
    }

    private function formatCpptEntry(NurseCpptEntry $e): array
    {
        return [
            'id'          => $e->id,
            'ppa_role'    => $e->ppa_role,
            'td_sistol'   => $e->td_sistol,
            'td_diastol'  => $e->td_diastol,
            'nadi'        => $e->nadi,
            'suhu'        => $e->suhu,
            'respirasi'   => $e->respirasi,
            'spo2'        => $e->spo2,
            'kgd'         => $e->kgd,
            'pain_scale'  => $e->pain_scale,
            'visus_od'    => $e->visus_od,
            'visus_os'    => $e->visus_os,
            'iop_od'      => $e->iop_od,
            'iop_os'      => $e->iop_os,
            'iop_method'  => $e->iop_method,
            'notes'       => $e->notes,
            'soap_s'      => $e->soap_s,
            'soap_o'      => $e->soap_o,
            'soap_a'      => $e->soap_a,
            'soap_p'      => $e->soap_p,
            'instruksi'   => $e->instruksi,
            'by'          => $e->createdBy?->name,
            'by_profession' => $e->createdBy?->profession,
            'at'          => $e->created_at,
            'edited_at'   => $e->edited_at,
            'verified_by' => $e->verifiedBy?->name,
            'verified_at' => $e->verified_at,
        ];
    }

    /**
     * Tambah CPPT terintegrasi (SOAP + TTV opsional). Peran PPA di-derive
     * otomatis dari profesi employee penulis. Tidak mewajibkan nurse_assessment.
     */
    public function addCppt(Visit $visit, array $data): NurseCpptEntry
    {
        $employee = auth('api')->user()?->employee;

        $entry = NurseCpptEntry::create([
            'visit_id'            => $visit->id,
            'nurse_assessment_id' => $visit->nurseAssessment?->id, // null utk inap tanpa triase
            'ppa_role'            => $employee?->ppaRole() ?? Employee::PPA_LAINNYA,
            'td_sistol'           => $data['td_sistol']  ?? null,
            'td_diastol'          => $data['td_diastol'] ?? null,
            'nadi'                => $data['nadi']       ?? null,
            'suhu'                => $data['suhu']       ?? null,
            'respirasi'           => $data['respirasi']  ?? null,
            'spo2'                => $data['spo2']       ?? null,
            'kgd'                 => $data['kgd']        ?? null,
            'pain_scale'          => $data['pain_scale'] ?? null,
            'visus_od'            => $data['visus_od']   ?? null,
            'visus_os'            => $data['visus_os']   ?? null,
            'iop_od'              => $data['iop_od']     ?? null,
            'iop_os'              => $data['iop_os']     ?? null,
            'iop_method'          => $data['iop_method'] ?? null,
            'notes'               => $data['notes']     ?? null,
            'soap_s'              => $data['soap_s']    ?? null,
            'soap_o'              => $data['soap_o']    ?? null,
            'soap_a'              => $data['soap_a']    ?? null,
            'soap_p'              => $data['soap_p']    ?? null,
            'instruksi'           => $data['instruksi'] ?? null,
            'created_by_id'       => $employee?->id,
        ]);

        return $entry->fresh(['createdBy', 'verifiedBy']);
    }

    /** Soft-edit CPPT — catat jejak editor (versi lama tidak disimpan). */
    public function updateCppt(string $entryId, array $data): NurseCpptEntry
    {
        $entry = NurseCpptEntry::findOrFail($entryId);

        $entry->fill(array_merge(
            array_intersect_key($data, array_flip([
                'td_sistol', 'td_diastol', 'nadi', 'suhu', 'respirasi', 'spo2',
                'kgd', 'pain_scale', 'visus_od', 'visus_os', 'iop_od', 'iop_os',
                'iop_method', 'notes', 'soap_s', 'soap_o', 'soap_a',
                'soap_p', 'instruksi',
            ])),
            [
                'edited_at'    => now(),
                'edited_by_id' => auth('api')->user()?->employee_id,
            ]
        ))->save();

        return $entry->fresh(['createdBy', 'verifiedBy']);
    }

    /**
     * Verifikasi/review DPJP atas entri CPPT (jejak review SNARS).
     * Hanya PPA berperan DOKTER yang boleh memverifikasi.
     */
    public function verifyCppt(string $entryId): NurseCpptEntry
    {
        $employee = auth('api')->user()?->employee;

        if (($employee?->ppaRole()) !== Employee::PPA_DOKTER) {
            throw new \Exception('Hanya dokter (DPJP) yang dapat memverifikasi CPPT.', 403);
        }

        $entry = NurseCpptEntry::findOrFail($entryId);
        $entry->forceFill([
            'verified_by_id' => $employee->id,
            'verified_at'    => now(),
        ])->save();

        return $entry->fresh(['createdBy', 'verifiedBy']);
    }

    /**
     * Pasien RANAP butuh operasi → kirim ke BEDAH sebagai SUB-AKTIVITAS.
     * Baris RANAP TIDAK ditutup (bed ditahan); dibuat baris BEDAH terpisah.
     * Saat operasi selesai (finalizeRecord → advanceFromStation BEDAH),
     * resolveNextRanap mengembalikan NO_OP → pasien tetap di baris RANAP.
     *
     * Biaya operasi (tindakan/BHP/IOL) dicatat sebagai inpatient_charges
     * (1 invoice RANAP saat discharge). SEP RANAP diurus admin manual.
     *
     * Jadwal operasi (visits.surgery_schedule_id → papan "Bedah Terjadwal"):
     *   - jika $surgeryScheduleId diberi → pakai jadwal itu;
     *   - else jika $options['surgery_package_id'] diberi → AUTO-BUAT SurgerySchedule
     *     (DPJP rawat inap jadi lead_surgeon, ruang OK default dari Profil Klinik);
     *   - else → tanpa jadwal (pasien tetap masuk antrean Bedah, bed ditahan).
     * surgery_package_id WAJIB ada untuk membuat jadwal (kolom NOT NULL) — pola
     * sama dengan DokterService::ensureSchedule.
     *
     * @param  string|null  $surgeryScheduleId  Jadwal operasi yang sudah ada.
     * @param  array         $options            scheduled_date, scheduled_time,
     *                                           surgery_package_id, operation_room, notes.
     */
    public function sendToBedah(Visit $visit, ?string $surgeryScheduleId = null, array $options = []): Queue
    {
        if (($visit->jenis_pelayanan ?? null) !== 'RANAP') {
            throw new \Exception('Hanya pasien rawat inap yang bisa dikirim ke bedah via RanapService.', 422);
        }

        // Pastikan baris RANAP masih hidup (kalau tidak, pasien sudah discharge).
        $liveRanap = Queue::byStation(Queue::STATION_RANAP)
            ->where('visit_id', $visit->id)
            ->whereNotIn('status', [Queue::STATUS_COMPLETED, Queue::STATUS_CANCELLED])
            ->exists();
        if (! $liveRanap) {
            throw new \Exception('Pasien tidak punya baris rawat inap aktif.', 422);
        }

        return DB::transaction(function () use ($visit, $surgeryScheduleId, $options) {
            // Prioritas sumber jadwal operasi (Fase 8C — otomatis dari jadwal dokter):
            //   1. $surgeryScheduleId eksplisit (jika petugas memilih jadwal tertentu);
            //   2. $visit->surgery_schedule_id yang SUDAH ADA — pasien pre-op rawat inap
            //      jadwalnya dibuat dokter di planning (8A) & dibawa lewat admisi (8B),
            //      jadi RANAP→Bedah TIDAK perlu input paket ulang;
            //   3. else → maybeCreateSurgerySchedule (butuh options.surgery_package_id).
            $scheduleId = $surgeryScheduleId
                ?: $visit->surgery_schedule_id
                ?: $this->maybeCreateSurgerySchedule($visit, $options);

            if ($scheduleId && $scheduleId !== $visit->surgery_schedule_id) {
                $visit->update(['surgery_schedule_id' => $scheduleId]);
            }

            // Enqueue baris BEDAH (baris RANAP tetap hidup = bed ditahan).
            return $this->queue->enqueue($visit->id, Queue::STATION_BEDAH);
        });
    }

    /**
     * Auto-buat SurgerySchedule untuk pasien RANAP yang dikirim ke bedah, supaya
     * tampil di papan "Bedah Terjadwal" (yang melisting via visits.surgery_schedule_id).
     * Mengembalikan null bila tak ada paket (surgery_package_id NOT NULL — tak bisa
     * dibuat tanpa paket; pasien tetap masuk antrean Bedah lewat enqueue).
     */
    private function maybeCreateSurgerySchedule(Visit $visit, array $options): ?string
    {
        $packageId = $options['surgery_package_id'] ?? null;
        if (! $packageId) {
            return null; // tanpa paket → tak buat jadwal (kolom NOT NULL)
        }

        // Ruang OK default dari Profil Klinik (ambil yang pertama bila ada).
        $defaultRoom = ClinicProfile::query()->value('operating_rooms');
        $defaultRoom = is_array($defaultRoom) ? ($defaultRoom[0] ?? null) : null;

        $schedule = SurgerySchedule::create([
            'surgery_package_id' => $packageId,
            'lead_surgeon_id'    => $visit->dpjp_employee_id,
            'scheduled_date'     => $options['scheduled_date'] ?? today()->toDateString(),
            'scheduled_time'     => $options['scheduled_time'] ?? null,
            'operation_room'     => $options['operation_room'] ?? $defaultRoom,
            'status'             => 'SCHEDULED',
            'notes'              => $options['notes'] ?? 'Dari Rawat Inap',
        ]);

        return $schedule->id;
    }

    /**
     * Pemulangan. Set discharge_at/type → generate room charge per-periode →
     * advance baris RANAP → KASIR (via QueueService, sumber tunggal). Bed → CLEANING.
     *
     * @param  string  $dischargeType PULANG_SEHAT|RUJUK|APS|MENINGGAL
     */
    public function discharge(
        Visit $visit,
        string $dischargeType,
        ?string $summary = null,
        ?string $followUpDate = null,
        ?string $followUpReason = null,
        ?string $spriTglRencana = null,
        array $obatPulang = []
    ): array {
        // 1. Tandai discharge (di luar advance — supaya gate RANAP→KASIR lolos).
        DB::transaction(function () use ($visit, $dischargeType, $summary, $followUpDate, $followUpReason, $obatPulang) {
            $dischargeAt = now();

            $visit->update(array_merge([
                'discharge_at'      => $dischargeAt,
                'discharge_type'    => $dischargeType,
                'discharge_summary' => $summary,
            ], $followUpDate ? [
                // Rencana kontrol pasca-pulang (semua penjamin) — kolom existing
                // yang juga dipakai dashboard "Pasien Kontrol".
                'planning_follow_up' => true,
                'follow_up_date'     => $followUpDate,
                'follow_up_reason'   => $followUpReason,
            ] : []));

            // Lepas bed aktif → CLEANING (tutup periode terakhir dulu, supaya
            // generateRoomCharges menghitung malam s/d discharge dengan benar).
            $active = $visit->activeBedAssignment()->first();
            if ($active) {
                $active->update(['released_at' => $dischargeAt]);
                Bed::where('id', $active->bed_id)->update(['status' => Bed::STATUS_CLEANING]);
            }

            // Generate room charge sekaligus (tanpa cron), per-periode bed_assignments.
            $this->generateRoomCharges($visit->fresh(), $dischargeAt);

            // Obat pulang (opsional): tagih via inpatient_charges OBAT + buat resep
            // SUBMITTED supaya pasien lanjut ke Farmasi (serah obat + potong stok).
            if (! empty($obatPulang)) {
                $this->createObatPulang($visit, $obatPulang);
            }
        });

        // 2. Advance baris RANAP → KASIR lewat QueueService (sumber tunggal routing).
        $ranapQueue = Queue::byStation(Queue::STATION_RANAP)
            ->where('visit_id', $visit->id)
            ->whereNotIn('status', [Queue::STATUS_COMPLETED, Queue::STATUS_CANCELLED])
            ->latest('created_at')
            ->firstOrFail();

        $result = $this->queue->advanceFromStation($ranapQueue->id, Queue::STATION_RANAP);

        // 3. Lapor tgl pulang ke BPJS (non-blocking) — hanya jika BPJS + ada SEP.
        $this->maybeUpdateTglPulangBpjs($visit->fresh());

        // 4. Terbitkan SPRI ke VClaim (non-blocking) bila diminta saat pulang.
        //    Gagal → row SPRI tersimpan FAILED/DRAFT, user re-issue dari History.
        if ($spriTglRencana) {
            try {
                $this->createSpri($visit->fresh(), $spriTglRencana, blocking: false);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SPRI saat discharge gagal: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Obat pulang RANAP. Dipanggil di dalam transaksi discharge.
     *
     * Dua jejak (sengaja terpisah, sesuai keputusan billing RANAP):
     *   1. TAGIHAN  → inpatient_charges type OBAT (harga getPrice per penjamin) →
     *      masuk invoice RI lewat buildInpatientChargeLines. Pasti tertagih tanpa
     *      bergantung status dispense farmasi.
     *   2. SERAH OBAT + STOK → 1 Prescription status SUBMITTED + items, supaya
     *      QueueService::nextAfterKasir mengarahkan pasien KASIR→FARMASI (resep ada),
     *      lalu farmasi men-dispense (potong stok). buildObatLines di-skip utk RANAP
     *      agar resep ini TIDAK ikut menambah tagihan (anti-dobel).
     *
     * @param  array<array{medication_id:string,quantity?:float,dose?:string,
     *                      frequency?:string,route?:string,duration_days?:int,
     *                      instructions?:string,notes?:string}>  $items
     */
    private function createObatPulang(Visit $visit, array $items): void
    {
        $employeeId = auth('api')->user()?->employee_id;

        // 1. Tagihan per item → inpatient_charges OBAT (harga resolve via getPrice).
        foreach ($items as $item) {
            $medId = $item['medication_id'] ?? null;
            if (! $medId) {
                continue;
            }
            $this->addObat($visit, $medId, (float) ($item['quantity'] ?? 1));
        }

        // 2. Resep SUBMITTED utk Farmasi (serah obat + potong stok). Resep RANAP
        //    WAJIB punya peresep (prescriptions.prescribed_by_id NOT NULL). Akun tanpa
        //    employee (mis. Superadmin) tidak bisa membuat resep — lewati pembuatan
        //    resep tapi tagihan tetap tercatat (item sudah masuk inpatient_charges).
        if (! $employeeId) {
            Log::warning('Obat pulang RANAP: resep tidak dibuat (akun tanpa employee), tagihan tetap tercatat.', [
                'visit_id' => $visit->id,
            ]);
            return;
        }

        $prescription = \App\Models\Prescription::create([
            'visit_id'         => $visit->id,
            'prescribed_by_id' => $employeeId,
            'status'           => 'SUBMITTED',
            'notes'            => 'Obat pulang rawat inap',
        ]);

        foreach ($items as $item) {
            $medId = $item['medication_id'] ?? null;
            if (! $medId) {
                continue;
            }
            \App\Models\PrescriptionItem::create([
                'prescription_id' => $prescription->id,
                'medication_id'   => $medId,
                'quantity'        => $item['quantity'] ?? 1,
                'dose'            => $item['dose'] ?? null,
                'frequency'       => $item['frequency'] ?? null,
                'route'           => $item['route'] ?? null,
                'duration_days'   => $item['duration_days'] ?? null,
                'instructions'    => $item['instructions'] ?? null,
                'notes'           => $item['notes'] ?? null,
            ]);
        }
    }

    /**
     * Lapor tgl pulang RANAP ke VClaim BPJS. Non-blocking: kegagalan/timeout/
     * credential-kosong TIDAK membatalkan discharge lokal (pola maybeSubmitLpkBpjs).
     */
    private function maybeUpdateTglPulangBpjs(Visit $visit): void
    {
        try {
            if (($visit->guarantor_type ?? null) !== 'BPJS' || empty($visit->no_sep) || empty($visit->discharge_at)) {
                return;
            }
            if (! app(\App\Services\BpjsVClaimService::class)->isEnabled()) {
                return;
            }

            // Delegasi ke jalur manual (blocking) — di sini ditelan jadi non-blocking.
            $this->updateTglPulang($visit);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BPJS updateTglPulang gagal: ' . $e->getMessage());
        }
    }

    /**
     * Generate room charge per-periode bed_assignments (basis billing inap).
     *
     * Untuk tiap periode: malam = jumlah malam assigned_at..(released_at ?? discharge),
     * minimum 1 (masuk dihitung, pulang tidak; masuk=pulang → 1). Harga via
     * getPrice('room', kelas_HAK, ...) — SELALU kelas hak, bukan kelas room aktual
     * (titip kelas tetap ditagih kelas hak). Bila pasien pindah/upgrade di tengah
     * rawat, tiap periode dihitung dengan kelas hak periode itu.
     *
     * Idempotent-ish: hanya membuat ROOM charge bila belum ada untuk visit
     * (cegah dobel saat discharge dipanggil ulang).
     */
    private function generateRoomCharges(Visit $visit, \DateTimeInterface $dischargeAt): void
    {
        $alreadyHasRoom = InpatientCharge::where('visit_id', $visit->id)
            ->where('charge_type', InpatientCharge::TYPE_ROOM)
            ->exists();
        if ($alreadyHasRoom) {
            return;
        }

        $periods = $visit->bedAssignments()->orderBy('assigned_at')->get();

        foreach ($periods as $p) {
            $start = \Illuminate\Support\Carbon::parse($p->assigned_at);
            $end   = \Illuminate\Support\Carbon::parse($p->released_at ?? $dischargeAt);

            // Malam = beda hari kalender (masuk dihitung, pulang tidak), minimum 1.
            $nights = max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()));

            $price = $this->kasir->getPrice('room', $p->kelas_rawat_hak, $visit->guarantor_type, $visit->insurer_id);

            $label = "Kamar Kelas {$p->kelas_rawat_hak}";
            if ($p->kelas_rawat_room !== $p->kelas_rawat_hak) {
                // Titip kelas: tagih kelas hak, catat room aktual untuk transparansi.
                $label .= " (dititip di Kelas {$p->kelas_rawat_room})";
            }
            $label .= " — {$nights} malam";

            $this->addCharge($visit, [
                'charge_date'    => $start->toDateString(),
                'charge_type'    => InpatientCharge::TYPE_ROOM,
                'reference_type' => 'bed_assignment',
                'reference_id'   => $p->id,
                'description'    => $label,
                'quantity'       => $nights,
                'unit_price'     => $price,
            ]);
        }
    }

    // =========================================================================
    // BPJS — SEP (view/update) · SPRI (CRU) · History pasien pulang
    // Semua aksi eksplisit di sini BLOCKING + guard isEnabled; SPRI saat
    // discharge dipanggil non-blocking (lihat discharge()).
    // =========================================================================

    /** Detail SEP untuk pasien inap BPJS (data lokal + opsional respon VClaim). */
    public function getSep(Visit $visit): array
    {
        $this->assertBpjsRanap($visit);

        $local = [
            'no_sep'          => $visit->no_sep,
            'kelas_rawat_hak' => $visit->kelas_rawat_hak,
            'admission_at'    => $visit->admission_at,
            'discharge_at'    => $visit->discharge_at,
            'patient'         => $visit->patient?->name,
            'no_rm'           => $visit->patient?->no_rm,
            'bpjs_number'     => $visit->patient?->bpjs_number,
        ];

        // Opsional: ambil detail dari VClaim (non-blocking — kalau gagal, tampil lokal).
        $vclaim = app(\App\Services\BpjsVClaimService::class);
        if ($vclaim->isEnabled()) {
            try {
                $local['vclaim'] = $vclaim->getSepInternal($visit->no_sep);
            } catch (\Throwable $e) {
                Log::warning('getSepInternal gagal: ' . $e->getMessage());
                $local['vclaim'] = null;
            }
        }

        return $local;
    }

    /**
     * Update SEP RANAP ke VClaim. DPJP/poli di-resolve khusus RANAP
     * (tanpa doctor_schedule) — lihat resolveRanapDpjpPoli.
     */
    public function updateSep(Visit $visit, array $data): array
    {
        $this->assertBpjsRanap($visit, requireEnabled: true);

        ['kodeDokter' => $kodeDpjp, 'poliKontrol' => $kodePoli] = $this->resolveRanapDpjpPoli($visit);

        $tSep = [
            'noSep'     => $visit->no_sep,
            'klsRawat'  => [
                'klsRawatHak'     => (string) ($data['kls_rawat'] ?? $visit->kelas_rawat_hak ?? '3'),
                'klsRawatNaik'    => '',
                'pembiayaan'      => '',
                'penanggungJawab' => '',
            ],
            'noMR'      => $visit->patient?->no_rm ?? '',
            'catatan'   => $data['catatan'] ?? '',
            'diagAwal'  => $data['diag_awal'] ?? '',
            'poli'      => ['tujuan' => $kodePoli, 'eksekutif' => '0'],
            'cob'       => ['cob' => '0'],
            'katarak'   => ['katarak' => (string) ($data['katarak'] ?? '0')],
            'jaminan'   => ['lakaLantas' => '0', 'penjamin' => ['tglKejadian' => '', 'keterangan' => '', 'suplesi' => ['suplesi' => '0', 'noSepSuplesi' => '', 'lokasiLaka' => ['kdPropinsi' => '', 'kdKabupaten' => '', 'kdKecamatan' => '']]]],
            'dpjpLayan' => $kodeDpjp,
            'noTelp'    => $data['no_telp'] ?? ($visit->patient?->phone ?? ''),
            'user'      => auth('api')->user()?->name ?? 'arumed',
        ];

        $vclaim = app(\App\Services\BpjsVClaimService::class);
        $result = $vclaim->updateSep($tSep, $visit->id);

        if ((string) ($result['metaData']['code'] ?? '') !== '200') {
            throw new \Exception('Gagal update SEP: ' . ($result['metaData']['message'] ?? 'respons tidak dikenal'), 422);
        }

        return $result;
    }

    /**
     * Update Tanggal Pulang SEP ke VClaim secara MANUAL (blocking).
     *
     * Berbeda dari maybeUpdateTglPulangBpjs() yang dipanggil otomatis &
     * non-blocking saat discharge: ini dipicu petugas dari modal SEP untuk
     * MENGULANG bila laporan otomatis gagal (BPJS down/timeout) atau bila
     * tgl pulang dikoreksi. Kegagalan dilempar 422 supaya petugas tahu.
     */
    public function updateTglPulang(Visit $visit, ?string $tglPulang = null): array
    {
        $this->assertBpjsRanap($visit, requireEnabled: true);

        // Default: pakai discharge_at lokal bila tgl tidak dioverride petugas.
        $tgl = $tglPulang ?: ($visit->discharge_at
            ? \Illuminate\Support\Carbon::parse($visit->discharge_at)->setTimezone('Asia/Jakarta')->toDateString()
            : null);
        if (empty($tgl)) {
            throw new \Exception('Pasien belum dipulangkan — tanggal pulang belum ada.', 422);
        }

        $vclaim = app(\App\Services\BpjsVClaimService::class);
        $result = $vclaim->updateTglPulang([
            'noSep'      => $visit->no_sep,
            'tglPulang'  => $tgl,
            'noLPManual' => '',
            'user'       => auth('api')->user()?->name ?? 'arumed',
        ], $visit->id);

        if ((string) ($result['metaData']['code'] ?? '') !== '200') {
            throw new \Exception('Gagal update tgl pulang SEP: ' . ($result['metaData']['message'] ?? 'respons tidak dikenal'), 422);
        }

        return $result;
    }

    /** Daftar SPRI satu kunjungan (terbaru dulu). */
    public function listSpri(Visit $visit): array
    {
        return $visit->spris()->get()->map(fn (BpjsSpri $s) => $this->formatSpri($s))->all();
    }

    /**
     * Terbitkan SPRI ke VClaim. blocking=true (aksi eksplisit) → lempar error.
     * blocking=false (dipanggil di discharge) → simpan FAILED, tidak lempar.
     */
    public function createSpri(Visit $visit, string $tglRencana, bool $blocking = true): BpjsSpri
    {
        $this->assertBpjsRanap($visit, requireEnabled: $blocking);

        // Idempotensi: bila sudah ada SPRI yang sukses/draft, jangan dobel.
        $existing = $visit->spris()
            ->whereIn('status', [BpjsSpri::STATUS_DRAFT, BpjsSpri::STATUS_SUCCESS])
            ->first();
        if ($existing) {
            return $existing;
        }

        ['kodeDokter' => $kodeDpjp, 'poliKontrol' => $kodePoli] = $this->resolveRanapDpjpPoli($visit);

        $spri = BpjsSpri::create([
            'visit_id'     => $visit->id,
            'tgl_rencana'  => $tglRencana,
            'poli_kontrol' => $kodePoli,
            'kode_dokter'  => $kodeDpjp,
            'status'       => BpjsSpri::STATUS_DRAFT,
        ]);

        $vclaim = app(\App\Services\BpjsVClaimService::class);
        if (! $vclaim->isEnabled()) {
            if ($blocking) {
                throw new \Exception('Integrasi BPJS VClaim tidak aktif.', 422);
            }
            return $spri; // tetap DRAFT — user terbitkan ulang dari History
        }

        try {
            $payload = [
                'noKartu'     => $visit->patient?->bpjs_number ?? '',
                'nik'         => $visit->patient?->nik ?? '',
                'noSep'       => $visit->no_sep,
                'kodeDokter'  => $kodeDpjp,
                'poliKontrol' => $kodePoli,
                'tglRencana'  => $tglRencana,
                'user'        => auth('api')->user()?->name ?? 'arumed',
            ];
            $result = $vclaim->insertSpri($payload, $visit->id);

            if ((string) ($result['metaData']['code'] ?? '') === '200') {
                $spri->update([
                    'status'          => BpjsSpri::STATUS_SUCCESS,
                    'no_spri'         => $result['response']['noSPRI'] ?? ($result['response']['noSuratKontrol'] ?? null),
                    'vclaim_response' => $result,
                ]);
            } else {
                $spri->update(['status' => BpjsSpri::STATUS_FAILED, 'vclaim_response' => $result]);
                if ($blocking) {
                    throw new \Exception('Gagal terbitkan SPRI: ' . ($result['metaData']['message'] ?? 'respons tidak dikenal'), 422);
                }
            }
        } catch (\Throwable $e) {
            $spri->update(['status' => BpjsSpri::STATUS_FAILED]);
            if ($blocking) {
                throw $e;
            }
            Log::warning('insertSpri gagal: ' . $e->getMessage());
        }

        return $spri->fresh();
    }

    /** Update tgl rencana SPRI yang sudah terbit. */
    public function updateSpri(string $spriId, string $tglRencana): BpjsSpri
    {
        $spri = BpjsSpri::with('visit.patient')->findOrFail($spriId);

        if ($spri->status !== BpjsSpri::STATUS_SUCCESS || ! $spri->no_spri) {
            throw new \Exception('Hanya SPRI yang sudah terbit yang bisa diubah. Yang masih draft → terbitkan dulu.', 422);
        }

        $vclaim = app(\App\Services\BpjsVClaimService::class);
        if (! $vclaim->isEnabled()) {
            throw new \Exception('Integrasi BPJS VClaim tidak aktif.', 422);
        }

        $result = $vclaim->updateSpri([
            'noSPRI'      => $spri->no_spri,
            'noKartu'     => $spri->visit?->patient?->bpjs_number ?? '',
            'nik'         => $spri->visit?->patient?->nik ?? '',
            'noSep'       => $spri->visit?->no_sep,
            'kodeDokter'  => $spri->kode_dokter,
            'poliKontrol' => $spri->poli_kontrol,
            'tglRencana'  => $tglRencana,
            'user'        => auth('api')->user()?->name ?? 'arumed',
        ], $spri->visit_id);

        if ((string) ($result['metaData']['code'] ?? '') !== '200') {
            throw new \Exception('Gagal update SPRI: ' . ($result['metaData']['message'] ?? 'respons tidak dikenal'), 422);
        }

        $spri->update(['tgl_rencana' => $tglRencana, 'vclaim_response' => $result]);

        return $spri->fresh();
    }

    /** Hapus SPRI — hanya yang belum terbit (DRAFT/FAILED tanpa no_spri). */
    public function deleteSpri(string $spriId): void
    {
        $spri = BpjsSpri::findOrFail($spriId);

        if ($spri->status === BpjsSpri::STATUS_SUCCESS && $spri->no_spri) {
            throw new \Exception('SPRI sudah terbit di BPJS dan tidak bisa dihapus dari sini.', 422);
        }

        $spri->delete();
    }

    /** Riwayat pasien inap yang sudah pulang, filter rentang tgl pulang. */
    public function dischargedHistory(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $from = $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : now()->startOfMonth();
        $to   = $dateTo ? Carbon::parse($dateTo)->endOfDay() : now()->endOfMonth();

        return Visit::with(['patient', 'room', 'latestSpri'])
            ->where('jenis_pelayanan', 'RANAP')
            ->whereNotNull('discharge_at')
            ->whereBetween('discharge_at', [$from, $to])
            ->orderByDesc('discharge_at')
            ->get()
            ->map(fn (Visit $v) => [
                'visit_id'       => $v->id,
                'name'           => $v->patient?->name,
                'no_rm'          => $v->patient?->no_rm,
                'room'           => $v->room?->name,
                'guarantor_type' => $v->guarantor_type,
                'no_sep'         => $v->no_sep,
                'kelas_rawat_hak' => $v->kelas_rawat_hak,
                'admission_at'   => $v->admission_at,
                'discharge_at'   => $v->discharge_at,
                'discharge_type' => $v->discharge_type,
                'follow_up_date' => $v->follow_up_date,
                'spri'           => $v->latestSpri ? $this->formatSpri($v->latestSpri) : null,
            ])->all();
    }

    private function formatSpri(BpjsSpri $s): array
    {
        return [
            'id'          => $s->id,
            'no_spri'     => $s->no_spri,
            'tgl_rencana' => $s->tgl_rencana?->toDateString(),
            'status'      => $s->status,
            'poli'        => $s->poli_kontrol,
        ];
    }

    /** Guard: pasien harus BPJS + punya SEP (dan opsional VClaim aktif). */
    private function assertBpjsRanap(Visit $visit, bool $requireEnabled = false): void
    {
        if (($visit->guarantor_type ?? null) !== 'BPJS') {
            throw new \Exception('Fitur ini hanya untuk pasien BPJS.', 422);
        }
        if (empty($visit->no_sep)) {
            throw new \Exception('Kunjungan ini belum punya SEP.', 422);
        }
        if ($requireEnabled && ! app(\App\Services\BpjsVClaimService::class)->isEnabled()) {
            throw new \Exception('Integrasi BPJS VClaim tidak aktif.', 422);
        }
    }

    /**
     * Resolve kode DPJP + kode poli BPJS untuk RANAP (tanpa doctor_schedule).
     * Poli RS Mata: pakai poli dari jadwal asal bila ada, else mapping aktif
     * pertama (RS satu spesialisasi). Guard 422 bila salah satu kosong.
     */
    private function resolveRanapDpjpPoli(Visit $visit): array
    {
        $kodeDpjp = $visit->dpjp?->bpjs_dpjp_code;

        $poliCode = $visit->doctorSchedule?->poli_code;
        $kodePoli = $poliCode ? BpjsPoliMapping::bpjsCodeFor($poliCode) : null;
        $kodePoli = $kodePoli ?: BpjsPoliMapping::where('is_active', true)->value('bpjs_poli_code');

        if (empty($kodeDpjp)) {
            throw new \Exception('DPJP belum punya kode BPJS (bpjs_dpjp_code). Lengkapi di Data Pengguna.', 422);
        }
        if (empty($kodePoli)) {
            throw new \Exception('Poli kontrol BPJS belum dipetakan. Lengkapi pemetaan poli di Integrasi BPJS.', 422);
        }

        return ['kodeDokter' => $kodeDpjp, 'poliKontrol' => $kodePoli];
    }
}
