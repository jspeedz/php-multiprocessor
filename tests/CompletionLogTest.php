<?php
namespace Jspeedz\MultiProcessor\Tests;

use CompletionLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../examples/CompletionLog.php';

final class CompletionLogTest extends TestCase {
    public static function records(): iterable {
        yield 'all items, different order' => [['c', 'a', 'b'], true];
        yield 'missing item' => [['a', 'b'], false];
        yield 'extra duplicate' => [['a', 'b', 'c', 'c'], false];
        yield 'duplicate replacing missing item, same count' => [['a', 'b', 'b'], false];
        yield 'unexpected item, same count' => [['a', 'b', 'd'], false];
        yield 'nothing completed' => [[], false];
    }

    #[DataProvider('records')]
    public function testVerificationChecksIdentitiesAndMultiplicity(array $items, bool $valid): void {
        $path = tempnam(sys_get_temp_dir(), 'multiprocessor-proof-');
        self::assertNotFalse($path);
        try {
            $log = new CompletionLog($path);
            foreach($items as $item) {
                $log->record($item);
            }
            if(!$valid) {
                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage('Completion verification failed');
            }
            $log->verify(['a', 'b', 'c']);
            self::assertTrue($valid);
        }
        finally {
            unlink($path);
        }
    }

    public function testExampleProcessorCompletesAll10000Hashes(): void {
        $path = tempnam(sys_get_temp_dir(), 'multiprocessor-hashes-');
        self::assertNotFalse($path);
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'pcov.enabled=0', '-d', 'xdebug.mode=off', __DIR__ . '/fixtures/example-completion.php', $path],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            self::assertSame(0, proc_close($process), $output);
            // Independently check what the actual example processor recorded,
            // including identity, multiplicity, and the tail of the workload.
            (new CompletionLog($path))->verify(array_map('strval', range(0, 9999)));
            self::assertCount(10000, file($path));
        }
        finally {
            unlink($path);
        }
    }
}
