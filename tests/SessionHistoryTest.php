<?php

declare(strict_types=1);

namespace OPA\Tests;

use OPA\Message;
use OPA\SessionHistory;
use PHPUnit\Framework\TestCase;

class SessionHistoryTest extends TestCase
{
    public function testCreateSessionHistory(): void
    {
        $history = new SessionHistory(
            sessionId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            createdAt: '2026-03-01T10:00:00Z',
        );

        $history->addMessage(new Message(
            role: 'user',
            content: 'Hello',
            id: '1',
            timestamp: '2026-03-01T10:00:00Z',
        ));

        $history->addMessage(new Message(
            role: 'assistant',
            content: 'Hi there!',
            id: '2',
            timestamp: '2026-03-01T10:00:01Z',
        ));

        $this->assertCount(2, $history->getMessages());
        $this->assertEquals('f47ac10b-58cc-4372-a567-0e02b2c3d479', $history->getSessionId());
    }

    public function testJsonRoundTrip(): void
    {
        $history = new SessionHistory(
            sessionId: 'test-session-id',
            createdAt: '2026-03-01T10:00:00Z',
            updatedAt: '2026-03-01T11:00:00Z',
        );

        $history->addMessage(new Message(
            role: 'user',
            content: 'Test message',
            id: '1',
        ));

        $json = $history->toJson();
        $parsed = SessionHistory::fromJson($json);

        $this->assertEquals($history->getSessionId(), $parsed->getSessionId());
        $this->assertEquals($history->getCreatedAt(), $parsed->getCreatedAt());
        $this->assertEquals($history->getUpdatedAt(), $parsed->getUpdatedAt());
        $this->assertCount(1, $parsed->getMessages());
        $this->assertEquals('user', $parsed->getMessages()[0]->getRole());
        $this->assertEquals('Test message', $parsed->getMessages()[0]->getContent());
    }

    public function testContentBlocks(): void
    {
        $message = new Message(
            role: 'assistant',
            content: [
                ['type' => 'text', 'text' => 'Here is the image:'],
                ['type' => 'image', 'source' => ['type' => 'attachment', 'path' => 'session/attachments/img.png']],
            ],
        );

        $this->assertIsArray($message->getContent());
        $this->assertCount(2, $message->getContent());
    }

    public function testInvalidRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message(role: 'invalid', content: 'test');
    }
}
