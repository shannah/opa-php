<?php

declare(strict_types=1);

namespace OPA;

/**
 * Verifies signed OPA archives following the JAR signing convention.
 */
class Verifier
{
    /**
     * Verify a signed OPA archive.
     *
     * @param string $archivePath Path to the .opa file
     * @param \OpenSSLCertificate|string|null $trustedCertificate Optional trusted certificate.
     *   If provided, the signature is verified against this certificate instead of the embedded one.
     * @return VerificationResult
     */
    public static function verify(string $archivePath, \OpenSSLCertificate|string|null $trustedCertificate = null): VerificationResult
    {
        $zip = new \ZipArchive();
        $result = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($result !== true) {
            return VerificationResult::failure("Failed to open archive: error code $result");
        }

        try {
            return self::doVerify($zip, $trustedCertificate);
        } finally {
            $zip->close();
        }
    }

    private static function doVerify(\ZipArchive $zip, \OpenSSLCertificate|string|null $trustedCertificate): VerificationResult
    {
        // Check for signature files
        $sfContent = $zip->getFromName('META-INF/SIGNATURE.SF');
        if ($sfContent === false) {
            return VerificationResult::unsigned();
        }

        // Find the signature block file
        $blockContent = null;
        foreach (['.RSA', '.DSA', '.EC'] as $ext) {
            $content = $zip->getFromName('META-INF/SIGNATURE' . $ext);
            if ($content !== false) {
                $blockContent = $content;
                break;
            }
        }

        if ($blockContent === null) {
            return VerificationResult::failure('Signature file (SIGNATURE.SF) present but no signature block file found');
        }

        // Step 1: Verify the digital signature of SIGNATURE.SF
        $sigVerified = self::verifySignature($sfContent, $blockContent, $trustedCertificate);
        if (!$sigVerified) {
            return VerificationResult::failure('Digital signature verification failed');
        }

        // Step 2: Parse SF and verify manifest digest
        $sfFields = self::parseSfFile($sfContent);
        $manifestContent = $zip->getFromName('META-INF/MANIFEST.MF');
        if ($manifestContent === false) {
            return VerificationResult::failure('Archive missing META-INF/MANIFEST.MF');
        }

        $algorithm = self::detectAlgorithm($sfFields['main']);
        if ($algorithm === null) {
            return VerificationResult::failure('No supported digest algorithm found in SIGNATURE.SF');
        }

        $digestName = str_replace('-', '', $algorithm);
        $manifestDigestField = $algorithm . '-Digest-Manifest';

        if (!isset($sfFields['main'][$manifestDigestField])) {
            return VerificationResult::failure("Missing $manifestDigestField in SIGNATURE.SF");
        }

        $expectedManifestDigest = $sfFields['main'][$manifestDigestField];
        $actualManifestDigest = base64_encode(hash($digestName, $manifestContent, true));

        if ($expectedManifestDigest !== $actualManifestDigest) {
            return VerificationResult::failure('Manifest digest mismatch');
        }

        // Step 3: Verify per-entry section digests in SF match manifest sections
        $manifest = Manifest::parse($manifestContent);
        $digestField = $algorithm . '-Digest';

        foreach ($sfFields['entries'] as $name => $entryFields) {
            if (!isset($entryFields[$digestField])) {
                continue;
            }

            $expectedSectionDigest = $entryFields[$digestField];
            $manifestSection = $manifest->getEntrySection($name);

            if ($manifestSection === null) {
                return VerificationResult::failure("Manifest section missing for entry: $name");
            }

            $sectionText = Manifest::formatSection($name, $manifestSection);
            $actualSectionDigest = base64_encode(hash($digestName, $sectionText, true));

            if ($expectedSectionDigest !== $actualSectionDigest) {
                return VerificationResult::failure("Section digest mismatch for entry: $name");
            }
        }

        // Step 4: Verify actual file content digests match the manifest entry digests
        foreach ($manifest->getEntrySections() as $name => $fields) {
            if (!isset($fields[$digestField])) {
                continue;
            }

            $expectedFileDigest = $fields[$digestField];
            $fileContent = $zip->getFromName($name);

            if ($fileContent === false) {
                return VerificationResult::failure("File missing from archive: $name");
            }

            $actualFileDigest = base64_encode(hash($digestName, $fileContent, true));
            if ($expectedFileDigest !== $actualFileDigest) {
                return VerificationResult::failure("File content digest mismatch for: $name");
            }
        }

        return VerificationResult::success();
    }

    /**
     * Verify the digital signature of SIGNATURE.SF using the block file.
     */
    private static function verifySignature(
        string $sfContent,
        string $blockContent,
        \OpenSSLCertificate|string|null $trustedCertificate,
    ): bool {
        // Parse the block file: certificate PEM + newline + base64 signature
        $parts = self::parseBlockFile($blockContent);
        if ($parts === null) {
            return false;
        }

        [$certPem, $signature] = $parts;

        // Use the trusted certificate if provided, otherwise use the embedded one
        $verifyCert = $trustedCertificate ?? $certPem;

        $publicKey = openssl_pkey_get_public($verifyCert);
        if ($publicKey === false) {
            return false;
        }

        // Detect algorithm from the SF content
        $sfFields = Manifest::parseFields($sfContent);
        $algo = OPENSSL_ALGO_SHA256;
        foreach (['SHA-512', 'SHA-384', 'SHA-256'] as $name) {
            if (isset($sfFields[$name . '-Digest-Manifest'])) {
                $algo = match ($name) {
                    'SHA-256' => OPENSSL_ALGO_SHA256,
                    'SHA-384' => OPENSSL_ALGO_SHA384,
                    'SHA-512' => OPENSSL_ALGO_SHA512,
                };
                break;
            }
        }

        return openssl_verify($sfContent, $signature, $publicKey, $algo) === 1;
    }

    /**
     * Parse the signature block file into certificate PEM and raw signature bytes.
     *
     * @return array{0: string, 1: string}|null [certPem, signatureBytes]
     */
    private static function parseBlockFile(string $content): ?array
    {
        // Format: certificate PEM + newline + base64 signature
        $endCert = '-----END CERTIFICATE-----';
        $pos = strpos($content, $endCert);
        if ($pos === false) {
            return null;
        }

        $certPem = substr($content, 0, $pos + strlen($endCert));
        $b64Signature = trim(substr($content, $pos + strlen($endCert)));
        $signature = base64_decode($b64Signature, true);

        if ($signature === false) {
            return null;
        }

        return [$certPem, $signature];
    }

    /**
     * Parse the SIGNATURE.SF file into main fields and per-entry fields.
     *
     * @return array{main: array<string, string>, entries: array<string, array<string, string>>}
     */
    private static function parseSfFile(string $content): array
    {
        $sections = Manifest::parseSections($content);
        $main = $sections[0]['fields'] ?? [];
        $entries = [];

        for ($i = 1; $i < count($sections); $i++) {
            $fields = $sections[$i]['fields'];
            if (isset($fields['Name'])) {
                $name = $fields['Name'];
                unset($fields['Name']);
                $entries[$name] = $fields;
            }
        }

        return ['main' => $main, 'entries' => $entries];
    }

    /**
     * Detect the digest algorithm from SF main section fields.
     */
    private static function detectAlgorithm(array $fields): ?string
    {
        foreach (['SHA-256', 'SHA-384', 'SHA-512'] as $algo) {
            if (isset($fields[$algo . '-Digest-Manifest'])) {
                return $algo;
            }
        }
        return null;
    }
}
