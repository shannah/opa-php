<?php

declare(strict_types=1);

namespace OPA\Tests;

use OPA\DataAsset;
use OPA\DataIndex;
use OPA\Message;
use OPA\OpaArchive;
use OPA\OpaReader;
use OPA\SessionHistory;
use PHPUnit\Framework\TestCase;

class OpaArchiveTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/opa-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testMinimalArchive(): void
    {
        $outputPath = $this->tempDir . '/minimal.opa';

        $archive = new OpaArchive('Please summarize the data.');
        $archive->save($outputPath);

        $this->assertFileExists($outputPath);

        $reader = OpaReader::open($outputPath);
        $this->assertEquals('Please summarize the data.', $reader->getPromptContent());
        $this->assertEquals('prompt.md', $reader->getManifest()->getPromptFile());
        $this->assertFalse($reader->hasSessionHistory());
        $this->assertFalse($reader->hasDataIndex());
        $reader->close();
    }

    public function testFullArchive(): void
    {
        $outputPath = $this->tempDir . '/full.opa';

        $archive = new OpaArchive('Analyze the sales data in `data/sales.csv`.');
        $archive->getManifest()
            ->setTitle('Sales Analysis')
            ->setDescription('Analyze Q1 sales data')
            ->setCreatedBy('opa-php test')
            ->setCreatedAt('2026-03-04T09:15:00Z')
            ->setAgentHint('claude-sonnet')
            ->setExecutionMode('batch');

        // Add session history
        $history = new SessionHistory(
            sessionId: 'test-session',
            createdAt: '2026-03-01T10:00:00Z',
        );
        $history->addMessage(new Message(role: 'user', content: 'Start analysis', id: '1'));
        $history->addMessage(new Message(role: 'assistant', content: 'Sure, analyzing...', id: '2'));
        $archive->setSessionHistory($history);

        // Add data files
        $archive->addDataFromString('data/sales.csv', "region,amount\nnorth,1000\nsouth,2000\n");

        // Add data index
        $index = new DataIndex();
        $index->addAsset(new DataAsset(
            path: 'data/sales.csv',
            description: 'Q1 sales data',
            contentType: 'text/csv',
        ));
        $archive->setDataIndex($index);

        $archive->save($outputPath);

        // Read it back
        $reader = OpaReader::open($outputPath);

        $this->assertEquals('Sales Analysis', $reader->getManifest()->getTitle());
        $this->assertEquals('batch', $reader->getManifest()->getExecutionMode());
        $this->assertStringContainsString('sales data', $reader->getPromptContent());

        $this->assertTrue($reader->hasSessionHistory());
        $session = $reader->getSessionHistory();
        $this->assertNotNull($session);
        $this->assertEquals('test-session', $session->getSessionId());
        $this->assertCount(2, $session->getMessages());

        $this->assertTrue($reader->hasDataIndex());
        $dataIndex = $reader->getDataIndex();
        $this->assertNotNull($dataIndex);
        $this->assertCount(1, $dataIndex->getAssets());
        $this->assertEquals('data/sales.csv', $dataIndex->getAssets()[0]->getPath());

        $csvContent = $reader->getFileContent('data/sales.csv');
        $this->assertStringContainsString('north,1000', $csvContent);

        $reader->close();
    }

    public function testAddDataFromFile(): void
    {
        $dataFile = $this->tempDir . '/test-data.txt';
        file_put_contents($dataFile, 'Hello from file');

        $outputPath = $this->tempDir . '/with-file.opa';

        $archive = new OpaArchive('Check the data.');
        $archive->addDataFromFile('data/test.txt', $dataFile);
        $archive->save($outputPath);

        $reader = OpaReader::open($outputPath);
        $this->assertEquals('Hello from file', $reader->getFileContent('data/test.txt'));
        $reader->close();
    }

    public function testSessionAttachments(): void
    {
        $outputPath = $this->tempDir . '/with-attachments.opa';

        $archive = new OpaArchive('Review the attached image.');
        $archive->addSessionAttachmentFromString('notes.md', '# Session Notes');
        $archive->save($outputPath);

        $reader = OpaReader::open($outputPath);
        $this->assertEquals('# Session Notes', $reader->getFileContent('session/attachments/notes.md'));
        $reader->close();
    }

    public function testPathTraversalRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $archive = new OpaArchive('test');
        $archive->addDataFromString('../etc/passwd', 'malicious');
    }

    public function testAbsolutePathRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $archive = new OpaArchive('test');
        $archive->addDataFromString('/etc/passwd', 'malicious');
    }

    public function testExtractTo(): void
    {
        $outputPath = $this->tempDir . '/extract-test.opa';
        $extractDir = $this->tempDir . '/extracted';

        $archive = new OpaArchive('Test prompt.');
        $archive->addDataFromString('data/file.txt', 'extracted content');
        $archive->save($outputPath);

        $reader = OpaReader::open($outputPath);
        $reader->extractTo($extractDir);
        $reader->close();

        $this->assertFileExists($extractDir . '/META-INF/MANIFEST.MF');
        $this->assertFileExists($extractDir . '/prompt.md');
        $this->assertFileExists($extractDir . '/data/file.txt');
        $this->assertEquals('extracted content', file_get_contents($extractDir . '/data/file.txt'));
    }

    public function testListEntries(): void
    {
        $outputPath = $this->tempDir . '/list-test.opa';

        $archive = new OpaArchive('Test.');
        $archive->addDataFromString('data/a.txt', 'a');
        $archive->addDataFromString('data/b.txt', 'b');
        $archive->save($outputPath);

        $reader = OpaReader::open($outputPath);
        $entries = $reader->listEntries();

        $this->assertContains('META-INF/MANIFEST.MF', $entries);
        $this->assertContains('prompt.md', $entries);
        $this->assertContains('data/a.txt', $entries);
        $this->assertContains('data/b.txt', $entries);

        $reader->close();
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $archive = new OpaArchive('test');
        $archive->addDataFromFile('data/missing.txt', '/nonexistent/file.txt');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
