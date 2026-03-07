<?php

declare(strict_types=1);

namespace OPA;

class Message
{
    /**
     * @param string $role One of: user, assistant, system, tool
     * @param string|array $content Plain text or array of content blocks
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $role,
        private string|array $content,
        private ?string $id = null,
        private ?string $timestamp = null,
        private array $metadata = [],
    ) {
        if (!in_array($role, ['user', 'assistant', 'system', 'tool'], true)) {
            throw new \InvalidArgumentException("Invalid role: $role");
        }
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getContent(): string|array
    {
        return $this->content;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getTimestamp(): ?string
    {
        return $this->timestamp;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }
        if ($this->timestamp !== null) {
            $data['timestamp'] = $this->timestamp;
        }
        if (!empty($this->metadata)) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'],
            content: $data['content'],
            id: $data['id'] ?? null,
            timestamp: $data['timestamp'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
