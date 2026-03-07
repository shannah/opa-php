<?php

declare(strict_types=1);

namespace OPA;

/**
 * Represents the META-INF/MANIFEST.MF file in an OPA archive.
 *
 * Follows the JAR manifest format: Name: Value pairs, 72-byte line limit.
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

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Parse a manifest from a JAR manifest format string.
     */
    public static function parse(string $content): self
    {
        $manifest = new self();
        $fields = self::parseFields($content);

        if (isset($fields['Prompt-File'])) {
            $manifest->promptFile = $fields['Prompt-File'];
        }
        if (isset($fields['Created-By'])) {
            $manifest->createdBy = $fields['Created-By'];
        }
        if (isset($fields['Created-At'])) {
            $manifest->createdAt = $fields['Created-At'];
        }
        if (isset($fields['Title'])) {
            $manifest->title = $fields['Title'];
        }
        if (isset($fields['Description'])) {
            $manifest->description = $fields['Description'];
        }
        if (isset($fields['Agent-Hint'])) {
            $manifest->agentHint = $fields['Agent-Hint'];
        }
        if (isset($fields['Session-File'])) {
            $manifest->sessionFile = $fields['Session-File'];
        }
        if (isset($fields['Data-Root'])) {
            $manifest->dataRoot = $fields['Data-Root'];
        }
        if (isset($fields['Execution-Mode'])) {
            $manifest->executionMode = $fields['Execution-Mode'];
        }
        if (isset($fields['Schema-Extensions'])) {
            $manifest->schemaExtensions = $fields['Schema-Extensions'];
        }

        return $manifest;
    }

    /**
     * Format a single manifest line, wrapping at 72 bytes per the JAR spec.
     */
    private static function formatLine(string $name, string $value): string
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
     * Parse JAR manifest fields, handling continuation lines.
     *
     * @return array<string, string>
     */
    private static function parseFields(string $content): array
    {
        $fields = [];
        $lines = preg_split('/\r?\n/', $content);
        $currentName = null;
        $currentValue = null;

        foreach ($lines as $line) {
            if ($line === '') {
                // Section break - stop at main section
                if ($currentName !== null) {
                    $fields[$currentName] = $currentValue;
                }
                break;
            }

            if ($line[0] === ' ') {
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
}
