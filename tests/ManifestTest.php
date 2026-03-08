<?php

declare(strict_types=1);

namespace OPA\Tests;

use OPA\Manifest;
use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    public function testDefaultManifest(): void
    {
        $manifest = new Manifest();
        $output = $manifest->toString();

        $this->assertStringContainsString('Manifest-Version: 1.0', $output);
        $this->assertStringContainsString('OPA-Version: 0.1', $output);
        $this->assertStringContainsString('Prompt-File: prompt.md', $output);
    }

    public function testFullManifest(): void
    {
        $manifest = new Manifest();
        $manifest->setTitle('Test Task')
            ->setDescription('A test task')
            ->setCreatedBy('opa-php 1.0.0')
            ->setCreatedAt('2026-03-04T09:15:00Z')
            ->setAgentHint('claude-sonnet')
            ->setExecutionMode('batch')
            ->setSessionFile('session/history.json')
            ->setDataRoot('data/');

        $output = $manifest->toString();

        $this->assertStringContainsString('Title: Test Task', $output);
        $this->assertStringContainsString('Description: A test task', $output);
        $this->assertStringContainsString('Created-By: opa-php 1.0.0', $output);
        $this->assertStringContainsString('Agent-Hint: claude-sonnet', $output);
        $this->assertStringContainsString('Execution-Mode: batch', $output);
    }

    public function testLineWrapping(): void
    {
        $manifest = new Manifest();
        $manifest->setDescription('This is a very long description that should be wrapped at 72 bytes according to the JAR manifest specification');

        $output = $manifest->toString();
        $lines = explode("\r\n", $output);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(72, strlen($line), "Line exceeds 72 bytes: $line");
        }
    }

    public function testParseRoundTrip(): void
    {
        $original = new Manifest();
        $original->setTitle('Round Trip Test')
            ->setDescription('Testing parse/serialize round trip')
            ->setCreatedBy('test')
            ->setCreatedAt('2026-03-04T00:00:00Z')
            ->setAgentHint('claude-3')
            ->setExecutionMode('autonomous');

        $serialized = $original->toString();
        $parsed = Manifest::parse($serialized);

        $this->assertEquals($original->getTitle(), $parsed->getTitle());
        $this->assertEquals($original->getDescription(), $parsed->getDescription());
        $this->assertEquals($original->getCreatedBy(), $parsed->getCreatedBy());
        $this->assertEquals($original->getCreatedAt(), $parsed->getCreatedAt());
        $this->assertEquals($original->getAgentHint(), $parsed->getAgentHint());
        $this->assertEquals($original->getExecutionMode(), $parsed->getExecutionMode());
        $this->assertEquals($original->getPromptFile(), $parsed->getPromptFile());
    }

    public function testParseWithContinuationLines(): void
    {
        $manifest = new Manifest();
        $longDesc = str_repeat('A', 100);
        $manifest->setDescription($longDesc);

        $serialized = $manifest->toString();
        $parsed = Manifest::parse($serialized);

        $this->assertEquals($longDesc, $parsed->getDescription());
    }

    public function testInvalidExecutionMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $manifest = new Manifest();
        $manifest->setExecutionMode('invalid');
    }
}
