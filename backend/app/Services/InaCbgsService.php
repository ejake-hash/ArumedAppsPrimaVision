<?php

namespace App\Services;

use App\Models\BpjsClaim;
use App\Models\InacbgsGroupingLog;
use App\Models\IntegrationConfig;

/**
 * INA-CBGs Grouper — tarif klaim BPJS dari diagnosis + tindakan.
 *
 * PLACEHOLDER — 2 mode engine:
 *   JAR  : proc_open() ke file .jar INA-CBGs (update tiap tahun dari Kemkes)
 *   API  : HTTP call ke grouper API (jika tersedia)
 *
 * Versi grouper diupdate tahunan. Saat ini: v5.2 (2024).
 */
class InaCbgsService
{
    private const CURRENT_VERSION = '5.2';

    private ?IntegrationConfig $config = null;

    public function boot(): void
    {
        $this->config = IntegrationConfig::where('system_name', 'INACBGS')->first();
    }

    public function getGrouperVersion(): string
    {
        return $this->config?->configuration['version'] ?? self::CURRENT_VERSION;
    }

    public function getEngineType(): string
    {
        return $this->config?->configuration['engine_type'] ?? 'JAR';
    }

    // =========================================================================
    // GROUPER
    // =========================================================================

    /**
     * Run INA-CBGs grouper for a given diagnosis + tindakan input.
     *
     * @param  array  $input  {
     *   'diagnosis_utama'    => string (ICD-10)
     *   'diagnosis_sekunder' => string[] (ICD-10[])
     *   'procedure_codes'    => string[] (ICD-9 CM[])
     *   'los'                => int (length of stay, for inpatient)
     *   'tgl_masuk'          => string (Y-m-d)
     *   'tgl_keluar'         => string (Y-m-d)
     * }
     * @return array { cbg_code, cbg_tarif, severity_level, success, error }
     */
    public function runGrouper(array $input): array
    {
        $engineType = $this->getEngineType();

        if ($engineType === 'JAR') {
            return $this->runJarGrouper($input);
        }

        return $this->runApiGrouper($input);
    }

    /**
     * Parse grouper result string (JAR stdout) into structured data.
     */
    public function parseResult(string $rawOutput): array
    {
        // JAR output format (example):
        // "N-1-13-I-0-0|850000|1|SUCCESS"
        // Columns: cbg_code | tarif | severity | status

        $parts = explode('|', trim($rawOutput));

        if (count($parts) < 3 || end($parts) !== 'SUCCESS') {
            return [
                'success'        => false,
                'cbg_code'       => null,
                'cbg_tarif'      => 0,
                'severity_level' => null,
                'error'          => $rawOutput,
            ];
        }

        return [
            'success'        => true,
            'cbg_code'       => $parts[0],
            'cbg_tarif'      => (float) $parts[1],
            'severity_level' => $parts[2],
            'error'          => null,
        ];
    }

    public function testConnection(): array
    {
        $engineType = $this->getEngineType();
        $version    = $this->getGrouperVersion();

        if ($engineType === 'JAR') {
            $jarPath = $this->config?->configuration['jar_path'] ?? storage_path('grouper/inacbgs.jar');
            $exists  = file_exists($jarPath);

            return [
                'success'     => $exists,
                'system'      => 'INACBGS',
                'engine_type' => 'JAR',
                'version'     => $version,
                'jar_path'    => $jarPath,
                'jar_exists'  => $exists,
                'message'     => $exists
                    ? "JAR INA-CBGs v{$version} ditemukan."
                    : "File JAR tidak ditemukan di {$jarPath}. Upload file dari Kemkes.",
            ];
        }

        return [
            'success' => false,
            'system'  => 'INACBGS',
            'message' => 'INA-CBGs API engine — placeholder, belum dikonfigurasi.',
        ];
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Invoke INA-CBGs JAR via proc_open.
     * Actual path and arguments depend on JAR version from Kemkes.
     */
    private function runJarGrouper(array $input): array
    {
        $jarPath = $this->config?->configuration['jar_path'] ?? storage_path('grouper/inacbgs.jar');

        if (! file_exists($jarPath)) {
            return [
                'success'  => false,
                'cbg_code' => null,
                'cbg_tarif' => 0,
                'severity_level' => null,
                'error'    => "File JAR tidak ditemukan: {$jarPath}",
            ];
        }

        // TODO: Build JSON input file, invoke java -jar, parse stdout
        // $inputJson = json_encode($input);
        // $cmd = "java -jar {$jarPath} '{$inputJson}'";
        // $output = shell_exec($cmd);
        // return $this->parseResult($output);

        return [
            'success'        => true,
            'cbg_code'       => 'N-1-13-I-0-0',
            'cbg_tarif'      => 850000,
            'severity_level' => '1',
            'error'          => null,
            '__note'         => 'JAR grouper placeholder — implement proc_open() call',
        ];
    }

    private function runApiGrouper(array $input): array
    {
        // TODO: HTTP call to grouper API endpoint
        return [
            'success'        => true,
            'cbg_code'       => 'N-1-13-I-0-0',
            'cbg_tarif'      => 850000,
            'severity_level' => '1',
            'error'          => null,
            '__note'         => 'API grouper placeholder',
        ];
    }
}
