<?php

namespace App\Http\Controllers;

use App\Models\DiagnosticOrder;
use App\Models\DiagnosticTestType;
use App\Services\AccessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feed Modality Worklist untuk alat penunjang (OCT Maestro / USG Aviso).
 *
 * Dipanggil proses FEEDER (di luar app) yang mengubah JSON ini menjadi file DICOM
 * `.wl` lalu melayani C-FIND MWL ke alat. App TIDAK bicara DICOM — hanya emit JSON.
 * Auth = service token (middleware service-token). Read-only.
 */
class PenunjangWorklistController extends Controller
{
    public function __construct(private readonly AccessionService $accession) {}

    /** GET /integrasi/penunjang/worklist?date=YYYY-MM-DD&modality=US */
    public function index(Request $request): JsonResponse
    {
        $date           = $request->query('date');           // opsional, default hari ini
        $modalityFilter = $request->query('modality');       // opsional, saring per alat
        $scheduledDate  = $date ? \Illuminate\Support\Carbon::parse($date) : today();

        $orders = DiagnosticOrder::with('visit.patient')
            ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
            ->whereNotNull('accession_number')
            ->whereDate('created_at', $date ?: today())
            ->whereHas('visit.patient')
            ->orderBy('created_at')
            ->get();

        // Nama pemeriksaan (label) dari master jenis penunjang, lookup sekali.
        $names = DiagnosticTestType::whereIn('code', $orders->pluck('test_type')->unique()->all())
            ->pluck('name', 'code');

        $rows = [];
        foreach ($orders as $order) {
            $patient  = $order->visit?->patient;
            $modality = $this->accession->modalityFor($order->test_type);

            if ($modalityFilter && $modality !== $modalityFilter) {
                continue;
            }

            $rows[] = [
                'accession_number'    => $order->accession_number,
                'order_id'            => $order->id,
                'no_rm'               => $patient?->no_rm,
                'patient_name'        => $patient?->name,
                'dob'                 => $patient?->date_of_birth?->format('Y-m-d'),
                'gender'              => $this->dicomSex($patient?->gender),
                'modality'            => $modality,
                'scheduled_date'      => $scheduledDate->format('Y-m-d'),
                'eye_side'            => $order->eye_side,
                'test_code'           => $order->test_type,
                'test_name'           => $names[$order->test_type] ?? $order->test_type,
                'visit_no_registrasi' => $order->visit?->no_registrasi,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $rows,
            'message' => 'Worklist penunjang',
            'errors'  => null,
        ]);
    }

    /** Peta gender internal (L/P) → DICOM PatientSex (M/F/O). */
    private function dicomSex(?string $gender): string
    {
        return match ($gender) {
            'L'     => 'M',
            'P'     => 'F',
            default => 'O',
        };
    }
}
