<?php

declare(strict_types=1);

namespace OPA;

/**
 * Represents the META-INF/MANIFEST.MF file in an OPA archive.
 *
 * Follows the JAR manifest format: Name: Value pairs, 72-byte line limit.
 * Supports per-entry sections for signing.
 */
class Manifest
{
    public const OPA_VERSION = '0.1';
    public const MANIFEST_VERSION = '1.0';

    private string $promptFile = 'prompt.md';
    private ?string $createdBy = null;
    private ?string $createdAt = null;
    private ?string $title = null;
    private ?string $description = null;
    private ?string $agentHint = null;
    private ?string $sessionFile = null;
    private string $dataRoot = 'data/';
    private string $executionMode = 'interactive';
    private ?string $schemaExtensions = null;

    /**
     * Per-entry sections: archive path => array of field name => value.
     * Used for signing (e.g., SHA-256-Digest per entry).
     *
     * @var array<string, array<string, string>>
     */
    private array $entrySections = [];

    public function getPromptFile(): string
    {
        return $this->promptFile;
    }

    public function setPromptFile(string $promptFile): self
    {
        $this->promptFile = $promptFile;
        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(string $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function setCreatedAt(string $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getAgentHint(): ?string
    {
        return $this->agentHint;
    }

    public function setAgentHint(string $agentHint): self
    {
        $this->agentHint = $agentHint;
        return $this;
    }

    public function getSessionFile(): ?string
    {
        return $this->sessionFile;
    }

    public function setSessionFile(string $sessionFile): self
    {
        $this->sessionFile = $sessionFile;
        return $this;
    }

    public function getDataRoot(): string
    {
        return $this->dataRoot;
    }

    public function setDataRoot(string $dataRoot): self
    {
        $this->dataRoot = $dataRoot;
        return $this;
    }

    public function getExecutionMode(): string
    {
        return $this->executionMode;
    }

    public function setExecutionMode(string $executionMode): self
    {
        if (!in_array($executionMode, ['interactive', 'batch', 'autonomous'], true)) {
            throw new \InvalidArgumentException(
                "Execution mode must be one of: interactive, batch, autonomous. Got: $executionMode"
            );
        }
        $this->executionMode = $executionMode;
        return $this;
    }

    public function getSchemaExtensions(): ?string
    {
        return $this->schemaExtensions;
    }

    public function setSchemaExtensions(string $schemaExtensions): self
    {
        $this->schemaExtensions = $schemaExtensions;
        return $this;
    }

    /**
     * Set a per-entry section (used by signing to store digests).
     *
     * @param string $name Archive entry path (e.g. "prompt.md")
     * @param array<string, string> $fields Field name => value pairs
     */
    public function setEntrySection(string $name, array $fields): self
    {
        $this->entrySections[$name] = $fields;
        return $this;
    }

    /**
     * Get a per-entry section.
     *
     * @return array<string, string>|null
     */
    public function getEntrySection(string $name): ?array
    {
        return $this->entrySections[$name] ?? null;
    }

    /**
     * Get all per-entry sections.
     *
     * @return array<string, array<string, string>>
     */
    public function getEntrySections(): array
    {
        return $this->entrySections;
    }

    /**
     * Serialize the manifest to the JAR manifest format string.
     */
    public function toString(): string
    {
        $lines = [];
        $lines[] = self::formatLine('Manifest-Version', self::MANIFEST_VERSION);
        $lines[] = self::formatLine('OPA-Version', self::OPA_VERSION);
        $lines[] = self::formatLine('Prompt-File', $this->promptFile);

        if ($this->title !== null) {
            $lines[] = self::formatLine('Title', $this->title);
        }
        if ($this->description !== null) {
            $lines[] = self::formatLine('Description', $this->description);
        }
        if ($this->createdBy !== null) {
            $lines[] = self::formatLine('Created-By', $this->createdBy);
        }
        if ($this->createdAt !== null) {
            $lines[] = self::formatLine('Created-At', $this->createdAt);
        }
        if ($this->agentHint !== null) {
            $lines[] = self::formatLine('Agent-Hint', $this->agentHint);
        }
        if ($this->executionMode !== 'interactive') {
            $lines[] = self::formatLine('Execution-Mode', $this->executionMode);
        }
        if ($this->sessionFile !== null) {
            $lines[] = self::formatLine('Session-File', $this->sessionFile);
        }
        if ($this->dataRoot !== 'data/') {
            $lines[] = self::formatLine('Data-Root', $this->dataRoot);
        }
        if ($this->schemaExtensions !== null) {
            $lines[] = self::formatLine('Schema-Extensions', $this->schemaExtensions);
        }

        $result = implode("\r\n", $lines) . "\r\n";

        // Append per-entry sections (separated by blank lines)
        foreach ($this->entrySections as $name => $fields) {
            $result .= "\r\n";
            $result .= self::formatLine('Name', $name) . "\r\n";
            foreach ($fields as $fieldName => $fieldValue) {
                $result .= self::formatLine($fieldName, $fieldValue) . "\r\n";
            }
        }

        return $result;
    }

    /**
     * Parse a manifest from a JAR manifest format string.
     */
    public static function parse(string $content): self
    {
        $manifest = new self();
        $sections = self::parseSections($content);

        // Main section
        if (!empty($sections)) {
            $mainFields = $sections[0]['fields'];

            if (isset($mainFields['Prompt-File'])) {
                $manifest->promptFile = $mainFields['Prompt-File'];
            }
            if (isset($mainFields['Created-By'])) {
                $manifest->createdBy = $mainFields['Created-By'];
            }
            if (isset($mainFields['Created-At'])) {
                $manifest->createdAt = $mainFields['Created-At'];
            }
            if (isset($mainFields['Title'])) {
                $manifest->title = $mainFields['Title'];
            }
            if (isset($mainFields['Description'])) {
                $manifest->description = $mainFields['Description'];
            }
            if (isset($mainFields['Agent-Hint'])) {
                $manifest->agentHint = $mainFields['Agent-Hint'];
            }
            if (isset($mainFields['Session-File'])) {
                $manifest->sessionFile = $mainFields['Session-File'];
            }
            if (isset($mainFields['Data-Root'])) {
                $manifest->dataRoot = $mainFields['Data-Root'];
            }
            if (isset($mainFields['Execution-Mode'])) {
                $manifest->executionMode = $mainFields['Execution-Mode'];
            }
            if (isset($mainFields['Schema-Extensions'])) {
                $manifest->schemaExtensions = $mainFields['Schema-Extensions'];
            }
        }

        // Named entry sections
        for ($i = 1; $i < count($sections); $i++) {
            $section = $sections[$i];
            if (isset($section['fields']['Name'])) {
                $name = $section['fields']['Name'];
                $fields = $section['fields'];
                unset($fields['Name']);
                $manifest->entrySections[$name] = $fields;
            }
        }

        return $manifest;
    }

    /**
     * Format a single manifest line, wrapping at 72 bytes per the JAR spec.
     */
    public static function formatLine(string $name, string $value): string
    {
        $line = "$name: $value";
        if (strlen($line) <= 72) {
            return $line;
        }

        $result = substr($line, 0, 72);
        $remaining = substr($line, 72);

        while ($remaining !== '') {
            // Continuation lines start with a single space, leaving 71 chars for content
            $chunk = substr($remaining, 0, 71);
            $result .= "\r\n " . $chunk;
            $remaining = substr($remaining, 71);
        }

        return $result;
    }

    /**
     * Parse JAR manifest into sections. Each section is an array with 'fields'.
     * The first section is the main section; subsequent sections are named entry sections.
     *
     * @return array<int, array{fields: array<string, string>}>
     */
    public static function parseSections(string $content): array
    {
        $sections = [];
        // Split on blank lines (handling both \r\n and \n)
        $rawSections = preg_split('/\r?\n\r?\n/', $content);

        foreach ($rawSections as $rawSection) {
            $rawSection = trim($rawSection);
            if ($rawSection === '') {
                continue;
            }
            $sections[] = ['fields' => self::parseFields($rawSection)];
        }

        return $sections;
    }

    /**
     * Parse JAR manifest fields from a single section, handling continuation lines.
     *
     * @return array<string, string>
     */
    public static function parseFields(string $content): array
    {
        $fields = [];
        $lines = preg_split('/\r?\n/', $content);
        $currentName = null;
        $currentValue = null;

        foreach ($lines as $line) {
            if ($line === '') {
                break;
            }

            if (isset($line[0]) && $line[0] === ' ') {
                // Continuation line
                if ($currentName !== null) {
                    $currentValue .= substr($line, 1);
                }
                continue;
            }

            // Save previous field
            if ($currentName !== null) {
                $fields[$currentName] = $currentValue;
            }

            $colonPos = strpos($line, ': ');
            if ($colonPos !== false) {
                $currentName = substr($line, 0, $colonPos);
                $currentValue = substr($line, $colonPos + 2);
            }
        }

        // Save last field
        if ($currentName !== null && !isset($fields[$currentName])) {
            $fields[$currentName] = $currentValue;
        }

        return $fields;
    }

    /**
     * Serialize a single section (for digest computation).
     *
     * @param string $name Entry name
     * @param array<string, string> $fields
     */
    public static function formatSection(string $name, array $fields): string
    {
        $result = self::formatLine('Name', $name) . "\r\n";
        foreach ($fields as $fieldName => $fieldValue) {
            $result .= self::formatLine($fieldName, $fieldValue) . "\r\n";
        }
        return $result;
    }
}
