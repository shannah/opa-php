<?php

declare(strict_types=1);

namespace OPA;

/**
 * Reader for OPA archives.
 */
class OpaReader
{
    private \ZipArchive $zip;
    private Manifest $manifest;

    private function __construct(\ZipArchive $zip, Manifest $manifest)
    {
        $this->zip = $zip;
        $this->manifest = $manifest;
    }

    /**
     * Open an OPA archive for reading.
     */
    public static function open(string $path): self
    {
        $zip = new \ZipArchive();
        $result = $zip->open($path, \ZipArchive::RDONLY);

        if ($result !== true) {
            throw new \RuntimeException("Failed to open archive: $path (error code $result)");
        }

        // Validate path safety on all entries
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            OpaArchive::validatePath($entryName);
        }

        $manifestContent = $zip->getFromName('META-INF/MANIFEST.MF');
        if ($manifestContent === false) {
            $zip->close();
            throw new \RuntimeException("Archive missing required META-INF/MANIFEST.MF");
        }

        $manifest = Manifest::parse($manifestContent);

        return new self($zip, $manifest);
    }

    public function getManifest(): Manifest
    {
        return $this->manifest;
    }

    public function getPromptContent(): string
    {
        $content = $this->zip->getFromName($this->manifest->getPromptFile());
        if ($content === false) {
            throw new \RuntimeException("Prompt file not found: " . $this->manifest->getPromptFile());
        }
        return $content;
    }

    public function hasSessionHistory(): bool
    {
        $sessionFile = $this->manifest->getSessionFile() ?? 'session/history.json';
        return $this->zip->locateName($sessionFile) !== false;
    }

    public function getSessionHistory(): ?SessionHistory
    {
        $sessionFile = $this->manifest->getSessionFile() ?? 'session/history.json';
        $content = $this->zip->getFromName($sessionFile);

        if ($content === false) {
            return null;
        }

        return SessionHistory::fromJson($content);
    }

    public function hasDataIndex(): bool
    {
        return $this->zip->locateName('data/INDEX.json') !== false;
    }

    public function getDataIndex(): ?DataIndex
    {
        $content = $this->zip->getFromName('data/INDEX.json');
        if ($content === false) {
            return null;
        }
        return DataIndex::fromJson($content);
    }

    /**
     * Read the contents of a file within the archive.
     */
    public function getFileContent(string $archivePath): ?string
    {
        OpaArchive::validatePath($archivePath);
        $content = $this->zip->getFromName($archivePath);
        return $content === false ? null : $content;
    }

    /**
     * List all entry paths in the archive.
     *
     * @return string[]
     */
    public function listEntries(): array
    {
        $entries = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $entries[] = $this->zip->getNameIndex($i);
        }
        return $entries;
    }

    /**
     * Extract the archive to a directory with path safety validation.
     */
    public function extractTo(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new \RuntimeException("Failed to create extraction directory: $directory");
        }

        $this->zip->extractTo($directory);
    }

    public function close(): void
    {
        $this->zip->close();
    }
}
