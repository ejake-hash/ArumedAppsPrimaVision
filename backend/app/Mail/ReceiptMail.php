<?php

namespace App\Mail;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Email kwitansi pasien dengan lampiran PDF.
 *
 * Mailable biasa (TIDAK ShouldQueue) — dikirim sinkron dari dalam job
 * App\Jobs\SendReceiptEmail, supaya job bisa mencatat status SENT/FAILED
 * per-invoice. $data = hasil KasirService::generateReceipt() (dirakit di
 * konteks request agar nama kasir & auth benar).
 */
class ReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string,mixed> $data Payload generateReceipt()
     */
    public function __construct(
        public array $data,
        public ?string $invoiceNumber = null,
    ) {}

    public function build(): self
    {
        $clinicName = $this->data['clinic']['name'] ?? 'Rumah Sakit';
        $number     = $this->invoiceNumber ?? ($this->data['invoice']['number'] ?? '');

        // Remote enabled agar logo (URL absolut) bisa dimuat dompdf tanpa file lokal.
        $pdf = Pdf::loadView('pdf.receipt', $this->data)
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', true);

        $safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $number) ?: 'invoice';

        return $this->subject("Kwitansi {$number} — {$clinicName}")
            ->view('emails.receipt', $this->data)
            ->attachData($pdf->output(), "Kwitansi-{$safeNumber}.pdf", [
                'mime' => 'application/pdf',
            ]);
    }
}
