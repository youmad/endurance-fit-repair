<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Command;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Command\CorpusRepairCommand;

final class CorpusRepairCommandTest extends TestCase
{
    public function testAcceptsTheFlagWithoutAnEqualsValue(): void
    {
        foreach (['--collect-geography', '--reconcile-summary-clocks', '--use-utc-for-unknown-local-time'] as $flag) {
            [$code, $stdout, $stderr] = $this->runCommand([$flag]);
            self::assertSame(2, $code);
            self::assertSame('', $stdout);
            self::assertStringContainsString('Missing --corpus=PATH', $stderr);
        }
    }

    public function testRejectsDuplicateFlags(): void
    {
        foreach (['--collect-geography', '--reconcile-summary-clocks', '--use-utc-for-unknown-local-time'] as $flag) {
            [$code, , $stderr] = $this->runCommand([$flag, $flag]);
            self::assertSame(2, $code);
            self::assertStringContainsString('Unknown, malformed or duplicate option: '.$flag, $stderr);
        }
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
            $code = (new CorpusRepairCommand())->run($arguments, $stdout, $stderr);
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
