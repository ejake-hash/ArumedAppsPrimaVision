<?php

namespace App\Services\Concerns;

use Illuminate\Database\QueryException;

/**
 * Retry pembuatan record yang nomornya digenerate via MAX+1 (PO/GRN/dll).
 * Dua request berbarengan bisa menghasilkan nomor sama → INSERT melempar
 * unique-violation (Postgres SQLSTATE 23505 / MySQL 1062). Closure dipanggil
 * ulang (men-generate nomor baru) hingga $maxAttempts.
 *
 * Closure HARUS men-generate nomor di dalam dirinya (bukan dari variabel luar),
 * agar percobaan berikutnya memakai nomor baru.
 */
trait RetriesUniqueNumber
{
    protected function createWithRetry(callable $create, int $maxAttempts = 5)
    {
        $attempt = 0;
        while (true) {
            try {
                return $create();
            } catch (QueryException $e) {
                $attempt++;
                if (! $this->isUniqueViolation($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }
                // loop ulang: closure men-generate nomor baru (MAX+1 terbaru).
            }
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // Postgres: 23505. MySQL: 1062 (driver code di $e->errorInfo[1]).
        $sqlState = $e->getCode();
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23505' || $driverCode === 1062;
    }
}
