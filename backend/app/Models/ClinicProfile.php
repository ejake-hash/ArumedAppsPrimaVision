<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ClinicProfile extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'clinic_name',
        'clinic_code',
        'subtitle',
        'tagline',
        'unit_line',
        'address',
        'phone',
        'emergency_hotline',
        'email',
        'logo_path',
        'signature_path',
        'stamp_path',
        'director_name',
        'director_sip',
        'rm_format',
        'rm_seq_length',
        'rm_last_seq',
        'pdf_engine',
        'watermark_enabled',
        'watermark_type',
        'receipt_print_settings',
        'operating_rooms',
    ];

    protected $casts = [
        'watermark_enabled'      => 'boolean',
        'receipt_print_settings' => 'array',
        'operating_rooms'        => 'array',
    ];

    /**
     * Default toggle elemen cetak kwitansi/rincian kasir. Dipakai bila kolom
     * `receipt_print_settings` masih null (klinik belum pernah set).
     */
    public const RECEIPT_PRINT_DEFAULTS = [
        'show_logo'      => true,
        'show_stamp'     => true,
        'show_esign'     => true,
        'show_footer'    => true,
        'show_watermark' => true,
    ];

    /** Setting cetak final (default ditimpa nilai tersimpan). */
    public function receiptPrintSettings(): array
    {
        return array_merge(self::RECEIPT_PRINT_DEFAULTS, $this->receipt_print_settings ?? []);
    }

    /**
     * Render KOP SURAT kanonik (sumber tunggal) sebagai HTML + inline-style.
     *
     * Dipakai BERSAMA oleh semua dokumen cetak (kwitansi, PO, pratinjau Profil,
     * dst) supaya kop SELALU identik dengan "sumber" di Profil Institusi.
     *
     * Sengaja memakai layout <table> + inline-style + satuan px, dan logo
     * di-embed base64 — agar render SAMA di tiga mesin: cetak browser,
     * Puppeteer, dan dompdf (dompdf tidak mendukung flexbox/mm/text-transform,
     * jadi UPPERCASE dilakukan di PHP, bukan via CSS).
     *
     * @param bool $withLogo  Sertakan logo (hormati toggle show_logo kwitansi).
     */
    public function renderLetterheadHtml(bool $withLogo = true): string
    {
        $e  = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $up = static fn ($v) => mb_strtoupper(trim((string) $v), 'UTF-8');

        // ── Logo → data URL base64 (portabel lintas mesin render) ──
        $logoCell = '';
        if ($withLogo && $this->logo_path) {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            if ($disk->exists($this->logo_path)) {
                try {
                    $src = 'data:' . ($disk->mimeType($this->logo_path) ?: 'image/png')
                        . ';base64,' . base64_encode($disk->get($this->logo_path));
                    $logoCell = '<td style="width:140px;padding-right:16px;vertical-align:middle;">'
                        . '<img src="' . $src . '" alt="Logo" style="width:130px;height:auto;max-height:130px;display:block;"/>'
                        . '</td>';
                } catch (\Throwable $ex) {
                    $logoCell = '';
                }
            }
        }

        // ── Blok teks ──
        $lines = '';
        if ($this->subtitle) {
            $lines .= '<div style="font-size:13px;font-weight:bold;letter-spacing:1px;color:#111;">' . $e($up($this->subtitle)) . '</div>';
        }
        $lines .= '<div style="font-size:30px;font-weight:bold;letter-spacing:0.5px;color:#0E3A66;line-height:1.02;margin:1px 0 2px;">'
            . $e($up($this->clinic_name ?: 'Nama Institusi')) . '</div>';
        if ($this->tagline) {
            $lines .= '<div style="font-size:14px;font-weight:bold;letter-spacing:1px;color:#111;">' . $e($up($this->tagline)) . '</div>';
        }
        if ($this->unit_line) {
            $lines .= '<div style="font-size:11px;font-weight:bold;text-decoration:underline;color:#111;margin-top:5px;">' . $e($up($this->unit_line)) . '</div>';
        }
        if ($this->address) {
            $lines .= '<div style="font-size:11px;font-weight:bold;color:#111;margin-top:1px;">' . $e($up($this->address)) . '</div>';
        }

        // ── Kontak (label : value), colon sejajar via tabel ──
        $row = static function (string $label, ?string $value) use ($e, $up): string {
            if (! $value) {
                return '';
            }
            return '<tr>'
                . '<td style="font-size:10.5px;font-weight:bold;color:#111;white-space:nowrap;padding-right:4px;">' . $e($up($label)) . '</td>'
                . '<td style="font-size:10.5px;font-weight:bold;color:#111;padding-right:6px;">:</td>'
                . '<td style="font-size:10.5px;font-weight:bold;color:#111;">' . $e($value) . '</td>'
                . '</tr>';
        };
        $contact = $row('Hospital Hotline', $this->phone)
            . $row('24 Hours Emergency Hotline', $this->emergency_hotline)
            . $row('Email', $this->email);
        if ($contact !== '') {
            $lines .= '<table style="border-collapse:collapse;margin-top:3px;"><tbody>' . $contact . '</tbody></table>';
        }

        return '<table style="width:100%;border-collapse:collapse;font-family:\'Times New Roman\',Georgia,serif;color:#111;"><tbody><tr>'
            . $logoCell
            . '<td style="vertical-align:middle;text-align:left;line-height:1.12;">' . $lines . '</td>'
            . '</tr></tbody></table>'
            . '<div style="border-bottom:2.5px solid #111;margin-top:6px;"></div>';
    }
}
