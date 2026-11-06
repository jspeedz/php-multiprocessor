<?php
namespace Jspeedz\MultiProcessor\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase {
    public static function scenarios(): iterable {
        yield 'serial, partial final chunk' => [2, 1, true];
        yield 'parallel, parent check disabled' => [2, 2, false];
        yield 'more children than chunks' => [3, 8, true];
        yield 'single chunk' => [10, 2, false];
    }

    #[DataProvider('scenarios')]
    public function testChildrenReceiveChunksAndCompleteLifecycle(int $size, int $concurrency, bool $parentCheck): void {
        $log = tempnam(sys_get_temp_dir(), 'multiprocessor-lifecycle-');
        self::assertNotFalse($log);
        try {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/fixtures/lifecycle.php', json_encode([$log, $size, $concurrency, $parentCheck], JSON_THROW_ON_ERROR)],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            self::assertSame(0, proc_close($process), $output);
            $parent = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $expected = array_chunk([10, 20, 30, 40, 50], $size);
            self::assertSame(1, $parent['once']);
            self::assertSame(count($expected), $parent['always']);
            self::assertSame(0, $parent['processed'], 'Children must not mutate the parent processor.');

            $children = [];
            $active = 0;
            foreach(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertNotSame($parent['pid'], $event['pid']);
                self::assertSame($parentCheck, $event['parentCheck']);
                if($event['stage'] === 'initialize') {
                    $active++;
                    self::assertLessThanOrEqual($concurrency, $active, 'Active processors must respect the concurrency limit.');
                }
                else if($event['stage'] === 'exit') {
                    $active--;
                }
                $children[$event['pid']][] = $event;
            }
            self::assertSame(0, $active);
            self::assertCount(count($expected), $children);
            $actual = [];
            foreach($children as $events) {
                self::assertSame(['initialize', 'process', 'finish', 'exit'], array_column($events, 'stage'));
                $items = $events[0]['items'];
                self::assertSame([$items, $items, $items, $items], array_column($events, 'items'));
                self::assertSame([0, count($items), count($items), count($items)], array_column($events, 'processed'));
                $actual[] = array_values($items);
            }
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, 'Every input item must be processed exactly once.');
        }
        finally {
            unlink($log);
        }
    }
}
