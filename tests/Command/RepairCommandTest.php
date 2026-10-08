<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Command;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Command\RepairCommand;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class RepairCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/endurance-fit-repair-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        file_put_contents($this->directory.'/input.fit', FitFixture::file(FitFixture::record(0x10000001)));
    }

    protected function tearDown(): void
    {
        foreach (['input.fit', 'output.fit', 'report.json'] as $name) {
            $path = $this->directory.'/'.$name;

            if (file_exists($path)) {
                unlink($path);
            }
        }

        rmdir($this->directory);
    }

    public function testWritesOutputAndReportWithoutChangingInput(): void
    {
        $original = file_get_contents($this->directory.'/input.fit');
        [$code, $stdout, $stderr] = $this->runCommand($this->arguments());
        self::assertSame(0, $code);
        self::assertSame('', $stderr);
        self::assertStringContainsString('activity validation is still required', $stdout);
        self::assertSame($original, file_get_contents($this->directory.'/input.fit'));
        $json = file_get_contents($this->directory.'/report.json');
        self::assertIsString($json);
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(hash_file('sha256', $this->directory.'/output.fit'), $report['output_sha256']);
    }

    public function testDoesNotOverwriteExistingOutput(): void
    {
        file_put_contents($this->directory.'/output.fit', 'keep');
        [$code] = $this->runCommand(array_slice($this->arguments(), 0, 2));
        self::assertSame(1, $code);
        self::assertSame('keep', file_get_contents($this->directory.'/output.fit'));
        self::assertFileDoesNotExist($this->directory.'/report.json');
    }

    public function testRollsBackNewFitWhenReportAlreadyExists(): void
    {
        file_put_contents($this->directory.'/report.json', 'keep');
        [$code] = $this->runCommand($this->arguments());
        self::assertSame(1, $code);
        self::assertSame('keep', file_get_contents($this->directory.'/report.json'));
        self::assertFileDoesNotExist($this->directory.'/output.fit');
    }

    public function testFailureWithoutOffsetCreatesNoFiles(): void
    {
        [$code, $stdout] = $this->runCommand([...array_slice($this->arguments(), 0, 3), '--mode=reconstruct']);
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertFileDoesNotExist($this->directory.'/output.fit');
        self::assertFileDoesNotExist($this->directory.'/report.json');
    }

    public function testDefaultModePreservesSourceWithoutInventingAnOffset(): void
    {
        [$code, $stdout, $stderr] = $this->runCommand(array_slice($this->arguments(), 0, 2));
        self::assertSame(0, $code);
        self::assertSame('', $stderr);
        self::assertSame("FIT written; activity validation is still required.\n", $stdout);
        self::assertSame(file_get_contents($this->directory.'/input.fit'), file_get_contents($this->directory.'/output.fit'));
        self::assertFileDoesNotExist($this->directory.'/report.json');
    }

    public function testRejectsDuplicateArguments(): void
    {
        [$code] = $this->runCommand([...$this->arguments(), '--local-time-offset=3600']);
        self::assertSame(1, $code);
        self::assertFileDoesNotExist($this->directory.'/output.fit');
    }

    /** @return list<string> */
    private function arguments(): array
    {
        return [
            '--input='.$this->directory.'/input.fit',
            '--output='.$this->directory.'/output.fit',
            '--report='.$this->directory.'/report.json',
            '--local-time-offset=0',
        ];
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function runCommand(array $arguments): array
    {
        $stdout = fopen('php://temp', 'w+b');
        $stderr = fopen('php://temp', 'w+b');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        try {
            $code = (new RepairCommand())->run($arguments, $stdout, $stderr);
            rewind($stdout);
            rewind($stderr);
            $output = stream_get_contents($stdout);
            $error = stream_get_contents($stderr);
            self::assertIsString($output);
            self::assertIsString($error);

            return [$code, $output, $error];
        } finally {
            fclose($stdout);
            fclose($stderr);
        }
    }
}
