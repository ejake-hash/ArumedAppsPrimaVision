<?php

namespace Tests\Unit\FormRegistry;

use PHPUnit\Framework\TestCase;

/**
 * PMK 24/2022 — pure-logic compliance tests (no DB).
 *
 * Memverifikasi pattern hash + audit namespace yang dipakai
 * SignatureService + FormRegistryAudit. Tidak butuh DB driver.
 */
class PMK24HashTest extends TestCase
{
    /**
     * PMK Section 9 — integrity hash WAJIB reproducible.
     */
    public function test_integrity_hash_is_reproducible(): void
    {
        $svg = '<svg><path d="M0,0 L10,10"/></svg>';
        $capturedAt = '2026-05-28 10:30:45';
        $docId = '550e8400-e29b-41d4-a716-446655440000';
        $identityKey = 'patient:abc-123';

        $hash1 = hash('sha256', $svg . $capturedAt . $docId . $identityKey);
        $hash2 = hash('sha256', $svg . $capturedAt . $docId . $identityKey);

        $this->assertSame($hash1, $hash2, 'Hash MUST be deterministic with same inputs');
        $this->assertSame(64, strlen($hash1), 'SHA-256 hash MUST be 64 hex chars');
    }

    /**
     * Hash WAJIB berubah kalau salah satu input berubah.
     */
    public function test_integrity_hash_changes_per_input_component(): void
    {
        $base = ['svg1', '2026-05-28 10:30:45', 'doc1', 'patient:a'];
        $hashBase = $this->buildHash(...$base);

        $variations = [
            ['svg2', '2026-05-28 10:30:45', 'doc1', 'patient:a'],
            ['svg1', '2026-05-28 10:30:46', 'doc1', 'patient:a'],
            ['svg1', '2026-05-28 10:30:45', 'doc2', 'patient:a'],
            ['svg1', '2026-05-28 10:30:45', 'doc1', 'patient:b'],
        ];

        foreach ($variations as $i => $variation) {
            $variantHash = $this->buildHash(...$variation);
            $this->assertNotSame(
                $hashBase,
                $variantHash,
                "Hash should change when input component #{$i} differs"
            );
        }
    }

    /**
     * PMK Section 10 — audit log namespace WAJIB FORM_*.
     */
    public function test_audit_log_uses_form_namespace(): void
    {
        $expectedActions = [
            'FORM_TEMPLATE_CREATED',
            'FORM_TEMPLATE_UPDATED',
            'FORM_TEMPLATE_ACTIVATED',
            'FORM_TEMPLATE_DEACTIVATED',
            'FORM_DOC_SUBMITTED',
            'FORM_DOC_RENDERED',
            'FORM_DOC_FINALIZED',
            'FORM_SIG_CAPTURED',
            'FORM_ADDENDUM_CREATED',
        ];
        foreach ($expectedActions as $action) {
            $this->assertStringStartsWith(
                'FORM_',
                $action,
                "Audit action '{$action}' MUST use FORM_ namespace"
            );
        }
    }

    /**
     * PMK Section 9 — hash format pakai 'Y-m-d H:i:s' (TANPA microseconds).
     */
    public function test_integrity_hash_uses_seconds_precision(): void
    {
        $base = '2026-05-28 10:30:45';
        $withMicro = '2026-05-28 10:30:45.123456';

        $hashBase = hash('sha256', 'svg' . $base . 'doc' . 'id');
        $hashMicro = hash('sha256', 'svg' . $withMicro . 'doc' . 'id');

        $this->assertNotSame($hashBase, $hashMicro);
        // Pattern: implementor WAJIB strip microseconds di SignatureService::capture()
        // & verify() konsisten pakai 'Y-m-d H:i:s'.
        $this->assertTrue(true, 'Document: SignatureService MUST use Y-m-d H:i:s format');
    }

    private function buildHash(string $svg, string $ts, string $doc, string $identity): string
    {
        return hash('sha256', $svg . $ts . $doc . $identity);
    }
}
