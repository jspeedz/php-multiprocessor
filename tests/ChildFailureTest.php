<?php
namespace Jspeedz\MultiProcessor\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChildFailureTest extends TestCase {
    /**
     * Covers failures in the first or last chunk with one or two children running
     * at a time. Exit codes 1 and 42 simulate unsuccessful child exits; 255 is
     * produced by a real PHP fatal error in the fixture.
     *
     * Also covers successful processing, empty input, and stopping after a fatal
     * error before any later chunks start when only one child can run at a time.
     * Every scenario uses one item per child and graceful shutdown as the
     * configured shutdown method. stopOnChildFatal decides whether a failure
     * should actually stop scheduling later children.
     *
     * Each dataset contains: input items, failing item, child exit code,
     * stopOnChildFatal, concurrency limit, expected completed items, parent exit code.
     */
    public static function childFailureScenarios(): iterable {
        foreach([1, 2] as $concurrency) {
            foreach([1, 42, 255] as $exitCode) {
                /**
                 * Early failure with stopping disabled: child 0 fails before
                 * recording completion. Children for items 1, 2, and 3 must still
                 * process their work exactly once, even if not yet started when
                 * the failure is detected. The parent must exit with code 2.
                 *
                 * Run serially and with up to two concurrent children, using
                 * child exit codes 1, 42, and a real PHP fatal error (255).
                 */
                yield "early failure, exit $exitCode, concurrency $concurrency" => [
                    [0, 1, 2, 3],
                    0,
                    $exitCode,
                    false,
                    $concurrency,
                    [1, 2, 3],
                    2,
                ];
                /**
                 * Final-item failure with stopping disabled: items 0, 1, and 2
                 * must complete exactly once; child 3 fails without completing
                 * its item. The parent must still exit with code 2 when there
                 * is no later work left to schedule, including a failure handled
                 * while waiting for the final children.
                 *
                 * Run with concurrency 1 and 2 and child exit codes 1, 42, and 255.
                 * Concurrent completion order is allowed to vary.
                 */
                yield "final failure, exit $exitCode, concurrency $concurrency" => [
                    [0, 1, 2, 3],
                    3,
                    $exitCode,
                    false,
                    $concurrency,
                    [0, 1, 2],
                    2,
                ];
            }
            /**
             * Successful processing with stopping disabled: no item matches the
             * failing-item sentinel (-1). All four items [0, 1, 2, 3] must finish
             * exactly once and the parent must return exit code 0. Run with one
             * and two concurrent children to cover serial and parallel scheduling.
             */
            yield "success, concurrency $concurrency" => [
                [0, 1, 2, 3],
                -1,
                0,
                false,
                $concurrency,
                [0, 1, 2, 3],
                0,
            ];
        }
        /**
         * Empty input: there are no items to process, so the completion log must
         * stay empty and the parent must exit successfully with code 0.
         * This scenario checks completion data and parent status, not fork events.
         */
        yield 'empty input' => [[], -1, 0, false, 1, [], 0];
        /**
         * Stop on the first fatal, with concurrency 1: item 0 causes a real PHP
         * fatal error (255) before recording completion. No other child is already
         * running to drain. The completion log must remain empty, including items
         * 1, 2, and 3, and the parent must exit with code 2. Exact fork events are
         * asserted separately by the synchronized graceful-shutdown scenarios.
         */
        yield 'stop on fatal' => [[0, 1, 2, 3], 0, 255, true, 1, [], 2];
    }

    /**
     * Checks both the manager process's exit code and which items its children
     * actually finish. With stopping disabled, an early or final child failure
     * must still let the other items finish and make the manager exit with code 2.
     * Successful runs, including empty input, must exit with code 0. With stopping
     * enabled and one child at a time, a fatal error in the first chunk must make
     * the manager exit with code 2 without processing the remaining items.
     */
    #[DataProvider('childFailureScenarios')]
    public function testChildFailureHandling(
        array $items,
        int $failureIndex,
        int $exitCode,
        bool $stopOnFatal,
        int $concurrency,
        array $expectedItems,
        int $expectedExitCode,
    ): void {
        $log = tempnam(sys_get_temp_dir(), 'multiprocessor-test-');
        self::assertNotFalse($log);

        try {
            // A fresh interpreter lets undefined functions cause real fatal errors,
            // outside PHPUnit's exception handling, and isolates process exits.
            $process = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/fixtures/child-failure.php',
                    json_encode(
                        [$items, $failureIndex, $exitCode, $stopOnFatal, $concurrency, $log],
                        JSON_THROW_ON_ERROR,
                    ),
                ],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['redirect', 1],
                ],
                $pipes,
            );
            self::assertIsResource($process);

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $actualExitCode = proc_close($process);

            self::assertSame($expectedExitCode, $actualExitCode, 'Unexpected parent exit code. Output: ' . $output);

            $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertNotFalse($lines);
            $completed = array_map('intval', $lines);
            sort($completed);
            sort($expectedItems);
            self::assertSame($expectedItems, $completed, 'Unexpected completed chunks. Output: ' . $output);
        }
        finally {
            unlink($log);
        }
    }

    /**
     * Each dataset contains the concurrency limit and items per child. The fixture
     * supplies one successful group of children, one group containing the fatal,
     * and two remaining chunks. These groups describe chunk assignments; the
     * scheduler may start the next group while earlier children are finishing.
     * Synchronization guarantees that all earlier children are reaped before
     * the fatal and all healthy children in the crashing group are still running.
     * All four cases require parent exit code 2 and no forks for remaining chunks.
     */
    public static function gracefulShutdownScenarios(): iterable {
        /**
         * Two children, one item each; input [0..5]. Children for items 0 and 1
         * must finish and be reaped with exit code 0 before child 2 fatals (255).
         * Child 3 must already be running and wait for shutdown detection, then
         * process item 3, run finish()/exit(), and be reaped with exit code 0.
         * Exactly four children may be forked; items 4 and 5 must never start.
         * Completed items must be exactly [0, 1, 3], each once, before parent exit 2.
         */
        yield 'concurrency 2, chunk size 1' => [2, 1];
        /**
         * Two children, three items each; input [0..17]. The first two children
         * must finish items 0..5 and be reaped successfully before the child for
         * items 6..8 fatals (255) without processing any of its items. Its running
         * sibling must wait for shutdown detection, then process all of 9..11,
         * run finish()/exit(), and be reaped with exit code 0.
         * Exactly four children may be forked; neither chunk covering 12..17 may
         * start. Completed items must be exactly 0..5 and 9..11, each once, before
         * parent exit 2. This catches a surviving child abandoning part of its chunk.
         */
        yield 'concurrency 2, chunk size 3' => [2, 3];
        /**
         * Three children, one item each; input [0..7]. Children for items 0, 1,
         * and 2 must finish and be reaped successfully before child 3 fatals (255).
         * Both children for items 4 and 5 must already be running, wait for
         * shutdown detection, then each process its item, run finish()/exit(),
         * and be reaped with exit code 0. Their completion order may vary.
         * Exactly six children may be forked; items 6 and 7 must never start.
         * Completed items must be exactly [0, 1, 2, 4, 5], each once, before parent
         * exit 2. This checks that shutdown drains multiple surviving children.
         */
        yield 'concurrency 3, chunk size 1' => [3, 1];
        /**
         * Three children, three items each; input [0..23]. The first three children
         * must finish items 0..8 and be reaped successfully before the child for
         * items 9..11 fatals (255) without processing any of its items. Both running
         * siblings must wait for shutdown detection, then finish their entire
         * chunks (12..14 and 15..17), run finish()/exit(), and be reaped with code 0.
         * Exactly six children may be forked; neither chunk covering 18..23 may
         * start. Completed items must be exactly 0..8 and 12..17, each once, before
         * parent exit 2. This checks multiple survivors with multi-item chunks.
         */
        yield 'concurrency 3, chunk size 3' => [3, 3];
    }

    /**
     * Proves graceful shutdown using real forks, a real PHP fatal error, and
     * explicit synchronization rather than delays that assume scheduler timing.
     *
     * An initial batch processes every item, runs finish()/exit(), and is reaped
     * with status 0. In the next batch, one child waits until all its siblings
     * have started and the initial batch has been reaped, then fatals. Its
     * siblings remain blocked until PHPUnit reads the manager's graceful-shutdown
     * message. Only then does PHPUnit release them to process their entire chunks.
     * Timeouts fail the scenario; they never stand in for successful coordination.
     *
     * The fixture observes real pcntl_fork()/pcntl_waitpid() calls without changing
     * their behavior. Assertions check exact starts, processed items, lifecycle
     * events, and OS exit statuses, including status 255 for the failed child.
     * Earlier work must finish before the fatal; running siblings must complete
     * after shutdown is detected. Every child must be reaped before the parent
     * exits with code 2, and the two remaining chunks must never even be forked.
     * Both single-item and multi-item chunks are covered with two or three children.
     */
    #[DataProvider('gracefulShutdownScenarios')]
    public function testGracefulShutdownFinishesRunningChildrenWithoutStartingMore(int $concurrency, int $chunkSize): void {
        $log = tempnam(sys_get_temp_dir(), 'multiprocessor-graceful-');
        self::assertNotFalse($log);
        $process = null;
        $pipes = [];

        try {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/fixtures/graceful-shutdown.php', json_encode(
                    [$log, $concurrency, $chunkSize], JSON_THROW_ON_ERROR,
                )],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            self::assertIsResource($process);
            stream_set_blocking($pipes[1], false);
            $output = '';
            $released = false;
            $deadline = microtime(true) + 15;

            while(!feof($pipes[1])) {
                if(microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for graceful shutdown. Output: ' . $output);
                }
                $read = [$pipes[1]];
                $write = $except = [];
                if(stream_select($read, $write, $except, 0, 10000) > 0) {
                    $output .= stream_get_contents($pipes[1]);
                }
                if(!$released && str_contains($output, 'currently running children will complete')) {
                    file_put_contents($log, json_encode([
                        'event' => 'shutdown-observed', 'pid' => getmypid(),
                    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
                    touch($log . '.release');
                    $released = true;
                }
            }
            fclose($pipes[1]);
            $status = proc_close($process);
            $process = null;
            self::assertTrue($released, 'The manager must announce graceful shutdown. Output: ' . $output);
            self::assertSame(2, $status, $output);

            $events = array_map(
                static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            $indexOf = static function(string $event, int $pid) use ($events): int {
                $indices = array_keys(array_filter($events, static fn(array $entry): bool =>
                    $entry['event'] === $event && $entry['pid'] === $pid));
                self::assertCount(1, $indices, "Expected exactly one $event event for PID $pid.");
                return $indices[0];
            };
            self::assertNotContains('timeout', array_column($events, 'event'));
            self::assertNotContains('unexpected-return', array_column($events, 'event'));
            $spawned = array_values(array_filter($events, static fn(array $event): bool => $event['event'] === 'spawned'));
            self::assertCount(2 * $concurrency, $spawned, 'No children may be forked for the remaining chunks.');
            $parentPID = $spawned[0]['pid'];
            $parentExit = $indexOf('parent-exit', $parentPID);
            self::assertSame($parentExit, array_key_last($events), 'The parent must exit after every child was reaped.');
            $shutdownObserved = $indexOf('shutdown-observed', getmypid());

            $starts = $reaped = [];
            foreach($events as $index => $event) {
                if($event['event'] === 'start') {
                    self::assertArrayNotHasKey($event['chunk'], $starts, 'A chunk must not start twice.');
                    $starts[$event['chunk']] = $event['pid'];
                }
                if($event['event'] === 'reaped') {
                    self::assertSame($parentPID, $event['pid']);
                    self::assertArrayNotHasKey($event['childPID'], $reaped);
                    $reaped[$event['childPID']] = [...$event, 'index' => $index];
                }
            }
            ksort($starts);
            self::assertSame(range(0, 2 * $concurrency - 1), array_keys($starts));
            $spawnedPIDs = array_column($spawned, 'childPID');
            $startedPIDs = array_values($starts);
            $reapedPIDs = array_keys($reaped);
            sort($spawnedPIDs);
            sort($startedPIDs);
            sort($reapedPIDs);
            self::assertSame($spawnedPIDs, $startedPIDs, 'Every forked child must be accounted for.');
            self::assertSame($startedPIDs, $reapedPIDs, 'Every started child must be reaped by its parent.');
            $failedPID = $starts[$concurrency];
            $fatal = $indexOf('fatal', $failedPID);
            self::assertSame(E_ERROR, $events[$fatal]['errorType']);
            self::assertLessThan($reaped[$failedPID]['index'], $fatal);
            self::assertLessThan($shutdownObserved, $reaped[$failedPID]['index']);

            foreach($starts as $chunk => $pid) {
                $childEvents = array_values(array_filter($events, static fn(array $event): bool => $event['pid'] === $pid));
                self::assertLessThan($fatal, $indexOf('start', $pid), 'All children must start before the crash.');
                $expectedStages = ['start'];
                if($chunk === $concurrency) {
                    $expectedStages[] = 'fatal';
                    $expectedItems = [];
                }
                else {
                    if($chunk > $concurrency) {
                        $expectedStages[] = 'released';
                        self::assertLessThan($indexOf('released', $pid), $shutdownObserved,
                            'Running siblings must remain blocked until shutdown is detected.');
                    }
                    $expectedStages = [...$expectedStages, ...array_fill(0, $chunkSize, 'processed'), 'finish', 'exit'];
                    $expectedItems = range($chunk * $chunkSize, ($chunk + 1) * $chunkSize - 1);
                }
                self::assertSame($expectedStages, array_column($childEvents, 'event'));
                $processed = array_values(array_filter($childEvents, static fn(array $event): bool => $event['event'] === 'processed'));
                self::assertSame($expectedItems, array_column($processed, 'item'), 'Every healthy child must process its entire chunk exactly once.');
                self::assertSame($chunk === $concurrency ? 255 : 0, $reaped[$pid]['exitCode']);
                self::assertNull($reaped[$pid]['signal'], 'Healthy children must not be killed during graceful shutdown.');
                self::assertLessThan($parentExit, $reaped[$pid]['index']);
                if($chunk < $concurrency) {
                    self::assertLessThan($fatal, $reaped[$pid]['index'], 'Earlier work must finish and be reaped before the crash.');
                }
                if($chunk !== $concurrency) {
                    self::assertLessThan($reaped[$pid]['index'], $indexOf('exit', $pid));
                }
            }
        }
        finally {
            if(is_resource($process)) {
                proc_terminate($process, SIGKILL);
                if(isset($pipes[1]) && is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                proc_close($process);
            }
            unlink($log);
            if(is_file($log . '.release')) {
                unlink($log . '.release');
            }
        }
    }
}
