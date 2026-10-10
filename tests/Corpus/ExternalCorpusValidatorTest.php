<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Corpus;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Corpus\ExternalCorpusValidator;
use Youmad\Endurance\FitRepair\RepairFailed;

final class ExternalCorpusValidatorTest extends TestCase
{
    public function testUsesOnlyExternalProtocolAndRejectsResultsForDifferentBytes(): void
    {
        $root = sys_get_temp_dir().'/external-fit-validator-'.bin2hex(random_bytes(8));
        mkdir($root);
        $script = $root.'/validator.php';
        $code = <<<'PHP'
<?php
if (['--describe'] === array_slice($argv, 1)) {
    echo json_encode(['schemaVersion' => 1, 'fingerprint' => hash_file('sha256', __FILE__), 'profileSha256' => null]);
    exit(0);
}
mkdir($argv[2]);
$results = [];
foreach (glob($argv[1].'/*.fit') as $file) {
    $results[] = ['path' => basename($file), 'sha256' => hash_file('sha256', $file), 'status' => 'PASS'];
}
file_put_contents($argv[2].'/corpus.validation.json', json_encode(['schemaVersion' => 1, 'profileSha256' => null, 'results' => $results]));
PHP;
        file_put_contents($script, $code);
        file_put_contents($root.'/source.fit', 'FIT bytes belong to the external validator');
        try {
            $validator = new ExternalCorpusValidator($script);
            self::assertSame(hash('sha256', $code), $validator->fingerprint());
            self::assertNull($validator->profileSha256());
            $results = $validator->validate(['nested/activity.fit' => $root.'/source.fit'], $root.'/good');
            self::assertSame('PASS', $results['nested/activity.fit']['status']);
            self::assertSame('nested/activity.fit', $results['nested/activity.fit']['path']);

            file_put_contents($script, str_replace("hash_file('sha256', $".'file)', "str_repeat('0', 64)", $code));
            $validator = new ExternalCorpusValidator($script);
            self::assertNotSame(hash('sha256', $code), $validator->fingerprint());
            try {
                $validator->validate(['nested/activity.fit' => $root.'/source.fit'], $root.'/bad');
                self::fail('A result for different bytes must be rejected.');
            } catch (RepairFailed $failure) {
                self::assertStringContainsString('different bytes', $failure->getMessage());
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
