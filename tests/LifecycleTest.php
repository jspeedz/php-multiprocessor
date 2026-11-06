<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase {
    /**
     * Successful-work matrix: every workload below runs under all 48 combinations
     * of parent checks on/off, stop-on-child-fatal on/off, progress output on/off,
     * GRACEFUL/NORMAL/IMMEDIATE shutdown methods, and priorities 0/19.
     *
     * No fatal error is induced here. Therefore every combination must process
     * every input value exactly once and exit with code 0; failure-only settings
     * must not discard successful work. Numeric settings use representative
     * boundaries rather than every possible chunk size, concurrency, or priority.
     *
     * Each workload contains: input items, chunk size, maximum concurrent children.
     */
    public static function scenarios(): iterable {
        $workloads = [
            /**
             * No work: expect no child lifecycle events, no completed items, and
             * parent exit code 0. The ONCE callback still runs once; ALWAYS never runs.
             */
            'empty' => [[], 2, 2],
            /**
             * One item with room for two children: expect exactly one child to
             * process [10], finish, and exit. Unused concurrency must not create
             * extra work or prevent the parent from waiting for that child.
             */
            'single item' => [[10], 1, 2],
            /**
             * Five one-item chunks with concurrency 2: expect five child lifecycles
             * and exactly [10, 20, 30, 40, 50] processed. New children must continue
             * replacing completed children until all five chunks have finished.
             */
            'one item per child' => [[10, 20, 30, 40, 50], 1, 2],
            /**
             * Serial processing with chunk size 2: expect three children handling
             * [10, 20], [30, 40], and [50]. Only one processor may be active at a
             * time; the shorter final chunk must still run its full lifecycle.
             */
            'serial, partial final chunk' => [[10, 20, 30, 40, 50], 2, 1],
            /**
             * The same three chunks with concurrency 2: both full chunks and the
             * final [50] must finish exactly once, with at most two processors
             * active. Cross-child completion order is deliberately unrestricted.
             */
            'parallel, partial final chunk' => [[10, 20, 30, 40, 50], 2, 2],
            /**
             * Input divides evenly into [10, 20] and [30, 40]: expect exactly two
             * children and no empty third chunk. Every value must finish once.
             */
            'exact chunks' => [[10, 20, 30, 40], 2, 2],
            /**
             * Concurrency 8 but only two chunks, [10, 20, 30] and [40, 50]: expect
             * two complete child lifecycles. The parent must drain the remaining
             * children even though it never reaches its concurrency limit.
             */
            'more children than chunks' => [[10, 20, 30, 40, 50], 3, 8],
            /**
             * Chunk size 10 exceeds the five-item input: expect exactly one child
             * processing the entire input and completing its full lifecycle.
             * No item may be lost because a full-sized chunk could not be filled.
             */
            'single oversized chunk' => [[10, 20, 30, 40, 50], 10, 2],
            /**
             * Expect chunks [0, false, ''], [null, 10, 10], and [0]. Values and
             * types must be preserved: null must not end iteration or hide the
             * following 10s, and both occurrences of 0 and 10 must be processed.
             * Exactly three child lifecycles must complete without deduplication.
             */
            'duplicates and falsey values' => [[0, false, '', null, 10, 10, 0], 3, 2],
        ];
        foreach($workloads as $name => [$items, $size, $concurrency]) {
            foreach([false, true] as $parentCheck) {
                foreach([false, true] as $stopOnFatal) {
                    foreach([false, true] as $progress) {
                        foreach(StopOnFatalMethodEnum::cases() as $method) {
                            foreach([0, 19] as $priority) {
                                $label = "$name, parent=$parentCheck, fatal=$stopOnFatal, progress=$progress, {$method->value}, priority=$priority";
                                yield $label => [$items, $size, $concurrency, $parentCheck, $stopOnFatal, $progress, $method, $priority];
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Runs a real manager and child processes for each workload/settings combination.
     * Expect one child per chunk, with initialize -> process -> finish -> exit,
     * and the exact chunk values recorded as completed by process(), finish(),
     * and exit(). Compare all chunks without depending on cross-child order;
     * missing values, duplicate processing, changed types, or extra chunks fail.
     *
     * The number of active processors must stay within the configured limit and
     * return to zero. Parent-alive checks must match their setting. Resource-close
     * callbacks must run ONCE once and ALWAYS once per child, while child-local
     * processing must not mutate the parent's processor. Parent exit code must be 0.
     */
    #[DataProvider('scenarios')]
    public function testChildrenReceiveChunksAndCompleteLifecycle(
        array $items,
        int $size,
        int $concurrency,
        bool $parentCheck,
        bool $stopOnFatal,
        bool $progress,
        StopOnFatalMethodEnum $method,
        int $priority,
    ): void {
        $log = tempnam(sys_get_temp_dir(), 'multiprocessor-lifecycle-');
        self::assertNotFalse($log);
        try {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/fixtures/lifecycle.php', json_encode(
                    [$log, $items, $size, $concurrency, $parentCheck, $stopOnFatal, $progress, $method->value, $priority],
                    JSON_THROW_ON_ERROR,
                )],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            self::assertSame(0, proc_close($process), $output);
            // The progress bar uses stdout; the parent report has its own file.
            $parent = json_decode(file_get_contents($log . '.parent'), true, 512, JSON_THROW_ON_ERROR);
            $expected = array_chunk($items, $size);
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
                self::assertSame([[], array_values($items), array_values($items), array_values($items)], array_column($events, 'completed'),
                    'process(), finish(), and exit() must report every item actually processed in this chunk.');
                $actual[] = array_values($items);
            }
            // Serialize before sorting to preserve types when comparing falsey or duplicate values.
            $expected = array_map('serialize', $expected);
            $actual = array_map('serialize', $actual);
            sort($expected, SORT_STRING);
            sort($actual, SORT_STRING);
            self::assertSame($expected, $actual, 'Every input item must be processed exactly once.');
        }
        finally {
            unlink($log);
            if(is_file($log . '.parent')) {
                unlink($log . '.parent');
            }
        }
    }
}
