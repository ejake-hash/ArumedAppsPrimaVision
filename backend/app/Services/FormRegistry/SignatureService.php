<?php

namespace App\Services\FormRegistry;

use App\Models\DocumentSignature;
use App\Models\PatientDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Service untuk capture TTD digital.
 *
 * Aturan kunci (PMK 24/2022 + design doc Section 9):
 *   1. Append-only. `update()` / `delete()` throw exception (di model + sini).
 *   2. `captured_at` selalu dari server, BUKAN client clock.
 *   3. `integrity_hash` = SHA-256(svg + captured_at + patient_document_id + signer_identity).
 *      Disimpan saat capture; bisa di-verify ulang.
 *   4. Hanya boleh capture saat patient_document.status ∈ {DRAFT, RENDERED, PENDING_SIGNATURE}.
 *      Setelah FINALIZED — tidak boleh tambah signature (untuk koreksi → pakai addendum).
 *   5. `signer_external_identity` wajib untuk signer_type ∈ {witness, guardian}
 *      jika tidak ada signer_user_id / signer_patient_id.
 */
final class SignatureService
{
    /** Metode pembubuhan TTD. */
    public const SIGN_METHOD_DRAW = 'DRAW';   // goresan kanvas (pasien/saksi)
    public const SIGN_METHOD_PIN  = 'PIN';    // verifikasi PIN nakes → stempel

    /** signer_type yang merupakan nakes internal (punya akun + PIN). */
    public const INTERNAL_SIGNER_TYPES = ['doctor', 'doctor_anestesi', 'nurse', 'staff'];

    /**
     * @param array{
     *   patient_document_id: string,
     *   signer_type: string,
     *   signer_user_id?: ?string,
     *   signer_patient_id?: ?string,
     *   signer_external_identity?: ?array,
     *   signature_svg?: ?string,
     *   signature_png_base64?: ?string,
     *   signature_pin?: ?string,
     *   biometric_metadata?: ?array,
     *   audit_log?: ?array,
     *   captured_device_info?: ?array,
     *   captured_by_facilitator_user_id?: ?string,
     * } $data
     */
    public function capture(array $data): DocumentSignature
    {
        $doc = PatientDocument::query()->findOrFail($data['patient_document_id']);

        if (in_array($doc->status, ['FINALIZED', 'FINAL', 'VOID', 'REJECTED'], true)) {
            throw new RuntimeException("Dokumen sudah {$doc->status} — tidak bisa tambah signature. Pakai addendum untuk koreksi.");
        }

        $signerType = $data['signer_type'] ?? null;
        if (!in_array($signerType, DocumentSignature::SIGNER_TYPES, true)) {
            throw new RuntimeException("signer_type tidak valid: '{$signerType}'.");
        }
        $this->assertSignerIdentity($signerType, $data);

        // Tentukan metode TTD: nakes + ada signature_pin → mode PIN (stempel),
        // selain itu mode DRAW (goresan SVG) seperti perilaku lama.
        $usePin = in_array($signerType, self::INTERNAL_SIGNER_TYPES, true)
            && !empty($data['signature_pin']);

        $nameSnapshot = null;
        $roleSnapshot = null;

        if ($usePin) {
            // Verifikasi PIN akun nakes.
            // PENTING: di proyek ini PIN disimpan PLAINTEXT (kolom varchar(6)),
            // bukan hash — bandingkan timing-safe dengan hash_equals, sesuai pola
            // kanonik DokterController::verifyPin. (DokterService::signDocument
            // memakai Hash::check → itu bug lama yang membuat TTD PIN selalu gagal.)
            $user = User::query()->with('employee')->find($data['signer_user_id'] ?? null);
            if ($user === null) {
                throw new RuntimeException('User penandatangan tidak ditemukan.');
            }
            if (empty($user->pin)) {
                throw new RuntimeException('Akun ini belum mengatur PIN tanda tangan.');
            }
            if (!hash_equals((string) $user->pin, (string) $data['signature_pin'])) {
                // 401 — dipakai controller untuk status code.
                throw new RuntimeException('PIN tidak sesuai.', 401);
            }

            // Bekukan identitas untuk stempel + halaman verifikasi.
            $emp = $user->employee;
            $nameSnapshot = $emp?->name ?? $user->name;
            $roleSnapshot = $this->buildRoleSnapshot($emp);

            // Mode PIN tidak menyimpan goresan.
            $data['signature_svg']        = null;
            $data['signature_png_base64'] = null;
        }

        $now = now();
        $signatureId = 'sig_' . Str::ulid()->toBase32();
        $svg = $data['signature_svg'] ?? '';
        $identityKey = $this->signerIdentityKey($data);
        $signMethod = $usePin ? self::SIGN_METHOD_PIN : self::SIGN_METHOD_DRAW;

        // Hash pakai format detik biasa — microseconds tidak konsisten across
        // DB driver (SQLite text vs Postgres timestamp(3)). Trade-off: hash
        // collision dalam detik yang sama hampir mustahil di praktek.
        // Mode PIN tidak punya SVG → ikutkan sign_method + nama snapshot supaya
        // tetap tamper-evident.
        $hash = hash(
            'sha256',
            $svg . '|' . $now->format('Y-m-d H:i:s') . '|' . $doc->id . '|' . $identityKey
                . '|' . $signMethod . '|' . ($nameSnapshot ?? '')
        );

        return DB::transaction(function () use ($data, $doc, $signatureId, $now, $hash, $signMethod, $nameSnapshot, $roleSnapshot) {
            $sig = DocumentSignature::create([
                'signature_id'                    => $signatureId,
                'patient_document_id'             => $doc->id,
                'signer_type'                     => $data['signer_type'],
                'sign_method'                     => $signMethod,
                'signer_user_id'                  => $data['signer_user_id']     ?? null,
                'signer_patient_id'               => $data['signer_patient_id']  ?? null,
                'signer_external_identity'        => $data['signer_external_identity'] ?? null,
                'signer_name_snapshot'            => $nameSnapshot,
                'signer_role_snapshot'            => $roleSnapshot,
                'signature_svg'                   => $data['signature_svg']       ?? null,
                'signature_png_base64'            => $data['signature_png_base64'] ?? null,
                'captured_at'                     => $now,
                'captured_device_info'            => $data['captured_device_info'] ?? [],
                'captured_by_facilitator_user_id' => $data['captured_by_facilitator_user_id'] ?? null,
                'biometric_metadata'              => $data['biometric_metadata']  ?? null,
                'audit_log'                       => $data['audit_log']           ?? [],
                'integrity_hash'                  => $hash,
                'created_at'                      => $now,
            ]);

            // Auto-advance status: DRAFT → RENDERED → PENDING_SIGNATURE
            // (soft enforcement — kalau ada signature, dokumen masuk PENDING_SIGNATURE).
            if (in_array($doc->status, ['DRAFT', 'RENDERED'], true)) {
                $doc->status = 'PENDING_SIGNATURE';
                $doc->save();
            }

            FormRegistryAudit::record(
                'FORM_SIG_CAPTURED',
                model: 'DocumentSignature',
                modelId: $sig->id,
                description: "Signature signer_type={$sig->signer_type}",
                context: [
                    'signature_id'        => $sig->signature_id,
                    'patient_document_id' => $doc->id,
                    'signer_type'         => $sig->signer_type,
                    'sign_method'         => $sig->sign_method,
                    'signer_name'         => $sig->signer_name_snapshot,
                    'integrity_hash'      => $hash,
                    'biometric_summary'   => $sig->biometric_metadata,
                ],
            );

            return $sig;
        });
    }

    /**
     * Verifikasi integrity hash: re-hash dan bandingkan.
     * Return: { valid: bool, expected: string, actual: string }
     */
    public function verify(string $signatureId): array
    {
        $sig = DocumentSignature::query()->where('signature_id', $signatureId)->firstOrFail();
        $identityKey = $this->signerIdentityKey([
            'signer_user_id'           => $sig->signer_user_id,
            'signer_patient_id'        => $sig->signer_patient_id,
            'signer_external_identity' => $sig->signer_external_identity,
        ]);
        $expected = hash(
            'sha256',
            ($sig->signature_svg ?? '') . '|' . $sig->captured_at->format('Y-m-d H:i:s') . '|' . $sig->patient_document_id . '|' . $identityKey
                . '|' . ($sig->sign_method ?? self::SIGN_METHOD_DRAW) . '|' . ($sig->signer_name_snapshot ?? '')
        );

        return [
            'valid'    => hash_equals($expected, $sig->integrity_hash),
            'expected' => $expected,
            'actual'   => $sig->integrity_hash,
            'signature_id' => $sig->signature_id,
        ];
    }

    /** @return list<DocumentSignature> */
    public function listByDocument(string $patientDocumentId): array
    {
        return DocumentSignature::query()
            ->where('patient_document_id', $patientDocumentId)
            ->orderBy('captured_at')
            ->get()
            ->all();
    }

    /** Status PatientDocument non-final yang masih bisa di-TTD dokter. */
    private const TTD_QUEUE_STATUSES = ['DRAFT', 'RENDERED', 'PENDING_SIGNATURE'];

    /**
     * Daftar kode template aktif yang field_schema-nya butuh TTD dokter.
     * Pre-compute SEKALI atas koleksi TEMPLATE (puluhan), bukan DOKUMEN (ribuan)
     * — supaya filter "butuh TTD dokter" bisa dipakai sebagai whereIn SQL.
     *
     * @return array{codes: list<string>, names: array<string,string>}
     */
    private function doctorSignatureTemplates(string $signerType = 'doctor'): array
    {
        $templates = \App\Models\DocumentTemplate::query()
            ->where('is_active', true)
            ->get(['code', 'name', 'field_schema']);

        $codes = [];
        $names = [];
        foreach ($templates as $tpl) {
            if (!$tpl->code || !is_array($tpl->field_schema)) {
                continue;
            }
            if ($this->schemaRequiresDoctorSignature($tpl->field_schema, $signerType)) {
                $codes[]            = $tpl->code;
                $names[$tpl->code]  = $tpl->name;
            }
        }

        return ['codes' => $codes, 'names' => $names];
    }

    /**
     * Builder query antrian TTD dokter — terfilter SEMUA di SQL (skalabel ribuan dok).
     * Dipakai bersama oleh ttdQueueForDoctor() (paginate) & ttdCountForDoctor() (count).
     *
     * @param list<string> $templateCodes Hasil doctorSignatureTemplates()['codes'].
     * @param array{search?: ?string, status?: ?string, date_from?: ?string, date_to?: ?string} $opts
     */
    private function ttdQueueBuilder(string $userId, array $templateCodes, array $opts = [], string $signerType = 'doctor'): \Illuminate\Database\Eloquent\Builder
    {
        $statuses = self::TTD_QUEUE_STATUSES;
        if (!empty($opts['status']) && in_array($opts['status'], $statuses, true)) {
            $statuses = [$opts['status']];
        }

        $q = PatientDocument::query()
            ->whereIn('status', $statuses)
            ->whereIn('template_code', $templateCodes)
            ->whereHas('visit')
            ->whereDoesntHave('documentSignatures', function ($sq) use ($userId, $signerType) {
                $sq->where('signer_type', $signerType)->where('signer_user_id', $userId);
            });

        // Antrean dokter anestesi: hanya dokumen yang MEMANG butuh TTD anestesi
        // (pending_signature_roles memuat 'ANESTESI'). Operasi tanpa anestesi punya
        // slot anestesi non-wajib → jangan bocor ke antrean dokter anestesi.
        if ($signerType === 'doctor_anestesi') {
            $q->whereJsonContains('pending_signature_roles', 'ANESTESI');
        }

        // Dokter (DPJP) hanya melihat dokumen pada kunjungan yang menjadi
        // tanggung jawabnya. Dua jalur DPJP, di-OR:
        //   (a) PEMERIKSA poli/RME  → doctorExamination.doctor_id = employee akun
        //   (b) OPERATOR bedah      → surgerySchedule.lead_surgeon_id = employee akun
        // Tanpa cabang (b), dokumen bedah (Laporan Pembedahan/Catatan Operasi/
        // Laporan Vitreo-Retina) pada visit bedah yang TAK punya baris pemeriksaan
        // poli akan hilang dari SEMUA antrean — pasien bedah memang sudah ber-DPJP
        // sejak poliklinik dan operator di ruang bedah = DPJP itu.
        // Antrean tidak tergabung antar-dokter. (Anestesi dikecualikan — bukan DPJP;
        // sudah disaring lewat pending_signature_roles di atas. Akun tanpa
        // employee_id → fallback tanpa filter agar tak terkunci kosong.)
        if ($signerType === 'doctor') {
            $employeeId = User::whereKey($userId)->value('employee_id');
            if ($employeeId) {
                $q->where(function ($w) use ($employeeId) {
                    $w->whereHas('visit.doctorExamination', fn ($e) => $e->where('doctor_id', $employeeId))
                      ->orWhereHas('visit.surgerySchedule', fn ($s) => $s->where('lead_surgeon_id', $employeeId))
                      // IGD: DPJP = dokter jaga yang menerbitkan asesmen RM 3.7
                      // (visit IGD tak punya doctorExamination/surgerySchedule).
                      ->orWhereHas('visit.igdAssessment', fn ($a) => $a->where('doctor_id', $employeeId));
                });
            }
        }

        $search = trim((string) ($opts['search'] ?? ''));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('template_code', 'ILIKE', "%{$search}%")
                  ->orWhereHas('patient', function ($p) use ($search) {
                      $p->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('no_rm', 'ILIKE', "%{$search}%");
                  });
            });
        }

        // Filter tanggal KUNJUNGAN (visit_date) — 1 tanggal (from=to) atau rentang.
        // Format string 'Y-m-d' divalidasi di controller; whereDate aman lintas-zona.
        $dateFrom = trim((string) ($opts['date_from'] ?? ''));
        $dateTo   = trim((string) ($opts['date_to'] ?? ''));
        if ($dateFrom !== '' || $dateTo !== '') {
            $q->whereHas('visit', function ($v) use ($dateFrom, $dateTo) {
                if ($dateFrom !== '') { $v->whereDate('visit_date', '>=', $dateFrom); }
                if ($dateTo   !== '') { $v->whereDate('visit_date', '<=', $dateTo); }
            });
        }

        return $q;
    }

    /**
     * Daftar antrian TTD untuk dokter yang login — query terfilter di SQL + pagination.
     * Filter: status ∈ {DRAFT,RENDERED,PENDING_SIGNATURE}, template butuh TTD dokter,
     * punya visit, dan dokter ini BELUM TTD. Output diratakan per-dokumen + meta paginator.
     *
     * @param array{page?: int, per_page?: int, search?: ?string, status?: ?string, date_from?: ?string, date_to?: ?string} $opts
     */
    public function ttdQueueForDoctor(string $userId, array $opts = [], string $signerType = 'doctor'): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $tpl     = $this->doctorSignatureTemplates($signerType);
        $codes   = $tpl['codes'];
        $names   = $tpl['names'];
        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 10)));

        // Tak ada template ber-TTD-dokter → paginator kosong (jangan query lebih lanjut).
        if (empty($codes)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $paginator = $this->ttdQueueBuilder($userId, $codes, $opts, $signerType)
            ->with(['patient', 'visit.doctorExamination.doctor'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        // Ratakan tiap baris (FE konsumsi flat) — through() mempertahankan meta.
        return $paginator->through(fn (PatientDocument $d) => [
            'id'            => $d->id,
            'template_code' => $d->template_code,
            'template_name' => $names[$d->template_code] ?? null,
            'status'        => $d->status,
            'created_at'    => $d->created_at,
            'visit_id'      => $d->visit_id,
            'visit_date'    => $d->visit?->visit_date,
            'review_doctor' => $d->visit?->doctorExamination?->doctor?->name,
            'patient'       => [
                'id'     => $d->patient_id,
                'no_rm'  => $d->patient?->no_rm,
                'name'   => $d->patient?->name,
                'gender' => $d->patient?->gender,
            ],
        ]);
    }

    /** Jumlah dokumen di antrian TTD dokter (untuk badge). Reuse builder yang sama. */
    public function ttdCountForDoctor(string $userId, string $signerType = 'doctor'): int
    {
        $codes = $this->doctorSignatureTemplates($signerType)['codes'];
        if (empty($codes)) {
            return 0;
        }
        return $this->ttdQueueBuilder($userId, $codes, [], $signerType)->count();
    }

    /**
     * Dokumen yang sudah DITANDATANGANI dokter ini pada satu hari / rentang tanggal
     * (zona Asia/Jakarta). Default (tanpa date_from/date_to) = HARI INI — mempertahankan
     * identitas tab "Ditandatangani hari ini". Sumber kebenaran = baris DocumentSignature
     * milik dokter (append-only, created_at = waktu tanda tangan server). Paginated +
     * search opsional (pasien/No.RM/jenis dok).
     *
     * @param array{page?: int, per_page?: int, search?: ?string, date_from?: ?string, date_to?: ?string} $opts
     */
    public function signedTodayForDoctor(string $userId, array $opts = [], string $signerType = 'doctor'): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 10)));
        $names   = $this->doctorSignatureTemplates($signerType)['names'];
        $search  = trim((string) ($opts['search'] ?? ''));

        // Rentang hari WIB (wall-clock). Kolom created_at = `timestamp without time
        // zone` & APP_TIMEZONE=Asia/Jakarta → tersimpan sbg jam dinding WIB, jadi
        // pembanding HARUS WIB juga (TANPA konversi UTC). Kosong → hari ini.
        [$startOfDay, $endOfDay] = $this->resolveSignedDateRange($opts);

        // Akun TANPA employee (superadmin/pengawas) melihat SELURUH antrean TTD
        // (ttdQueueBuilder melepas filter DPJP saat employee_id null). Tab
        // "Ditanda Tangani" harus SIMETRIS — kalau hanya difilter signer_user_id,
        // akun pengawas yang tak pernah TTD sendiri selalu kosong walau ada ribuan
        // dokumen tertanda. Maka: ada employee → riwayat TTD-nya sendiri (dokter);
        // tanpa employee → semua tanda tangan signer_type ini.
        $scopeToSelf = (bool) User::whereKey($userId)->value('employee_id');

        $paginator = DocumentSignature::query()
            ->where('signer_type', $signerType)
            ->when($scopeToSelf, fn ($q) => $q->where('signer_user_id', $userId))
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereHas('patientDocument', function ($dq) use ($search) {
                if ($search !== '') {
                    $dq->where(function ($w) use ($search) {
                        $w->where('template_code', 'ILIKE', "%{$search}%")
                          ->orWhereHas('patient', function ($p) use ($search) {
                              $p->where('name', 'ILIKE', "%{$search}%")
                                ->orWhere('no_rm', 'ILIKE', "%{$search}%");
                          });
                    });
                }
            })
            ->with(['patientDocument.patient', 'patientDocument.visit'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return $paginator->through(function (DocumentSignature $sig) use ($names) {
            $d = $sig->patientDocument;
            return [
                'id'            => $d?->id,
                'signature_id'  => $sig->id,
                'template_code' => $d?->template_code,
                'template_name' => $names[$d?->template_code] ?? null,
                'status'        => $d?->status,
                'signed_at'     => $sig->created_at,
                'visit_id'      => $d?->visit_id,
                'visit_date'    => $d?->visit?->visit_date,
                'patient'       => [
                    'id'     => $d?->patient_id,
                    'no_rm'  => $d?->patient?->no_rm,
                    'name'   => $d?->patient?->name,
                    'gender' => $d?->patient?->gender,
                ],
            ];
        });
    }

    /**
     * Resolve rentang waktu (WIB wall-clock) untuk filter "ditandatangani".
     * date_from/date_to berupa tanggal WIB (Y-m-d). Tanpa keduanya → hari ini (WIB).
     * Salah satu sisi kosong → pakai sisi yang ada untuk membatasi rentang terbuka.
     *
     * Kolom created_at = `timestamp without time zone` & APP_TIMEZONE=Asia/Jakarta →
     * Laravel menyimpan/membaca sbg jam dinding WIB. Maka rentang DITAHAN di zona WIB
     * (TANPA ->utc()); konversi UTC dulu menggeser jendela 7 jam → TTD sore (>17:00
     * WIB) hilang & TTD sore hari sebelumnya bocor masuk. Konsisten dgn pola
     * whereDate('created_at', today()) di seluruh aplikasi.
     *
     * @param array{date_from?: ?string, date_to?: ?string} $opts
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function resolveSignedDateRange(array $opts): array
    {
        $from = trim((string) ($opts['date_from'] ?? '')) ?: null;
        $to   = trim((string) ($opts['date_to'] ?? '')) ?: null;

        if ($from === null && $to === null) {
            return [
                \Illuminate\Support\Carbon::now('Asia/Jakarta')->startOfDay(),
                \Illuminate\Support\Carbon::now('Asia/Jakarta')->endOfDay(),
            ];
        }

        $start = \Illuminate\Support\Carbon::parse($from ?? $to, 'Asia/Jakarta')->startOfDay();
        $end   = \Illuminate\Support\Carbon::parse($to ?? $from, 'Asia/Jakarta')->endOfDay();

        return [$start, $end];
    }

    /** Jumlah dokumen yang ditandatangani dokter ini hari ini (WIB) — untuk kartu statistik. */
    public function signedTodayCountForDoctor(string $userId, string $signerType = 'doctor'): int
    {
        // WIB wall-clock (lihat resolveSignedDateRange: created_at tersimpan jam WIB).
        $startOfDay = \Illuminate\Support\Carbon::now('Asia/Jakarta')->startOfDay();
        $endOfDay   = \Illuminate\Support\Carbon::now('Asia/Jakarta')->endOfDay();

        // Selaras signedTodayForDoctor: akun tanpa employee (pengawas) menghitung
        // SEMUA tanda tangan signer_type ini (kartu konsisten dgn daftar & antrean).
        $scopeToSelf = (bool) User::whereKey($userId)->value('employee_id');

        return DocumentSignature::query()
            ->where('signer_type', $signerType)
            ->when($scopeToSelf, fn ($q) => $q->where('signer_user_id', $userId))
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->count();
    }

    /**
     * Tandatangani banyak dokumen sekaligus sebagai dokter (PIN diverifikasi SEKALI).
     * Best-effort per-dokumen: 1 dokumen gagal tak memblok lainnya (capture & finalize
     * masing-masing dalam transaction sendiri).
     *
     * @param list<string> $documentIds
     * @return array{signed: list<string>, skipped: array<int,array{id:string,reason:string}>, failed: array<int,array{id:string,error:string}>}
     */
    public function bulkSignAsDoctor(string $userId, array $documentIds, string $pin, string $signerType = 'doctor'): array
    {
        // Verifikasi PIN SEKALI di awal (fail-fast). PIN PLAINTEXT → hash_equals,
        // BUKAN Hash::check (pola kanonik DokterController::verifyPin).
        $user = User::query()->find($userId);
        if ($user === null) {
            throw new RuntimeException('User penandatangan tidak ditemukan.', 404);
        }
        if (empty($user->pin)) {
            throw new RuntimeException('Akun ini belum mengatur PIN tanda tangan.', 422);
        }
        if (!hash_equals((string) $user->pin, (string) $pin)) {
            throw new RuntimeException('PIN tidak sesuai.', 401);
        }

        /** @var FormRegistryService $registry */
        $registry = app(FormRegistryService::class);

        $signed  = [];
        $skipped = [];
        $failed  = [];

        foreach (array_unique($documentIds) as $docId) {
            try {
                // capture() verifikasi ulang PIN (murah, plaintext) & auto-advance
                // DRAFT/RENDERED → PENDING_SIGNATURE. Transaction internal capture.
                $this->capture([
                    'patient_document_id' => $docId,
                    'signer_type'         => $signerType,
                    'signer_user_id'      => $userId,
                    'signature_pin'       => $pin,
                ]);
            } catch (\Throwable $e) {
                // PIN sudah lolos di awal → error di sini = dokumen bermasalah.
                $failed[] = ['id' => $docId, 'error' => $e->getMessage()];
                continue;
            }

            // Coba finalize bila semua required signer lengkap. Kalau masih ada
            // signer wajib lain (mis. TTD pasien) → dokter TETAP ter-TTD, finalize
            // ditunda → masuk skipped (bukan failed).
            try {
                $registry->finalize($docId);
                $signed[] = $docId;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $docId, 'reason' => $e->getMessage()];
            }
        }

        return ['signed' => $signed, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function schemaRequiresDoctorSignature(array $schema, string $signerType = 'doctor'): bool
    {
        $fields = $schema['fields'] ?? [];
        if (empty($fields) && isset($schema['pages'])) {
            foreach ($schema['pages'] as $page) {
                if (isset($page['fields']) && is_array($page['fields'])) {
                    $fields = array_merge($fields, $page['fields']);
                }
            }
        }
        foreach ($fields as $f) {
            if (($f['type'] ?? null) === 'signature_canvas' && ($f['signer_type'] ?? null) === $signerType) {
                return true;
            }
        }
        return false;
    }

    private function signerIdentityKey(array $data): string
    {
        if (!empty($data['signer_user_id']))    return 'user:' . $data['signer_user_id'];
        if (!empty($data['signer_patient_id'])) return 'patient:' . $data['signer_patient_id'];
        if (!empty($data['signer_external_identity'])) {
            // Normalisasi: encode JSON dengan sort key supaya stable hash.
            $arr = $data['signer_external_identity'];
            ksort($arr);
            return 'ext:' . json_encode($arr, JSON_UNESCAPED_UNICODE);
        }
        return 'anon';
    }

    private function assertSignerIdentity(string $signerType, array $data): void
    {
        $needsUser     = in_array($signerType, self::INTERNAL_SIGNER_TYPES, true);
        $needsPatient  = $signerType === 'patient';
        $needsExternal = in_array($signerType, ['witness', 'guardian'], true);

        if ($needsUser && empty($data['signer_user_id'])) {
            throw new RuntimeException("signer_type='{$signerType}' butuh signer_user_id.");
        }
        if ($needsPatient && empty($data['signer_patient_id'])) {
            throw new RuntimeException("signer_type='patient' butuh signer_patient_id.");
        }
        if ($needsExternal) {
            $ext = $data['signer_external_identity'] ?? null;
            if (!is_array($ext) || empty($ext['nama'])) {
                throw new RuntimeException("signer_type='{$signerType}' butuh signer_external_identity dengan minimal field 'nama'.");
            }
        }
    }

    /**
     * Bangun string jabatan untuk stempel: "Dokter Spesialis Mata · SIP 446/123".
     * Ambil dari profession + SIP/STR employee. Null-safe.
     */
    private function buildRoleSnapshot(?\App\Models\Employee $emp): ?string
    {
        if ($emp === null) {
            return null;
        }
        $parts = [];
        if (!empty($emp->profession)) {
            $parts[] = $emp->profession;
        }
        // Jangan dobel prefix bila data sudah memuat "SIP"/"STR".
        if (!empty($emp->sip)) {
            $parts[] = preg_match('/^\s*SIP\b/i', (string) $emp->sip) ? $emp->sip : 'SIP ' . $emp->sip;
        } elseif (!empty($emp->str)) {
            $parts[] = preg_match('/^\s*STR\b/i', (string) $emp->str) ? $emp->str : 'STR ' . $emp->str;
        }
        return $parts ? implode(' · ', $parts) : null;
    }
}
