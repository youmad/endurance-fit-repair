<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Corpus;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Corpus\CorpusWorkspace;

final class CorpusWorkspaceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fit-workspace-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testWritesReportRowsWithoutChangingTheirJsonValues(): void
    {
        $workspace = new CorpusWorkspace($this->directory);
        $rows = [['path' => 'лап.fit', 'diagnostics' => ['rows' => [[1, null, -2]], 'missing' => []]], ['status' => 'FAILED']];
        $workspace->writeResultRows('report.json', ['schemaVersion' => 1], $rows);
        self::assertSame(['schemaVersion' => 1, 'results' => $rows], CorpusWorkspace::readJson($this->directory.'/report.json'));
        $workspace->writeResultRows('empty.json', [], []);
        self::assertSame(['results' => []], CorpusWorkspace::readJson($this->directory.'/empty.json'));
    }

    public function testSerializationFailurePreservesPublishedReportAndRemovesTemporaryFile(): void
    {
        $workspace = new CorpusWorkspace($this->directory);
        $workspace->writeResultRows('report.json', ['schemaVersion' => 1], [['status' => 'PASS']]);
        $before = file_get_contents($this->directory.'/report.json');

        try {
            $workspace->writeResultRows('report.json', ['schemaVersion' => 1], [['status' => 'FAILED'], ['invalid' => INF]]);
            self::fail('Expected JSON serialization failure.');
        } catch (\JsonException) {
            self::assertSame($before, file_get_contents($this->directory.'/report.json'));
            self::assertSame([], glob($this->directory.'/report.json.tmp-*'));
        }
    }
}
