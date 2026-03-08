<?php

declare(strict_types=1);

namespace OPA;

/**
 * Builder for creating OPA archives.
 */
class OpaArchive
{
    private Manifest $manifest;
    private string $promptContent;
    private ?SessionHistory $sessionHistory = null;
    private ?DataIndex $dataIndex = null;

    /** @var array<string, string> Archive path => file content */
    private array $dataFiles = [];

    /** @var array<string, string> Archive path => local filesystem path */
    private array $dataFilePaths = [];

    /** @var array<string, string> Archive path => file content for session attachments */
    private array $sessionAttachments = [];

    /** @var array<string, string> Archive path => local filesystem path for session attachments */
    private array $sessionAttachmentPaths = [];

    public function __construct(string $promptContent)
    {
        $this->manifest = new Manifest();
        $this->promptContent = $promptContent;
    }

    public function getManifest(): Manifest
    {
        return $this->manifest;
    }

    public function setSessionHistory(SessionHistory $history): self
    {
        $this->sessionHistory = $history;
        $this->manifest->setSessionFile('session/history.json');
        return $this;
    }

    public function setDataIndex(DataIndex $index): self
    {
        $this->dataIndex = $index;
        return $this;
    }

    /**
     * Add a data file from a string content.
     *
     * @param string $archivePath Path within the archive (e.g. "data/report.csv")
     * @param string $content File content
     */
    public function addDataFromString(string $archivePath, string $content): self
    {
        self::validatePath($archivePath);
        $this->dataFiles[$archivePath] = $content;
        return $this;
    }

    /**
     * Add a data file from a local filesystem path.
     *
     * @param string $archivePath Path within the archive (e.g. "data/report.csv")
     * @param string $localPath Local filesystem path
     */
    public function addDataFromFile(string $archivePath, string $localPath): self
    {
        self::validatePath($archivePath);
        if (!file_exists($localPath)) {
            throw new \InvalidArgumentException("File not found: $localPath");
        }
        $this->dataFilePaths[$archivePath] = $localPath;
        return $this;
    }

    /**
     * Add a session attachment from string content.
     */
    public function addSessionAttachmentFromString(string $filename, string $content): self
    {
        $archivePath = "session/attachments/$filename";
        self::validatePath($archivePath);
        $this->sessionAttachments[$archivePath] = $content;
        return $this;
    }

    /**
     * Add a session attachment from a local file.
     */
    public function addSessionAttachmentFromFile(string $filename, string $localPath): self
    {
        $archivePath = "session/attachments/$filename";
        self::validatePath($archivePath);
        if (!file_exists($localPath)) {
            throw new \InvalidArgumentException("File not found: $localPath");
        }
        $this->sessionAttachmentPaths[$archivePath] = $localPath;
        return $this;
    }

    /**
     * Save the OPA archive to a file, optionally signing it.
     *
     * @param Signer|null $signer If provided, the archive will be signed after creation.
     */
    public function save(string $outputPath, ?Signer $signer = null): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new \RuntimeException("Failed to create ZIP archive: error code $result");
        }

        // META-INF/MANIFEST.MF (required)
        $zip->addFromString('META-INF/MANIFEST.MF', $this->manifest->toString());

        // prompt.md (required)
        $zip->addFromString($this->manifest->getPromptFile(), $this->promptContent);

        // Session history (optional)
        if ($this->sessionHistory !== null) {
            $zip->addFromString(
                $this->manifest->getSessionFile() ?? 'session/history.json',
                $this->sessionHistory->toJson(),
            );
        }

        // Session attachments
        foreach ($this->sessionAttachments as $path => $content) {
            $zip->addFromString($path, $content);
        }
        foreach ($this->sessionAttachmentPaths as $archivePath => $localPath) {
            $zip->addFile($localPath, $archivePath);
        }

        // Data files
        foreach ($this->dataFiles as $path => $content) {
            $zip->addFromString($path, $content);
        }
        foreach ($this->dataFilePaths as $archivePath => $localPath) {
            $zip->addFile($localPath, $archivePath);
        }

        // Data index (optional)
        if ($this->dataIndex !== null) {
            $zip->addFromString('data/INDEX.json', $this->dataIndex->toJson());
        }

        $zip->close();

        if ($signer !== null) {
            $signer->sign($outputPath);
        }
    }

    /**
     * Validate an archive path per §4.3: no ".." components, no absolute paths.
     */
    public static function validatePath(string $path): void
    {
        if (str_starts_with($path, '/')) {
            throw new \InvalidArgumentException("Archive paths must not be absolute: $path");
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException("Archive paths must not contain '..': $path");
            }
        }
    }
}
