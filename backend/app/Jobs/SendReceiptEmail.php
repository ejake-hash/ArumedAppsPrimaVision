<?php

namespace App\Jobs;

use App\Mail\ReceiptMail;
use App\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Kirim kwitansi PDF ke email pasien + catat status per-invoice supaya kasir
 * tahu hasilnya (ANTRE → TERKIRIM / GAGAL), bukan asal "dikirim".
 *
 * PDF dirender di worker (di dalam ReceiptMail::build). $data dirakit di konteks
 * request (KasirService::emailReceipt) agar nama kasir & auth benar.
 */
class SendReceiptEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param array<string,mixed> $data Payload generateReceipt()
     */
    public function __construct(
        public string $invoiceId,
        public string $email,
        public array $data,
        public ?string $invoiceNumber = null,
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)->send(new ReceiptMail($this->data, $this->invoiceNumber));

        BillingInvoice::where('id', $this->invoiceId)->update([
            'receipt_email_status' => 'SENT',
            'receipt_email_at'     => now(),
            'receipt_email_error'  => null,
        ]);
    }

    /**
     * Dipanggil setelah seluruh retry habis — tandai GAGAL agar kasir bisa
     * kirim ulang dari UI.
     */
    public function failed(\Throwable $e): void
    {
        BillingInvoice::where('id', $this->invoiceId)->update([
            'receipt_email_status' => 'FAILED',
            'receipt_email_at'     => now(),
            'receipt_email_error'  => mb_substr($e->getMessage(), 0, 255),
        ]);
    }
}
