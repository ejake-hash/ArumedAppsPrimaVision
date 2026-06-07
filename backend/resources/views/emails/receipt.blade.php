@php
    $rp = fn ($v) => 'Rp ' . number_format((float) ($v ?? 0), 0, ',', '.');
    $clinicName = $clinic['name'] ?? 'Rumah Sakit';
@endphp
<!DOCTYPE html>
<html lang="id">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e3e8ee;">
                    <tr>
                        <td style="background:#0E3A66;padding:18px 24px;color:#ffffff;font-size:17px;font-weight:bold;">
                            {{ $clinicName }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 12px;font-size:14px;">Yth. {{ $patient['name'] ?? 'Pasien' }},</p>
                            <p style="margin:0 0 16px;font-size:13px;line-height:1.6;color:#444;">
                                Terima kasih atas kunjungan Anda. Berikut kami lampirkan
                                <strong>kwitansi rincian biaya pelayanan</strong> Anda dalam format PDF.
                            </p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f9fc;border:1px solid #e3e8ee;border-radius:8px;margin-bottom:16px;">
                                <tr><td style="padding:12px 16px;font-size:13px;color:#555;">No. Invoice</td><td style="padding:12px 16px;font-size:13px;text-align:right;font-weight:bold;">{{ $invoice['number'] ?? '—' }}</td></tr>
                                <tr><td style="padding:0 16px 12px;font-size:13px;color:#555;">Tanggal</td><td style="padding:0 16px 12px;font-size:13px;text-align:right;">{{ $invoice['date'] ?? '—' }}</td></tr>
                                <tr><td style="padding:0 16px 12px;font-size:13px;color:#555;">Total Tagihan</td><td style="padding:0 16px 12px;font-size:15px;text-align:right;font-weight:bold;color:#0E3A66;">{{ $rp($summary['total'] ?? 0) }}</td></tr>
                                <tr><td style="padding:0 16px 12px;font-size:13px;color:#555;">Status</td><td style="padding:0 16px 12px;font-size:13px;text-align:right;font-weight:bold;color:{{ ($invoice['is_paid'] ?? false) ? '#1f7d4a' : '#b91c1c' }};">{{ ($invoice['is_paid'] ?? false) ? 'LUNAS' : 'BELUM LUNAS' }}</td></tr>
                            </table>
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#777;">
                                Rincian lengkap ada pada lampiran PDF. Email ini dibuat otomatis — mohon tidak membalas.
                                Bila ada pertanyaan, hubungi {{ $clinicName }}@if(!empty($clinic['phone'])) di {{ $clinic['phone'] }}@endif.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f6f9fc;padding:14px 24px;font-size:11px;color:#999;border-top:1px solid #e3e8ee;">
                            {{ $clinicName }} · Arumed Apps
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
