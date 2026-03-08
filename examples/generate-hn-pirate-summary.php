#!/usr/bin/env php
<?php

/**
 * Generates a signed OPA archive containing the Hacker News RSS feed
 * and a prompt asking an AI to summarize articles in pirate speak,
 * highlighting the most AI-relevant article, outputting as HTML.
 */

require __DIR__ . '/../vendor/autoload.php';

use OPA\OpaArchive;
use OPA\Signer;
use OPA\DataAsset;
use OPA\DataIndex;

// --- Fetch Hacker News RSS feed ---
echo "Fetching Hacker News RSS feed...\n";
$rss = file_get_contents('https://news.ycombinator.com/rss');
if ($rss === false) {
    fwrite(STDERR, "Failed to fetch Hacker News RSS feed.\n");
    exit(1);
}
echo "Fetched " . strlen($rss) . " bytes.\n";

// --- Generate a self-signed key pair for signing ---
echo "Generating signing key...\n";
$key = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
$keyPem = '';
openssl_pkey_export($key, $keyPem);

$csr = openssl_csr_new(['CN' => 'HN Pirate Summary Generator'], $key);
$cert = openssl_csr_sign($csr, null, $key, 365);
$certPem = '';
openssl_x509_export($cert, $certPem);

// --- Build the prompt ---
$prompt = <<<'MARKDOWN'
# Hacker News Pirate Summary

You are a pirate captain who is also a tech enthusiast. Your task:

1. Read the Hacker News RSS feed at `data/hn-feed.xml`
2. Summarize each article in 1-2 sentences, written entirely in pirate speak
3. Identify the ONE article that would be of most interest to someone passionate about AI
4. Highlight that article with a special "Captain's AI Pick" section

## Output Format

Output your report as a complete, self-contained HTML document. Use this structure:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Pirate's Hacker News Digest</title>
    <!-- Include inline CSS for styling -->
</head>
<body>
    <h1>🏴‍☠️ The Pirate's Hacker News Digest</h1>

    <section class="ai-pick">
        <h2>🦜 Captain's AI Pick</h2>
        <!-- The AI-relevant article highlighted here with title, link, and pirate summary -->
    </section>

    <section class="articles">
        <h2>⚓ All Articles from the Feed</h2>
        <!-- Each article as a card with title, link, and pirate summary -->
    </section>

    <footer>
        <p>Generated from Hacker News RSS · {{timestamp}}</p>
    </footer>
</body>
</html>
```

Make it look good with clean CSS. Use a dark nautical theme.
MARKDOWN;

// --- Build the archive ---
echo "Building OPA archive...\n";

$archive = new OpaArchive($prompt);
$archive->getManifest()
    ->setTitle('Pirate HN Summary')
    ->setDescription('Summarize Hacker News in pirate speak, highlight AI article, output HTML')
    ->setCreatedBy('opa-php/examples')
    ->setCreatedAt(gmdate('Y-m-d\TH:i:s\Z'))
    ->setExecutionMode('batch');

$archive->addDataFromString('data/hn-feed.xml', $rss);

$index = new DataIndex();
$index->addAsset(new DataAsset(
    path: 'data/hn-feed.xml',
    description: 'Hacker News front page RSS feed',
    contentType: 'application/rss+xml',
));
$archive->setDataIndex($index);

// --- Sign and save ---
$outputFile = __DIR__ . '/hn-pirate-summary.opa';
$signer = new Signer($keyPem, $certPem);
$archive->save($outputFile, $signer);

echo "Signed archive written to: $outputFile\n";

// --- Verify it round-trips ---
$result = \OPA\Verifier::verify($outputFile, $certPem);
echo "Verification: " . ($result->isValid() ? "PASSED" : "FAILED: " . $result->getError()) . "\n";
