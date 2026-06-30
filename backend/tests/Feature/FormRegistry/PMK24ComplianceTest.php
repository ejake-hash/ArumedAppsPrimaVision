<?php

namespace Tests\Feature\FormRegistry;

use App\Models\DocumentSignature;
use App\Models\DocumentType;
use App\Models\Patient;
use App\Models\PatientDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PMK 24/2022 Section 9 — DocumentSignature append-only enforcement.
 *
 * Test ini butuh DB driver yang berjalan (auto-skip kalau pdo_sqlite tidak
 * ada). Pure-logic hash/audit tests ada di Tests\Unit\FormRegistry\PMK24HashTest.
 */
class PMK24ComplianceTest extends TestCase
{
    use RefreshDatabase;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite extension tidak tersedia — DB-level test di-skip. Hash test pure-logic tetap jalan di Unit suite.');
        }
    }

    public function test_signature_cannot_be_updated(): void
    {
        $sig = $this->makeSignature();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only|immutable/i');

        $sig->signer_type = 'witness';
        $sig->save();
    }

    public function test_signature_cannot_be_deleted(): void
    {
        $sig = $this->makeSignature();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only|immutable/i');

        $sig->delete();
    }

    private function makeSignature(): DocumentSignature
    {
        // Rantai FK riil (PG menegakkan FK; sqlite tidak — itu sebab test lama
        // memakai UUID yatim). Patient → DocumentType → PatientDocument.
        $patient = new Patient();
        $patient->forceFill(['name' => 'Pasien Uji'])->save();

        $docType = new DocumentType();
        $docType->forceFill([
            'code'           => 'TEST',
            'name'           => 'Dokumen Uji',
            'fill_frequency' => 'ONCE',
        ])->save();

        $doc = new PatientDocument();
        $doc->forceFill([
            'patient_id'       => $patient->id,
            'document_type_id' => $docType->id,
        ])->save();

        return DocumentSignature::create([
            'signature_id'         => 'SIG-TEST-0001',
            'patient_document_id'  => $doc->id,
            'signer_type'          => 'patient',
            'signature_svg'        => '<svg/>',
            'captured_at'          => now(),
            'captured_device_info' => ['ua' => 'phpunit'],
            'audit_log'            => [],
            'integrity_hash'       => str_repeat('a', 64),
        ]);
    }
}
