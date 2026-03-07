<?php

declare(strict_types=1);

namespace OPA\Tests;

use OPA\OpaArchive;
use OPA\OpaReader;
use OPA\Signer;
use OPA\Verifier;
use OPA\VerificationResult;
use PHPUnit\Framework\TestCase;

class SigningTest extends TestCase
{
    private string $tempDir;
    private string $privateKeyPem;
    private string $certificatePem;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/opa-sign-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        // Generate a self-signed certificate and private key for testing
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $keyPem = '';
        openssl_pkey_export($key, $keyPem);
        $this->privateKeyPem = $keyPem;

        $csr = openssl_csr_new(['CN' => 'OPA Test'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 365);
        $certPem = '';
        openssl_x509_export($cert, $certPem);
        $this->certificatePem = $certPem;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testSignAndVerify(): void
    {
        $outputPath = $this->tempDir . '/signed.opa';

        $archive = new OpaArchive('Test prompt for signing.');
        $archive->getManifest()->setTitle('Signed Archive');
        $archive->addDataFromString('data/test.txt', 'Hello, signed world!');

        $signer = new Signer($this->privateKeyPem, $this->certificatePem);
        $archive->save($outputPath, $signer);

        // Verify the archive is marked as signed
        $reader = OpaReader::open($outputPath);
        $this->assertTrue($reader->isSigned());
        $this->assertEquals('Test prompt for signing.', $reader->getPromptContent());
        $reader->close();

        // Verify signature
        $result = Verifier::verify($outputPath);
        $this->assertTrue($result->isSigned());
        $this->assertTrue($result->isValid(), 'Verification failed: ' . ($result->getError() ?? ''));
    }

    public function testVerifyWithTrustedCertificate(): void
    {
        $outputPath = $this->tempDir . '/signed-trusted.opa';

        $archive = new OpaArchive('Trusted test.');
        $signer = new Signer($this->privateKeyPem, $this->certificatePem);
        $archive->save($outputPath, $signer);

        $result = Verifier::verify($outputPath, $this->certificatePem);
        $this->assertTrue($result->isValid(), 'Verification failed: ' . ($result->getError() ?? ''));
    }

    public function testVerifyUnsignedArchive(): void
    {
        $outputPath = $this->tempDir . '/unsigned.opa';

        $archive = new OpaArchive('Unsigned test.');
        $archive->save($outputPath);

        $result = Verifier::verify($outputPath);
        $this->assertFalse($result->isSigned());
        $this->assertFalse($result->isValid());
    }

    public function testTamperedPromptFailsVerification(): void
    {
        $outputPath = $this->tempDir . '/tampered.opa';

        $archive = new OpaArchive('Original prompt.');
        $signer = new Signer($this->privateKeyPem, $this->certificatePem);
        $archive->save($outputPath, $signer);

        // Tamper with the prompt content
        $zip = new \ZipArchive();
        $zip->open($outputPath);
        $zip->addFromString('prompt.md', 'Tampered prompt!');
        $zip->close();

        $result = Verifier::verify($outputPath);
        $this->assertTrue($result->isSigned());
        $this->assertFalse($result->isValid());
    }

    public function testTamperedManifestFailsVerification(): void
    {
        $outputPath = $this->tempDir . '/tampered-manifest.opa';

        $archive = new OpaArchive('Test prompt.');
        $signer = new Signer($this->privateKeyPem, $this->certificatePem);
        $archive->save($outputPath, $signer);

        // Tamper with the manifest
        $zip = new \ZipArchive();
        $zip->open($outputPath);
        $manifestContent = $zip->getFromName('META-INF/MANIFEST.MF');
        $zip->addFromString('META-INF/MANIFEST.MF', $manifestContent . "Title: Injected\r\n");
        $zip->close();

        $result = Verifier::verify($outputPath);
        $this->assertTrue($result->isSigned());
        $this->assertFalse($result->isValid());
    }

    public function testSignWithMultipleDataFiles(): void
    {
        $outputPath = $this->tempDir . '/multi-data.opa';

        $archive = new OpaArchive('Analyze all data files.');
        $archive->addDataFromString('data/a.csv', 'col1,col2');
        $archive->addDataFromString('data/b.csv', 'col3,col4');
        $archive->addDataFromString('data/nested/c.txt', 'nested content');

        $signer = new Signer($this->privateKeyPem, $this->certificatePem);
        $archive->save($outputPath, $signer);

        $result = Verifier::verify($outputPath);
        $this->assertTrue($result->isValid(), 'Verification failed: ' . ($result->getError() ?? ''));

        // Check manifest has digest sections for all entries
        $reader = OpaReader::open($outputPath);
        $manifest = $reader->getManifest();
        $this->assertNotNull($manifest->getEntrySection('prompt.md'));
        $this->assertNotNull($manifest->getEntrySection('data/a.csv'));
        $this->assertNotNull($manifest->getEntrySection('data/b.csv'));
        $this->assertNotNull($manifest->getEntrySection('data/nested/c.txt'));
        $reader->close();
    }

    public function testSignerRejectsInvalidAlgorithm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Algorithm must be one of');

        new Signer($this->privateKeyPem, $this->certificatePem, 'SHA-1');
    }

    public function testSignerRejectsMd5(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Signer($this->privateKeyPem, $this->certificatePem, 'MD5');
    }

    public function testVerificationResultStates(): void
    {
        $success = VerificationResult::success();
        $this->assertTrue($success->isSigned());
        $this->assertTrue($success->isValid());
        $this->assertNull($success->getError());

        $failure = VerificationResult::failure('bad digest');
        $this->assertTrue($failure->isSigned());
        $this->assertFalse($failure->isValid());
        $this->assertEquals('bad digest', $failure->getError());

        $unsigned = VerificationResult::unsigned();
        $this->assertFalse($unsigned->isSigned());
        $this->assertFalse($unsigned->isValid());
    }

    public function testSignatureFilesPresent(): void
    {
        $outputPath = $this->tempDir . '/check-files.opa';

        $archive = new OpaArchive('Check files.');
        $signer = new Signer($this->privateKeyPem, $this->certificatePem);
        $archive->save($outputPath, $signer);

        $reader = OpaReader::open($outputPath);
        $entries = $reader->listEntries();

        $this->assertContains('META-INF/SIGNATURE.SF', $entries);
        $this->assertContains('META-INF/SIGNATURE.RSA', $entries);

        // Verify SF content structure
        $sfContent = $reader->getFileContent('META-INF/SIGNATURE.SF');
        $this->assertStringContainsString('Signature-Version: 1.0', $sfContent);
        $this->assertStringContainsString('SHA-256-Digest-Manifest:', $sfContent);

        $reader->close();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
