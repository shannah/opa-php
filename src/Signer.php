<?php

declare(strict_types=1);

namespace OPA;

/**
 * Signs OPA archives following the JAR signing convention.
 *
 * Produces META-INF/SIGNATURE.SF and META-INF/SIGNATURE.RSA (or .EC/.DSA).
 */
class Signer
{
    private \OpenSSLAsymmetricKey $privateKey;
    private \OpenSSLCertificate $certificate;
    private string $algorithm;
    private string $digestName;
    private int $opensslAlgo;
    private string $blockFileExtension;

    /**
     * @param \OpenSSLAsymmetricKey|string $privateKey Private key or PEM string
     * @param \OpenSSLCertificate|string $certificate Certificate or PEM string
     * @param string $algorithm Digest algorithm: SHA-256, SHA-384, or SHA-512
     */
    public function __construct(
        \OpenSSLAsymmetricKey|string $privateKey,
        \OpenSSLCertificate|string $certificate,
        string $algorithm = 'SHA-256',
    ) {
        if (is_string($privateKey)) {
            $key = openssl_pkey_get_private($privateKey);
            if ($key === false) {
                throw new \InvalidArgumentException('Invalid private key');
            }
            $this->privateKey = $key;
        } else {
            $this->privateKey = $privateKey;
        }

        if (is_string($certificate)) {
            $cert = openssl_x509_read($certificate);
            if ($cert === false) {
                throw new \InvalidArgumentException('Invalid certificate');
            }
            $this->certificate = $cert;
        } else {
            $this->certificate = $certificate;
        }

        $allowed = ['SHA-256', 'SHA-384', 'SHA-512'];
        if (!in_array($algorithm, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Algorithm must be one of: " . implode(', ', $allowed) . ". Got: $algorithm"
            );
        }
        $this->algorithm = $algorithm;
        $this->digestName = str_replace('-', '', $algorithm);
        $this->opensslAlgo = match ($algorithm) {
            'SHA-256' => OPENSSL_ALGO_SHA256,
            'SHA-384' => OPENSSL_ALGO_SHA384,
            'SHA-512' => OPENSSL_ALGO_SHA512,
        };

        // Determine block file extension from key type
        $keyDetails = openssl_pkey_get_details($this->privateKey);
        $this->blockFileExtension = match ($keyDetails['type'] ?? -1) {
            OPENSSL_KEYTYPE_RSA => '.RSA',
            OPENSSL_KEYTYPE_DSA => '.DSA',
            OPENSSL_KEYTYPE_EC  => '.EC',
            default => '.RSA',
        };
    }

    /**
     * Sign an OPA archive file in place.
     *
     * Reads the archive, adds digest sections to the manifest,
     * generates SIGNATURE.SF and SIGNATURE.RSA/DSA/EC, and writes them back.
     */
    public function sign(string $archivePath): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($archivePath);
        if ($result !== true) {
            throw new \RuntimeException("Failed to open archive: $archivePath");
        }

        // Read existing manifest
        $manifestContent = $zip->getFromName('META-INF/MANIFEST.MF');
        if ($manifestContent === false) {
            $zip->close();
            throw new \RuntimeException('Archive missing META-INF/MANIFEST.MF');
        }

        $manifest = Manifest::parse($manifestContent);

        // Compute SHA digests of each entry's content and add to manifest sections
        $entryNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            // Skip META-INF signature files and directories
            if (str_starts_with($name, 'META-INF/SIGNATURE.') || $name === 'META-INF/MANIFEST.MF') {
                continue;
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            $entryNames[] = $name;
        }

        // Add digest sections to manifest for each entry
        foreach ($entryNames as $name) {
            $content = $zip->getFromName($name);
            if ($content === false) {
                continue;
            }
            $digest = base64_encode(hash($this->digestName, $content, true));
            $manifest->setEntrySection($name, [
                $this->algorithm . '-Digest' => $digest,
            ]);
        }

        // Serialize the updated manifest
        $newManifestContent = $manifest->toString();

        // Build SIGNATURE.SF
        $sfContent = $this->buildSignatureFile($newManifestContent, $manifest);

        // Sign SIGNATURE.SF to produce the block file
        $blockContent = $this->createSignatureBlock($sfContent);

        // Write everything back to the archive
        $zip->addFromString('META-INF/MANIFEST.MF', $newManifestContent);
        $zip->addFromString('META-INF/SIGNATURE.SF', $sfContent);
        $zip->addFromString('META-INF/SIGNATURE' . $this->blockFileExtension, $blockContent);

        $zip->close();
    }

    /**
     * Build the SIGNATURE.SF content.
     */
    private function buildSignatureFile(string $manifestContent, Manifest $manifest): string
    {
        $digestField = $this->algorithm . '-Digest-Manifest';
        $manifestDigest = base64_encode(hash($this->digestName, $manifestContent, true));

        $lines = [];
        $lines[] = Manifest::formatLine('Signature-Version', '1.0');
        $lines[] = Manifest::formatLine($digestField, $manifestDigest);

        $sf = implode("\r\n", $lines) . "\r\n";

        // Add per-entry section digests (digest of each section in the manifest)
        foreach ($manifest->getEntrySections() as $name => $fields) {
            $sectionText = Manifest::formatSection($name, $fields);
            $sectionDigest = base64_encode(hash($this->digestName, $sectionText, true));

            $sf .= "\r\n";
            $sf .= Manifest::formatLine('Name', $name) . "\r\n";
            $sf .= Manifest::formatLine($this->algorithm . '-Digest', $sectionDigest) . "\r\n";
        }

        return $sf;
    }

    /**
     * Create the signature block file content.
     *
     * Contains the signing certificate (PEM) and the digital signature,
     * encoded in a structured binary format.
     */
    private function createSignatureBlock(string $sfContent): string
    {
        $signature = '';
        $result = openssl_sign($sfContent, $signature, $this->privateKey, $this->opensslAlgo);
        if ($result === false) {
            throw new \RuntimeException('Failed to create digital signature: ' . openssl_error_string());
        }

        $certPem = '';
        openssl_x509_export($this->certificate, $certPem);

        // Store as: certificate PEM + newline + base64 signature
        // This allows the verifier to extract both the certificate and signature
        return $certPem . "\n" . base64_encode($signature);
    }
}
