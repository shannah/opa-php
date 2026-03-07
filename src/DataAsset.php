<?php

declare(strict_types=1);

namespace OPA;

class DataAsset
{
    public function __construct(
        private string $path,
        private ?string $description = null,
        private ?string $contentType = null,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = ['path' => $this->path];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->contentType !== null) {
            $data['content_type'] = $this->contentType;
        }

        return $data;
    }

    /**
     * @param array<string, string> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: $data['path'],
            description: $data['description'] ?? null,
            contentType: $data['content_type'] ?? null,
        );
    }
}
