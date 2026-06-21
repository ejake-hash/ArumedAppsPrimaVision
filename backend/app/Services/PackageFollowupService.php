<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\PackageFollowupEntitlement;
use App\Models\SurgeryPackageTariff;
use App\Models\SurgerySchedule;
use App\Models\VisitSurgeryPackage;
use Illuminate\Support\Facades\Log;

/**
 * "Konsultasi kontrol gratis pasca-bedah" (Opsi B).
 *
 * - issueForOperation(): saat operasi selesai → terbitkan hak per paket pasien yang
 *   VARIAN TARIF terpilihnya punya followup_procedure_id. Manfaat kini per tarif jual
 *   per penjamin (bukan per master paket). Idempoten per (operasi, paket, prosedur).
 * - redeemPaidInvoice(): saat invoice kontrol lunas → tandai hak terpakai utk tiap
 *   baris diskon DISKON_KONTROL (referensi hak). Penerbitan baris diskonnya sendiri
 *   ada di KasirService::buildFollowupConsultLines (agar bisa di-override kasir).
 */
class PackageFollowupService
{
    /**
     * Terbitkan hak kontrol gratis untuk satu OPERASI (surgery_schedule) yang selesai.
     * Non-fatal: error di-log, tidak melempar (jangan blokir penyelesaian operasi).
     */
    public function issueForOperation(string $scheduleId): int
    {
        try {
            $schedule = SurgerySchedule::with('visit')->find($scheduleId);
            $visit    = $schedule?->visit;
            if (! $visit || ! $visit->patient_id) {
                return 0;
            }

            // Paket pasien yang berlaku untuk operasi ini: snapshot terikat schedule ini
            // ATAU visit-level (surgery_schedule_id null, dipasang lewat planning).
            $snaps = VisitSurgeryPackage::where('visit_id', $visit->id)
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('surgery_schedule_id', $scheduleId)->orWhereNull('surgery_schedule_id'))
                ->get();

            $issued = 0;
            foreach ($snaps as $snap) {
                if (! $snap->source_surgery_package_id) {
                    continue;
                }

                // Manfaat kini nempel di VARIAN TARIF terpilih (per penjamin). Pakai
                // varian yang direkam snapshot; bila kosong (snapshot lama), resolve
                // varian default utk penjamin pasien (TPA-aware) seperti billing.
                $tariff = $this->resolveTariffForSnapshot($snap, $visit);
                if (! $tariff || ! $tariff->grantsFollowup()) {
                    continue;
                }

                $validUntil = $tariff->followup_valid_days
                    ? today()->addDays((int) $tariff->followup_valid_days)
                    : null;

                $ent = PackageFollowupEntitlement::firstOrCreate(
                    [
                        'surgery_schedule_id'       => $scheduleId,
                        'source_surgery_package_id' => $snap->source_surgery_package_id,
                        'procedure_id'              => $tariff->followup_procedure_id,
                    ],
                    [
                        'patient_id'      => $visit->patient_id,
                        'source_visit_id' => $visit->id,
                        'total_count'     => max(1, (int) $tariff->followup_count),
                        'used_count'      => 0,
                        'valid_until'     => $validUntil,
                        'is_active'       => true,
                    ]
                );
                if ($ent->wasRecentlyCreated) {
                    $issued++;
                }
            }

            return $issued;
        } catch (\Throwable $e) {
            Log::warning('[PackageFollowup] issueForOperation gagal: ' . $e->getMessage(), ['schedule_id' => $scheduleId]);
            return 0;
        }
    }

    /**
     * Varian tarif yang berlaku untuk satu snapshot paket pasien — sumber manfaat
     * kontrol pasca-bedah. Utamakan varian yang DIPILIH saat planning (direkam di
     * snapshot); bila kosong (snapshot lama / belum tercatat), resolve varian default
     * untuk penjamin pasien lewat logika billing (TPA-aware) lalu muat modelnya.
     */
    private function resolveTariffForSnapshot(VisitSurgeryPackage $snap, $visit): ?SurgeryPackageTariff
    {
        if ($snap->surgery_package_tariff_id) {
            $tariff = SurgeryPackageTariff::find($snap->surgery_package_tariff_id);
            if ($tariff) {
                return $tariff;
            }
        }

        // Lazy resolve KasirService (hindari ketergantungan melingkar: KasirService
        // memanggil service ini saat pelunasan).
        $resolved = app(KasirService::class)->resolvePackageTariff(
            $snap->source_surgery_package_id, $visit->guarantor_type, $visit->insurer_id, null
        );

        return ($resolved && isset($resolved->id)) ? SurgeryPackageTariff::find($resolved->id) : null;
    }

    /**
     * Tandai hak terpakai untuk invoice yang LUNAS: tiap baris DISKON_KONTROL menunjuk
     * (reference_id) ke satu hak. Idempoten — tidak menebus dua kali untuk visit yang sama,
     * dan tidak melebihi jatah. Dipanggil dari KasirService::processPayment (saat lunas).
     */
    public function redeemPaidInvoice(BillingInvoice $invoice): void
    {
        $items = $invoice->items()->where('item_type', 'DISKON_KONTROL')->whereNotNull('reference_id')->get();
        foreach ($items as $it) {
            $ent = PackageFollowupEntitlement::find($it->reference_id);
            if (! $ent) {
                continue;
            }
            // Idempoten + jangan over-redeem.
            if ($ent->redeemed_visit_id === $invoice->visit_id || $ent->used_count >= $ent->total_count) {
                continue;
            }
            $ent->used_count++;
            $ent->redeemed_visit_id = $invoice->visit_id;
            $ent->redeemed_at       = now();
            if ($ent->used_count >= $ent->total_count) {
                $ent->is_active = false;
            }
            $ent->save();
        }
    }
}
