<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Tests\Support\ProcessScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailurePolicyTest extends TestCase {
    private static function config(array $changes = []): array {
        return [...[
            'count' => 43, 'size' => 5, 'concurrency' => 3,
            'position' => 'middle', 'stage' => 'mid-process', 'failure' => 'fatal',
            'stop' => true, 'method' => 'graceful', 'parentCheck' => true,
            'priority' => 19, 'progress' => false, 'ignoreTerm' => false, 'grace' => 0.1,
        ], ...$changes];
    }

    /** Failure position, lifecycle stage, policy, and serial/parallel scheduling. */
    public static function policies(): iterable {
        foreach([1, 3] as $concurrency) {
            foreach(['first', 'middle', 'last'] as $position) {
                foreach(['initialize', 'process', 'mid-process', 'finish', 'exit', 'sigkill'] as $stage) {
                    foreach([false, true] as $stop) {
                        foreach(StopOnFatalMethodEnum::cases() as $method) {
                            yield "$stage, $position, children=$concurrency, stop=" . (int) $stop . ", {$method->value}" => [self::config([
                                'count' => (2 * $concurrency + 2) * 5 + 3,
                                'concurrency' => $concurrency, 'position' => $position,
                                'stage' => $stage === 'sigkill' ? 'mid-process' : $stage,
                                'failure' => $stage === 'sigkill' ? 'sigkill' : ($stage === 'mid-process' ? 'fatal' : 'exception'),
                                'stop' => $stop, 'method' => $method->value,
                            ])];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('policies')]
    public function testRealFailuresFollowSchedulingAndShutdownPolicy(array $config): void {
        $this->assertPolicy($config);
    }

    public static function multiBatchWorkloads(): iterable {
        foreach(['first', 'last'] as $position) {
            foreach([false, true] as $stop) {
                foreach(StopOnFatalMethodEnum::cases() as $method) {
                    yield "$position, stop=" . (int) $stop . ", {$method->value}" => [self::config([
                        // Twenty chunks cover queued work and final draining;
                        // failure policy does not require 2,000 forks per case.
                        'count' => 100, 'position' => $position,
                        'stop' => $stop, 'method' => $method->value,
                    ])];
                }
            }
        }
    }

    #[DataProvider('multiBatchWorkloads')]
    public function testMultipleBatchesAreAccountedForUnderFailure(array $config): void {
        $this->assertPolicy($config);
    }

    public static function secondarySettings(): iterable {
        foreach([false, true] as $parentCheck) {
            foreach([false, true] as $stop) {
                foreach([false, true] as $progress) {
                    foreach([0, 19] as $priority) {
                        foreach(StopOnFatalMethodEnum::cases() as $method) {
                            yield 'parent=' . (int) $parentCheck . ', stop=' . (int) $stop . ', progress=' . (int) $progress
                                . ", priority=$priority, {$method->value}" => [self::config([
                                    'parentCheck' => $parentCheck, 'stop' => $stop,
                                    'progress' => $progress, 'priority' => $priority, 'method' => $method->value,
                                ])];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('secondarySettings')]
    public function testSecondarySettingsAndResourceCallbacksPreserveFailurePolicy(array $config): void {
        $this->assertPolicy($config);
    }

    public static function simultaneousFailures(): iterable {
        foreach([false, true] as $stop) {
            foreach(StopOnFatalMethodEnum::cases() as $method) {
                yield 'stop=' . (int) $stop . ", {$method->value}" => [self::config([
                    'multiple' => true, 'stop' => $stop, 'method' => $method->value,
                ])];
            }
        }
    }

    #[DataProvider('simultaneousFailures')]
    public function testMultipleFailedChildrenAreEachReapedOnce(array $config): void {
        $this->assertPolicy($config);
    }

    public static function externalSignals(): iterable {
        foreach([false, true] as $stop) {
            foreach(StopOnFatalMethodEnum::cases() as $method) {
                yield 'stop=' . (int) $stop . ", {$method->value}" => [self::config([
                    'failure' => 'external-sigkill', 'stop' => $stop, 'method' => $method->value,
                ])];
            }
        }
    }

    #[DataProvider('externalSignals')]
    public function testChildKilledByAnotherProcessFollowsFatalPolicy(array $config): void {
        $events = $this->assertPolicy($config);
        $requests = self::select($events, 'kill-requested');
        $sent = self::select($events, 'external-signal');
        self::assertCount(1, $requests);
        self::assertCount(1, $sent);
        self::assertSame($requests[0]['pid'], $sent[0]['childPID']);
        self::assertNotSame($requests[0]['pid'], $sent[0]['pid']);
        self::assertSame(SIGKILL, $sent[0]['signal']);
    }

    public static function exitCodes(): iterable {
        foreach([1, 42] as $code) {
            foreach([false, true] as $stop) {
                foreach(StopOnFatalMethodEnum::cases() as $method) {
                    yield "exit=$code, stop=" . (int) $stop . ", {$method->value}" => [self::config([
                        'failure' => 'exit' . $code, 'stop' => $stop, 'method' => $method->value,
                    ])];
                }
            }
        }
    }

    #[DataProvider('exitCodes')]
    public function testExplicitChildExitCodesFollowTheirFatalClassification(array $config): void {
        // Exit 1 triggers the fatal policy; ordinary nonzero exit 42 marks the
        // run failed but does not trigger stopOnChildFatal, even when enabled.
        $this->assertPolicy($config);
    }

    public static function gracePeriods(): iterable {
        yield 'immediate escalation' => [0.0];
        yield 'positive grace period' => [0.08];
    }

    #[DataProvider('gracePeriods')]
    public function testNormalShutdownEscalatesWhenRealChildrenIgnoreSigterm(float $grace): void {
        $events = $this->assertPolicy(self::config(['method' => 'normal', 'ignoreTerm' => true, 'grace' => $grace]));
        $signals = self::select($events, 'signal');
        foreach(array_unique(array_column($signals, 'childPID')) as $pid) {
            $sent = array_values(array_filter($signals, static fn(array $event): bool => $event['childPID'] === $pid));
            self::assertSame([SIGTERM, SIGKILL], array_column($sent, 'signal'));
            self::assertGreaterThanOrEqual((int) ($grace * 1_000_000_000), $sent[1]['time'] - $sent[0]['time']);
            // Do not assume the child gets CPU time during the grace period.
            // Its real SIGKILL status (checked above) proves SIGTERM did not end it.
        }
    }

    private static function select(array $events, string $name): array {
        return array_values(array_filter($events, static fn(array $event): bool => $event['event'] === $name));
    }

    private function assertPolicy(array $config): array {
        $chunks = array_chunk(range(0, $config['count'] - 1), $config['size']);
        $failureChunk = match($config['position']) {
            'first' => 0,
            'middle' => $config['concurrency'],
            'last' => count($chunks) - 1,
        };
        $waveStart = $config['position'] === 'last' ? max(0, count($chunks) - $config['concurrency']) : $failureChunk;
        $waveEnd = min(count($chunks) - 1, $waveStart + $config['concurrency'] - 1);
        $failChunks = ($config['multiple'] ?? false) ? [$failureChunk, $failureChunk + 1] : [$failureChunk];
        $config = [...$config, 'waveStart' => $waveStart, 'waveEnd' => $waveEnd, 'failChunks' => $failChunks];
        $stops = $config['stop'] && $config['failure'] !== 'exit42';
        $released = false;
        $externalKills = [];
        [$status, $events, $output] = ProcessScenario::run('failure-policy.php', $config,
            static function(string $log, string $output) use ($config, $stops, &$released, &$externalKills): void {
                if($config['failure'] === 'external-sigkill') {
                    foreach(ProcessScenario::events($log) as $event) {
                        if($event['event'] === 'kill-requested' && !isset($externalKills[$event['pid']])) {
                            ProcessScenario::record($log, 'external-signal', ['childPID' => $event['pid'], 'signal' => SIGKILL]);
                            self::assertTrue(posix_kill($event['pid'], SIGKILL));
                            $externalKills[$event['pid']] = true;
                        }
                    }
                }
                if($released || ($stops && $config['method'] !== 'graceful')) {
                    return;
                }
                if($stops) {
                    $detected = str_contains($output, 'currently running children will complete');
                }
                else {
                    $detected = count(array_filter(ProcessScenario::events($log), static fn(array $event): bool =>
                        $event['event'] === 'reaped' && in_array($event['chunk'], $config['failChunks'], true))) > 0;
                }
                if($detected) {
                    touch($log . '.release');
                    $released = true;
                }
            },
        );
        self::assertSame($stops && $config['method'] !== 'graceful' ? 1 : 2, $status, $output);
        self::assertNotContains('unexpected-return', array_column($events, 'event'), $output);
        $spawned = self::select($events, 'spawned');
        $starts = self::select($events, 'start');
        $reaped = self::select($events, 'reaped');
        $expectedChunks = range(0, $stops ? $waveEnd : count($chunks) - 1);
        self::assertSame($expectedChunks, array_column($spawned, 'chunk'), 'Unexpected forks: queued work must follow the policy.');
        $startedChunks = array_column($starts, 'chunk');
        sort($startedChunks);
        self::assertSame($expectedChunks, $startedChunks);
        $spawnedPIDs = array_column($spawned, 'childPID');
        $startedPIDs = array_column($starts, 'pid');
        $reapedPIDs = array_column($reaped, 'childPID');
        sort($spawnedPIDs);
        sort($startedPIDs);
        sort($reapedPIDs);
        self::assertSame($spawnedPIDs, $startedPIDs);
        self::assertSame($startedPIDs, $reapedPIDs, 'Every real child must be reaped exactly once.');
        self::assertCount(1, self::select($events, 'parent-exit'));
        self::assertSame('parent-exit', $events[array_key_last($events)]['event'], 'No child may still be recording work after its manager exits.');
        self::assertCount(1, self::select($events, 'close-once'));
        self::assertCount(count($expectedChunks), self::select($events, 'close-always'));

        $firstDetected = null;
        $active = 0;
        $finishedPIDs = [];
        $eventsByChunk = [];
        $firstFailure = array_search('failure', array_column($events, 'event'), true);
        self::assertNotFalse($firstFailure);
        self::assertCount(count($failChunks), self::select($events, 'failure'));
        foreach($events as $index => $event) {
            if(isset($event['chunk'])) {
                $eventsByChunk[$event['chunk']][] = $event;
            }
            if($event['event'] === 'spawned') {
                $active++;
                self::assertLessThanOrEqual($config['concurrency'], $active);
                if($stops && $firstDetected !== null) {
                    self::fail('A new child was forked after a failed child was reaped.');
                }
            }
            if($event['event'] === 'reaped') {
                $active--;
                $finishedPIDs[] = $event['childPID'];
                self::assertGreaterThanOrEqual(0, $active);
                if($event['chunk'] < $waveStart) {
                    self::assertLessThan($firstFailure, $index, 'Earlier chunks must be reaped before the injected failure.');
                }
                if(in_array($event['chunk'], $failChunks, true)) {
                    $firstDetected ??= $index;
                }
            }
            if($event['event'] === 'signal') {
                self::assertNotContains($event['childPID'], $finishedPIDs, 'A reaped PID must never be signalled again.');
            }
        }
        self::assertSame(0, $active);
        self::assertNotNull($firstDetected);
        $expectedItems = [];
        foreach($expectedChunks as $chunk) {
            $failed = in_array($chunk, $failChunks, true);
            $interrupted = !$failed && $stops && $config['method'] !== 'graceful' && $chunk >= $waveStart && $chunk <= $waveEnd;
            $expected = $chunks[$chunk];
            if($failed) {
                $expected = match($config['stage']) {
                    'initialize', 'process' => [],
                    'mid-process' => array_slice($expected, 0, 2),
                    'finish', 'exit' => $expected,
                };
            }
            elseif($interrupted) {
                $expected = array_slice($expected, 0, 1);
            }
            $childEvents = $eventsByChunk[$chunk];
            self::assertSame($expected, array_column(self::select($childEvents, 'processed'), 'item'), "Unexpected completed items in chunk $chunk.");
            array_push($expectedItems, ...$expected);
            $result = self::select($childEvents, 'reaped');
            self::assertCount(1, $result);
            $killed = ($failed && in_array($config['failure'], ['sigkill', 'external-sigkill'], true))
                || ($interrupted && ($config['method'] === 'immediate' || $config['ignoreTerm']));
            $expectedSignal = $killed ? SIGKILL : null;
            $expectedExit = $expectedSignal !== null ? null : ($failed ? match($config['failure']) {
                'exit1' => 1, 'exit42' => 42, default => 255,
            } : ($interrupted ? 128 + SIGTERM : 0));
            self::assertSame($expectedSignal, $result[0]['signal'], "Unexpected signal for chunk $chunk.");
            self::assertSame($expectedExit, $result[0]['exitCode'], "Unexpected exit code for chunk $chunk.");
            $finished = (!$failed && !$interrupted) || ($failed && $config['stage'] === 'exit');
            $exited = !$failed && !$interrupted;
            $expectedStages = ['start'];
            if(!$failed || $config['stage'] !== 'initialize') {
                $expectedStages[] = 'initialized';
            }
            $expectedStages = [...$expectedStages, ...array_fill(0, count($expected), 'processed')];
            if($finished) {
                $expectedStages[] = 'finish';
            }
            if($exited) {
                $expectedStages[] = 'exit';
            }
            $lifecycle = array_values(array_filter($childEvents, static fn(array $event): bool =>
                in_array($event['event'], ['start', 'initialized', 'processed', 'finish', 'exit'], true)));
            self::assertSame($expectedStages, array_column($lifecycle, 'event'), "Unexpected lifecycle in chunk $chunk.");
        }
        $actualItems = array_column(self::select($events, 'processed'), 'item');
        sort($expectedItems);
        sort($actualItems);
        self::assertSame($expectedItems, $actualItems, 'Missing, duplicate, or unexpected completed work.');
        $signals = self::select($events, 'signal');
        if(!$stops || $config['method'] === 'graceful') {
            self::assertSame([], $signals, 'This policy must not terminate siblings.');
        }
        else {
            $allowedSignals = $config['method'] === 'immediate' ? [SIGKILL]
                : ($config['ignoreTerm'] ? [SIGTERM, SIGKILL] : [SIGTERM]);
            foreach($signals as $signal) {
                self::assertContains($signal['childPID'], $spawnedPIDs);
                self::assertContains($signal['signal'], $allowedSignals);
            }
            foreach($starts as $start) {
                if(in_array($start['chunk'], $failChunks, true) || $start['chunk'] < $waveStart) {
                    continue;
                }
                $sent = array_column(array_values(array_filter($signals, static fn(array $event): bool => $event['childPID'] === $start['pid'])), 'signal');
                self::assertSame($config['method'] === 'immediate' ? [SIGKILL] : ($config['ignoreTerm'] ? [SIGTERM, SIGKILL] : [SIGTERM]), $sent);
            }
        }
        return $events;
    }
}
