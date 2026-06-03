<?php

namespace App\Services\FormRegistry;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Version;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Generate QR code sebagai SVG markup inline — TANPA ekstensi GD/Imagick.
 *
 * Dipakai Form Registry untuk menanam QR verifikasi ke rendered_html dokumen
 * (kotak stempel TTD + footer). Output berupa string <svg> yang bisa langsung
 * di-embed ke HTML; saat dicetak via Puppeteer tetap vector-crisp.
 *
 * Server produksi tidak punya ext-gd (lihat catatan deploy) — maka SVG markup
 * adalah satu-satunya output yang aman. JANGAN ganti ke QRGdImagePNG.
 */
final class QrCodeHelper
{
    /**
     * Render QR code untuk teks/URL → string SVG inline (bukan data-URI).
     *
     * @param string $data    Isi QR (di proyek ini: URL verifikasi + token).
     * @param int    $sizePx  Ukuran target render di HTML (lewat style width/height).
     */
    public static function svg(string $data, int $sizePx = 90): string
    {
        $options = new QROptions([
            'version'          => Version::AUTO,
            'outputInterface'  => QRMarkupSVG::class,
            'eccLevel'         => EccLevel::M,
            'outputBase64'     => false,   // raw <svg>, bukan data:image/svg
            'svgViewBoxSize'   => 0,       // auto viewBox dari jumlah modul
            'drawLightModules' => false,   // modul terang transparan → bg putih bersih
            'addQuietzone'     => true,
            'quietzoneSize'    => 2,
            'cssClass'         => 'rm-qr',
        ]);

        $svg = (new QRCode($options))->render($data);

        // Strip deklarasi XML (tidak valid di tengah dokumen HTML) + paksa ukuran.
        $svg = preg_replace('/<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg;
        $svg = preg_replace(
            '/<svg\b([^>]*)>/',
            '<svg$1 width="' . (int) $sizePx . '" height="' . (int) $sizePx . '" style="display:block;">',
            $svg,
            1
        ) ?? $svg;

        return $svg;
    }
}
