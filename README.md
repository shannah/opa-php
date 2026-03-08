# opa-php

A minimal PHP library for generating, reading, signing, and verifying [Open Prompt Archive (OPA)](https://github.com/shannah/opa-spec) files.

OPA is a portable, ZIP-based archive format for packaging AI agent prompts together with session history, data assets, and execution metadata.

## Requirements

- PHP 8.1+
- Extensions: `zip`, `json`, `openssl`

## Installation

```bash
composer require opa/opa-php
```

## Quick Start

### Creating an archive

```php
use OPA\OpaArchive;

$archive = new OpaArchive('Summarize the attached report.');
$archive->getManifest()
    ->setTitle('Report Summary')
    ->setExecutionMode('batch');

$archive->addDataFromString('data/report.csv', $csvContent);
$archive->save('summary-task.opa');
```

### Reading an archive

```php
use OPA\OpaReader;

$reader = OpaReader::open('summary-task.opa');

echo $reader->getManifest()->getTitle();
echo $reader->getPromptContent();

if ($reader->hasSessionHistory()) {
    foreach ($reader->getSessionHistory()->getMessages() as $msg) {
        echo "[{$msg->getRole()}] {$msg->getContent()}\n";
    }
}

$reader->close();
```

### Signing and verification

```php
use OPA\OpaArchive;
use OPA\Signer;
use OPA\Verifier;

// Sign on create
$signer = new Signer($privateKeyPem, $certificatePem);
$archive = new OpaArchive('Analyze the data.');
$archive->save('signed.opa', $signer);

// Verify
$result = Verifier::verify('signed.opa');
if ($result->isSigned() && $result->isValid()) {
    echo "Signature verified.\n";
}

// Verify against a specific trusted certificate
$result = Verifier::verify('signed.opa', $trustedCertPem);
```

## API Reference

### OpaArchive (builder)

```php
$archive = new OpaArchive(string $promptContent);
$archive->getManifest(): Manifest;
$archive->setSessionHistory(SessionHistory $history): self;
$archive->setDataIndex(DataIndex $index): self;
$archive->addDataFromString(string $archivePath, string $content): self;
$archive->addDataFromFile(string $archivePath, string $localPath): self;
$archive->addSessionAttachmentFromString(string $filename, string $content): self;
$archive->addSessionAttachmentFromFile(string $filename, string $localPath): self;
$archive->save(string $outputPath, ?Signer $signer = null): void;
```

### OpaReader

```php
$reader = OpaReader::open(string $path): OpaReader;
$reader->getManifest(): Manifest;
$reader->getPromptContent(): string;
$reader->hasSessionHistory(): bool;
$reader->getSessionHistory(): ?SessionHistory;
$reader->hasDataIndex(): bool;
$reader->getDataIndex(): ?DataIndex;
$reader->getFileContent(string $archivePath): ?string;
$reader->listEntries(): array;
$reader->isSigned(): bool;
$reader->extractTo(string $directory): void;
$reader->close(): void;
```

### Manifest

```php
$manifest->setTitle(string $title): self;
$manifest->setDescription(string $description): self;
$manifest->setCreatedBy(string $createdBy): self;
$manifest->setCreatedAt(string $createdAt): self;
$manifest->setAgentHint(string $agentHint): self;
$manifest->setExecutionMode(string $mode): self;       // interactive, batch, autonomous
$manifest->setPromptFile(string $promptFile): self;
$manifest->setSessionFile(string $sessionFile): self;
$manifest->setDataRoot(string $dataRoot): self;
$manifest->setSchemaExtensions(string $extensions): self;
```

### SessionHistory & Message

```php
$history = new SessionHistory(
    sessionId: 'uuid-here',
    createdAt: '2026-03-01T10:00:00Z',
);
$history->addMessage(new Message(
    role: 'user',           // user, assistant, system, tool
    content: 'Hello',       // string or array of content blocks
    id: '1',
    timestamp: '2026-03-01T10:00:00Z',
));
```

### Signing

```php
// Signer supports SHA-256 (default), SHA-384, SHA-512
// MD5 and SHA-1 are rejected per spec
$signer = new Signer($privateKeyPem, $certificatePem, 'SHA-256');

// Sign an existing archive in place
$signer->sign('archive.opa');

// Or sign during creation
$archive->save('archive.opa', $signer);
```

### Verification

```php
$result = Verifier::verify('archive.opa');
$result->isSigned(): bool;    // whether signature files are present
$result->isValid(): bool;     // whether all verification checks passed
$result->getError(): ?string; // error message if verification failed
```

The verifier checks the full signature chain:
1. Digital signature on `SIGNATURE.SF`
2. Manifest digest matches actual `MANIFEST.MF`
3. Per-entry section digests in SF match manifest sections
4. File content digests in manifest match actual archive contents

## Archive Structure

```
archive.opa
├── META-INF/
│   ├── MANIFEST.MF            # Required manifest
│   ├── SIGNATURE.SF           # Signature file (if signed)
│   └── SIGNATURE.RSA          # Signature block (if signed)
├── prompt.md                  # Required prompt file
├── session/
│   ├── history.json           # Session history (optional)
│   └── attachments/           # Session attachments (optional)
└── data/
    ├── INDEX.json             # Data index (optional)
    └── ...                    # Data assets
```

## Examples

See [`examples/generate-hn-pirate-summary.php`](examples/generate-hn-pirate-summary.php) — fetches the Hacker News RSS feed, bundles it into a signed OPA archive with a prompt asking an AI to summarize articles in pirate speak and output styled HTML.

```bash
php examples/generate-hn-pirate-summary.php
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Specification

This library implements the [OPA specification](https://github.com/shannah/opa-spec).

## License

MIT
