<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Email laporan harian tunggakan kasir (kunjungan belum tutup kasir + umurnya).
 * Mailable sinkron biasa — dikirim dari command kasir:report-backlog.
 */
class KasirBacklogReport extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function __construct(
        public array $rows,
        public int $overdueCount,
        public float $sumTotal,
        public int $threshold,
        public string $reportDate,
    ) {}

    public function build(): self
    {
        $clinic = config('mail.from.name') ?: 'Klinik';
        return $this->subject("[{$clinic}] Tunggakan Kasir {$this->reportDate} — " . count($this->rows) . ' tagihan belum tutup')
            ->view('emails.kasir_backlog', [
                'rows'         => $this->rows,
                'overdueCount' => $this->overdueCount,
                'sumTotal'     => $this->sumTotal,
                'threshold'    => $this->threshold,
                'reportDate'   => $this->reportDate,
            ]);
    }
}
