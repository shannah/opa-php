<?php

declare(strict_types=1);

namespace OPA;

class SessionHistory
{
    /** @var Message[] */
    private array $messages = [];

    public function __construct(
        private string $sessionId,
        private ?string $createdAt = null,
        private ?string $updatedAt = null,
    ) {
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @return Message[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function addMessage(Message $message): self
    {
        $this->messages[] = $message;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'opa_version' => Manifest::OPA_VERSION,
            'session_id' => $this->sessionId,
        ];

        if ($this->createdAt !== null) {
            $data['created_at'] = $this->createdAt;
        }
        if ($this->updatedAt !== null) {
            $data['updated_at'] = $this->updatedAt;
        }

        $data['messages'] = array_map(
            fn(Message $m) => $m->toArray(),
            $this->messages,
        );

        return $data;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $history = new self(
            sessionId: $data['session_id'],
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );

        foreach ($data['messages'] ?? [] as $messageData) {
            $history->addMessage(Message::fromArray($messageData));
        }

        return $history;
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::fromArray($data);
    }
}
