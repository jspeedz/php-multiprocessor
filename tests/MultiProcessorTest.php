<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Exception\NonCleanExitException;
use Jspeedz\MultiProcessor\Exception\UnsupportedException;
use Jspeedz\MultiProcessor\Exception\InvalidPriorityException;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Granularity;

#[CoversClass(MultiProcessor::class)]
final class MultiProcessorTest extends TestCase {
    private array $handlers = [];
    private bool $asyncSignals;

    protected function setUp(): void {
        $this->asyncSignals = pcntl_async_signals();
        foreach([SIGTERM, SIGINT, SIGCHLD] as $signal) {
            $this->handlers[$signal] = pcntl_signal_get_handler($signal);
        }
    }

    protected function tearDown(): void {
        foreach($this->handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        pcntl_async_signals($this->asyncSignals);
    }

    private function manager(): MultiProcessor {
        $processor = new class extends ProcessorAbstract {
            public function process(): void {}
        };
        $manager = new MultiProcessor(new ArrayIterator([]), $processor);
        $manager->useProgressBar(false);
        return $manager;
    }

    #[TestWith([-21])]
    #[TestWith([20])]
    public function testInvalidPrioritiesAreRejected(int $priority): void {
        $manager = $this->manager();
        $this->expectException(InvalidPriorityException::class);
        $this->expectExceptionMessage('Invalid process priority (' . $priority . ')');
        $manager->setPriority($priority);
    }

    public function testEmptyRunClosesResourcesOnceWithoutCallingPerForkCallbacks(): void {
        $manager = $this->manager();
        $calls = [];
        $manager->addCloseResourceCallback(function() use (&$calls): void { $calls[] = 'once'; }, ResourceCloseCallbackTriggerEnum::ONCE);
        $manager->addCloseResourceCallback(function() use (&$calls): void { $calls[] = 'second'; }, ResourceCloseCallbackTriggerEnum::ONCE);
        $manager->addCloseResourceCallback(function() use (&$calls): void { $calls[] = 'always'; });

        $manager->run();

        self::assertSame(['once', 'second'], $calls);
        $this->expectOutputString('');
    }

    private function get(MultiProcessor $manager, string $property): mixed {
        return (new \ReflectionProperty($manager, $property))->getValue($manager);
    }

    private function set(MultiProcessor $manager, string $property, mixed $value): void {
        (new \ReflectionProperty($manager, $property))->setValue($manager, $value);
    }

    private function invoke(MultiProcessor $manager, string $method, mixed ...$arguments): mixed {
        return (new \ReflectionMethod($manager, $method))->invoke($manager, ...$arguments);
    }

    public function testConstructorRegistersSignalsAndDefaultSettings(): void {
        pcntl_async_signals(false);
        $manager = new MultiProcessor(new ArrayIterator([]), new class extends ProcessorAbstract {
            public function process(): void {}
        });

        self::assertSame(posix_getpid(), $this->get($manager, 'parentPID'));
        self::assertTrue(pcntl_async_signals());
        foreach([SIGTERM, SIGINT, SIGCHLD] as $signal) {
            self::assertSame([$manager, 'signalHandler'], pcntl_signal_get_handler($signal));
        }
        self::assertSame([
            'priority' => 19,
            'concurrentChildren' => 1,
            'chunkSize' => 10,
            'stopOnParentFatal' => true,
            'stopOnChildFatal' => true,
            'stopOnFatalMethod' => StopOnFatalMethodEnum::GRACEFUL,
            'useProgressBar' => true,
        ], $this->get($manager, 'settings'));
    }

    #[TestWith([-20])]
    #[TestWith([0])]
    #[TestWith([19])]
    public function testSettingsCanBeChangedAndDefaultsRestored(int $priority): void {
        $manager = $this->manager();
        $manager->setPriority($priority);
        $manager->setMaximumConcurrentChildren(3);
        $manager->setChunkSize(7);
        $manager->stopOnParentFatal(false);
        $manager->stopOnChildFatal(false);
        $manager->stopOnFatalMethod(StopOnFatalMethodEnum::IMMEDIATE);
        self::assertSame([
            'priority' => $priority,
            'concurrentChildren' => 3,
            'chunkSize' => 7,
            'stopOnParentFatal' => false,
            'stopOnChildFatal' => false,
            'stopOnFatalMethod' => StopOnFatalMethodEnum::IMMEDIATE,
            'useProgressBar' => false,
        ], $this->get($manager, 'settings'));

        $manager->stopOnParentFatal();
        $manager->stopOnChildFatal();
        $manager->stopOnFatalMethod();
        $manager->useProgressBar();
        $settings = $this->get($manager, 'settings');
        self::assertTrue($settings['stopOnParentFatal']);
        self::assertTrue($settings['stopOnChildFatal']);
        self::assertTrue($settings['useProgressBar']);
        self::assertSame(StopOnFatalMethodEnum::GRACEFUL, $settings['stopOnFatalMethod']);
    }

    #[TestWith([ResourceCloseCallbackTriggerEnum::ONCE])]
    #[TestWith([ResourceCloseCallbackTriggerEnum::ALWAYS])]
    public function testResourceCallbacksRunInRegistrationOrderForTheirTrigger(ResourceCloseCallbackTriggerEnum $trigger): void {
        $manager = $this->manager();
        $calls = [];
        $manager->addCloseResourceCallback(function() use (&$calls): void { $calls[] = 'first'; }, $trigger);
        $manager->addCloseResourceCallback(function() use (&$calls): void { $calls[] = 'second'; }, $trigger);
        $other = $trigger === ResourceCloseCallbackTriggerEnum::ONCE
            ? ResourceCloseCallbackTriggerEnum::ALWAYS : ResourceCloseCallbackTriggerEnum::ONCE;
        $this->invoke($manager, 'closeAllResources', $other);
        self::assertSame([], $calls);
        $this->invoke($manager, 'closeAllResources', $trigger);
        self::assertSame(['first', 'second'], $calls);
    }

    #[TestWith([SIGCHLD, 'SIGCHLD'])]
    #[TestWith([SIGINT, 'SIGINT'])]
    #[TestWith([SIGQUIT, 'SIGQUIT'])]
    #[TestWith([SIGTERM, 'SIGTERM'])]
    public function testSignalNames(int $signal, string $name): void {
        self::assertSame($name, $this->invoke($this->manager(), 'getStringFromSignal', $signal));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testChildTerminationNotificationIsIgnoredInParentAndChild(bool $child): void {
        $manager = $this->manager();
        if($child) {
            $this->set($manager, 'parentPID', -1);
        }
        $manager->signalHandler(SIGCHLD, ['pid' => 123]);
        self::assertFalse($this->get($manager, 'stopProcessing'));
        self::assertSame([], $this->get($manager, 'childPIDs'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testUnknownSignalsAreRejectedInParentAndChild(bool $child): void {
        $manager = $this->manager();
        if($child) {
            $this->set($manager, 'parentPID', -1);
        }
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Unknown signal string, add to method (' . SIGUSR1 . ')');
        $manager->signalHandler(SIGUSR1, null);
    }

    #[TestWith([0, false, false])]
    #[TestWith([1, false, false])]
    #[TestWith([255, false, false])]
    #[TestWith([2, true, false])]
    #[TestWith([42, true, false])]
    #[TestWith([1, true, true])]
    #[TestWith([255, true, true])]
    public function testExitStatusesUpdateBookkeeping(int $exitCode, bool $stopOnFatal, bool $alreadyStopping): void {
        $manager = $this->manager();
        $manager->stopOnChildFatal($stopOnFatal);
        $this->set($manager, 'childPIDs', [123, 456]);
        $this->set($manager, 'currentRunningChildren', 2);
        $this->set($manager, 'chunksProcessed', 3);
        $this->set($manager, 'gracefulShutdown', $alreadyStopping);

        $this->invoke($manager, 'executeWaitForChildren', 123, $exitCode << 8);

        // Reaped children are always forgotten, so their (possibly reused) PIDs are never signalled
        self::assertSame([1 => 456], $this->get($manager, 'childPIDs'));
        self::assertSame(1, $this->get($manager, 'currentRunningChildren'));
        self::assertSame(4, $this->get($manager, 'chunksProcessed'));
        self::assertSame($alreadyStopping, $this->get($manager, 'gracefulShutdown'));
        $this->expectOutputString('');
    }

    #[TestWith([1])]
    #[TestWith([255])]
    public function testFatalChildStartsGracefulShutdownAndReportsCompletion(int $exitCode): void {
        $manager = $this->manager();
        $this->set($manager, 'childPIDs', [123]);
        $this->set($manager, 'currentRunningChildren', 1);
        $this->invoke($manager, 'executeWaitForChildren', 123, $exitCode << 8);
        self::assertTrue($this->get($manager, 'gracefulShutdown'));
        self::assertSame(0, $this->get($manager, 'currentRunningChildren'));
        self::assertSame(1, $this->get($manager, 'chunksProcessed'));
        $this->expectOutputString(PHP_EOL . 'A child fataled, shutting down 0 processes (currently running children will complete)' . PHP_EOL
            . PHP_EOL . 'Gracefully shut down because of a child process fatal' . PHP_EOL);
    }

    #[TestWith([SIGKILL])]
    #[TestWith([SIGSEGV])]
    public function testChildKilledBySignalIsAFatalThatStartsGracefulShutdown(int $signal): void {
        $manager = $this->manager();
        $this->set($manager, 'childPIDs', [123, 456]);
        $this->set($manager, 'currentRunningChildren', 2);
        $this->invoke($manager, 'executeWaitForChildren', 123, $signal);
        self::assertSame([1 => 456], $this->get($manager, 'childPIDs'));
        self::assertTrue($this->get($manager, 'childFailed'));
        self::assertTrue($this->get($manager, 'gracefulShutdown'));
        self::assertSame(1, $this->get($manager, 'currentRunningChildren'));
        self::assertSame(1, $this->get($manager, 'chunksProcessed'));
        $this->expectOutputString(PHP_EOL . 'A child was terminated by unhandled signal ' . $signal . ' (see the SIG* PHP constants)' . PHP_EOL
            . PHP_EOL . 'A child fataled, shutting down 1 processes (currently running children will complete)' . PHP_EOL);
    }

    public function testChildKilledBySignalIsAFailureEvenWithoutStopOnChildFatal(): void {
        $manager = $this->manager();
        $manager->stopOnChildFatal(false);
        $this->set($manager, 'childPIDs', [123]);
        $this->set($manager, 'currentRunningChildren', 1);
        $this->invoke($manager, 'executeWaitForChildren', 123, SIGKILL);
        self::assertTrue($this->get($manager, 'childFailed'));
        self::assertFalse($this->get($manager, 'gracefulShutdown'));
        $this->expectOutputString(PHP_EOL . 'A child was terminated by unhandled signal ' . SIGKILL . ' (see the SIG* PHP constants)' . PHP_EOL);
    }

    public function testUnknownChildPidIsIgnored(): void {
        $manager = $this->manager();
        $this->set($manager, 'childPIDs', [123, 456]);
        $this->set($manager, 'currentRunningChildren', 2);
        // A process we didn't fork (like a popen() process) never took up a slot or processed a chunk
        self::assertFalse($this->invoke($manager, 'executeWaitForChildren', 789, 1 << 8));
        self::assertSame([123, 456], $this->get($manager, 'childPIDs'));
        self::assertSame(2, $this->get($manager, 'currentRunningChildren'));
        self::assertSame(0, $this->get($manager, 'chunksProcessed'));
        self::assertFalse($this->get($manager, 'childFailed'));
        $this->expectOutputString('');
    }

    public function testWaitForNextSlotSkipsProcessesThatAreNotOurChildren(): void {
        // 999 is not one of our children: the parent keeps waiting until 101 frees up a slot before forking 102
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[999, 0], [101, 0], [102, 0]];'
            . '$manager->setChunkSize(2); $manager->setMaximumConcurrentChildren(1); $manager->run();'
            . 'echo (new \\ReflectionProperty($manager, "chunksProcessed"))->getValue($manager);');
        self::assertSame(0, $status, $output);
        self::assertSame('fork:101;priority:19:101;wait:999;wait:101;fork:102;priority:19:102;wait:102;wait:-1;2', $output);
    }

    public function testStoppedChildIsNotTreatedAsExited(): void {
        $manager = $this->manager();
        $this->set($manager, 'childPIDs', [123]);
        $this->set($manager, 'currentRunningChildren', 1);
        try {
            $this->invoke($manager, 'executeWaitForChildren', 123, (SIGSTOP << 8) | 0x7f);
            self::fail('A stopped child must raise an exception.');
        }
        catch(NonCleanExitException $exception) {
            self::assertSame('Non clean exit?', $exception->getMessage());
            self::assertSame(1, $this->get($manager, 'currentRunningChildren'));
            self::assertSame(0, $this->get($manager, 'chunksProcessed'));
            // A stopped child is still running, so it stays tracked
            self::assertSame([123], $this->get($manager, 'childPIDs'));
        }
    }

    public function testEmptyRunCanDisplayInitialProgress(): void {
        $manager = $this->manager();
        $manager->useProgressBar();
        $manager->run();
        $this->expectOutputString('Chunks                    Progress                           '
            . '   Elapsed  / Remaining' . "\r" . PHP_EOL . '0/0    '
            . ' [' . str_repeat(' ', 41) . ']   0.00%     00:00:00 / ??:??:??');
    }

    #[TestWith([2, 2, '50.00%', 3661, '01:01:01 / 01:01:01'])]
    #[TestWith([4, 0, '100.00%', 36610, '10:10:10 / 00:00:00'])]
    public function testProgressStatistics(int $completed, int $remaining, string $percentage, int $elapsed, string $times): void {
        $manager = $this->manager();
        $data = new ArrayIterator([1, 2, 3, 4]);
        $data->generateChunks(1);
        $this->set($manager, 'data', $data);
        $this->set($manager, 'chunksProcessed', $completed);
        $this->set($manager, 'timeStarted', time() - $elapsed);
        $statistics = $this->invoke($manager, 'getRunStatistics');
        self::assertStringStartsWith("\r" . $completed . '/4    ' . ' [' . str_repeat('=', $completed * 10) . '>' . str_repeat(' ', $remaining * 10) . ']', $statistics);
        self::assertStringContainsString($percentage, $statistics);
        $maximumElapsed = time() - $this->get($manager, 'timeStarted');
        $possibleTimes = [];
        for($seconds = $elapsed; $seconds <= $maximumElapsed; $seconds++) {
            $possibleTimes[] = gmdate('H:i:s', $seconds) . ' / '
                . gmdate('H:i:s', (int) ceil($seconds / $completed * $remaining));
        }
        self::assertContains(substr($statistics, -19), $possibleTimes);
        self::assertSame($times, $possibleTimes[0]);
    }

    /**
     * Process functions are replaced only inside this disposable PHP process. This
     * exercises fork failures and exit paths without killing PHPUnit or real PIDs.
     * Coverage is collected there too, since exit() never returns to the test runner.
     *
     * @return array{int, string}
     */
    private function subprocess(string $code, bool $simulateProcesses = true): array {
        $root = dirname(__DIR__);
        $bootstrap = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';';
        $coverage = CodeCoverage::instance();
        $coverageFile = null;
        $granularity = Granularity::Line;
        if($coverage->isActive()) {
            $coverageFile = tempnam(sys_get_temp_dir(), 'multiprocessor-coverage-');
            self::assertNotFalse($coverageFile);
            $granularity = $coverage->collectsPathCoverage() ? Granularity::LineBranchAndPath
                : ($coverage->collectsBranchCoverage() ? Granularity::LineAndBranch : Granularity::Line);
            $bootstrap .= 'require ' . var_export(__DIR__ . '/fixtures/subprocess-coverage.php', true) . ';';
        }
        $shims = $simulateProcesses ? <<<'PHP'
            namespace Jspeedz\MultiProcessor {
                function pcntl_async_signals(?bool $enable = null): bool { return false; }
                function pcntl_fork(): int {
                    $pid = array_shift($GLOBALS['forks']);
                    echo 'fork:' . $pid . ';';
                    return $pid;
                }
                function pcntl_setpriority(int $priority, int $pid): bool {
                    echo "priority:$priority:$pid;";
                    return true;
                }
                function pcntl_waitpid(int $pid, ?int &$status): int {
                    [$child, $status] = array_shift($GLOBALS['waits']) ?? [-1, 0];
                    echo "wait:$child;";
                    return $child;
                }
                function posix_kill(int $pid, int $signal): bool {
                    echo "kill:$pid:$signal;";
                    return true;
                }
            }
            PHP : '';
        $script = $shims . 'namespace {' . $bootstrap . <<<'PHP'
            $GLOBALS['forks'] = [];
            $GLOBALS['waits'] = [];
            $processor = new class extends \Jspeedz\MultiProcessor\Processor\ProcessorAbstract {
                public function initialize(): void {
                    echo 'initialize:' . json_encode(iterator_to_array($this->getData())) . ';';
                    echo 'parent-check:' . (int) ($this->parentAliveCheck !== null) . ';';
                }
                public function process(): void { echo 'process;'; }
                public function finish(): void { echo 'finish;'; }
                public function exit(): void { echo 'exit;'; parent::exit(); }
            };
            $manager = new \Jspeedz\MultiProcessor\MultiProcessor(
                new \Jspeedz\MultiProcessor\Iterator\ArrayIterator([10, 20, 30]), $processor);
            $manager->useProgressBar(false);
            PHP . $code . '}';
        try {
            $process = proc_open(
                [PHP_BINARY, '-r', $script, $coverageFile ?? '', $granularity->name, $root . '/src/MultiProcessor.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            if($coverageFile !== null) {
                $serialized = file_get_contents($coverageFile);
                self::assertNotFalse($serialized);
                self::assertNotSame('', $serialized, $output);
                $data = unserialize($serialized, ['allowed_classes' => [RawCodeCoverageData::class]]);
                self::assertInstanceOf(RawCodeCoverageData::class, $data);
                $coverage->codeCoverage()->append($data);
            }
            return [$status, $output];
        }
        finally {
            if($coverageFile !== null) {
                unlink($coverageFile);
            }
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testChildRunsLifecycleWithConfiguredParentCheck(bool $parentCheck): void {
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [0];'
            . '$manager->stopOnParentFatal(' . var_export($parentCheck, true) . ');'
            . '$manager->run(); echo "unreachable";');
        self::assertSame(0, $status, $output);
        self::assertSame('fork:0;initialize:[10,20,30];parent-check:' . (int) $parentCheck . ';process;finish;exit;', $output);
    }

    #[TestWith([1, 'wait:101;fork:102;priority:19:102;wait:102;fork:103;priority:19:103;wait:103;wait:-1;'])]
    #[TestWith([2, 'fork:102;priority:19:102;wait:101;fork:103;priority:19:103;wait:102;wait:103;wait:-1;'])]
    #[TestWith([8, 'fork:102;priority:19:102;fork:103;priority:19:103;wait:101;wait:102;wait:103;wait:-1;'])]
    public function testConcurrencyControlsWhenParentWaits(int $concurrency, string $expected): void {
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102, 103];'
            . '$GLOBALS["waits"] = [[101, 0], [102, 0], [103, 0]];'
            . '$manager->setChunkSize(1); $manager->setMaximumConcurrentChildren(' . $concurrency . '); $manager->run();');
        self::assertSame(0, $status, $output);
        self::assertSame('fork:101;priority:19:101;' . $expected, $output);
    }

    public function testRunClosesResourcesBeforeEachFork(): void {
        [$status, $output] = $this->subprocess(<<<'PHP'
            $GLOBALS['forks'] = [101, 102];
            $GLOBALS['waits'] = [[101, 0], [102, 0]];
            $manager->setChunkSize(2);
            $manager->setPriority(5);
            $manager->addCloseResourceCallback(function() { echo 'once;'; }, \Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum::ONCE);
            $manager->addCloseResourceCallback(function() { echo 'always;'; });
            $manager->run();
            PHP);
        self::assertSame(0, $status, $output);
        self::assertSame('once;always;fork:101;priority:5:101;wait:101;always;fork:102;priority:5:102;wait:102;wait:-1;', $output);
    }

    #[TestWith([1])]
    #[TestWith([255])]
    #[TestWith([42])]
    public function testNonzeroChildExitAllowsRemainingChunksButExitsParentWithFailure(int $exitCode): void {
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[101, ' . ($exitCode << 8) . '], [102, 0]];'
            . '$manager->stopOnChildFatal(false); $manager->setChunkSize(2); $manager->run(); echo "returned;";');
        self::assertSame(2, $status, $output);
        self::assertSame('fork:101;priority:19:101;wait:101;fork:102;priority:19:102;wait:102;wait:-1;', $output);
    }

    public function testGracefulShutdownDrainsPendingChildrenBeforeExiting(): void {
        [$status, $output] = $this->subprocess(<<<'PHP'
            $GLOBALS['forks'] = [101, 102];
            $GLOBALS['waits'] = [[101, 256], [102, 0]];
            $manager->setChunkSize(2);
            $manager->setMaximumConcurrentChildren(8);
            $manager->run();
            echo 'unreachable';
            PHP);
        self::assertSame(2, $status, $output);
        self::assertStringContainsString('shutting down 1 processes', $output);
        self::assertStringContainsString('wait:102;', $output);
        self::assertStringEndsWith('wait:-1;', $output);
        self::assertStringContainsString('Gracefully shut down because of a child process fatal', $output);
        self::assertStringNotContainsString('unreachable', $output);
    }

    public function testGracefulShutdownWaitsEvenBelowConcurrencyLimit(): void {
        [$status, $output] = $this->subprocess(<<<'PHP'
            $GLOBALS['forks'] = [101];
            $GLOBALS['waits'] = [[101, 0]];
            $manager->setMaximumConcurrentChildren(8);
            (new \ReflectionProperty($manager, 'gracefulShutdown'))->setValue($manager, true);
            $manager->run();
            echo 'unreachable';
            PHP);
        self::assertSame(2, $status, $output);
        self::assertSame('fork:101;priority:19:101;wait:101;' . PHP_EOL
            . 'Gracefully shut down because of a child process fatal' . PHP_EOL, $output);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testWaitWithNoChildrenDoesNotChangeCounters(bool $finalChildren): void {
        [$status, $output] = $this->subprocess(
            '(new \ReflectionMethod($manager, "waitForChildren"))->invoke($manager, '
            . var_export($finalChildren, true) . ');'
            . 'echo (new \ReflectionProperty($manager, "chunksProcessed"))->getValue($manager);');
        self::assertSame(0, $status, $output);
        self::assertSame('wait:-1;0', $output);
    }

    public function testForkFailureRaisesProcessException(): void {
        [$status, $output] = $this->subprocess(<<<'PHP'
            $GLOBALS['forks'] = [-1];
            try { $manager->run(); }
            catch(\Jspeedz\MultiProcessor\Exception\ProcessException $e) { echo $e->getMessage(); exit(23); }
            PHP);
        self::assertSame(23, $status, $output);
        self::assertSame('fork:-1;Could not fork', $output);
    }

    #[TestWith([1, 1])]
    #[TestWith([1, 8])]
    #[TestWith([255, 1])]
    #[TestWith([255, 8])]
    public function testGracefulFatalExitsParentWithFailure(int $exitCode, int $concurrency): void {
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101];'
            . '$GLOBALS["waits"] = [[101, ' . ($exitCode << 8) . ']];'
            . '$manager->setMaximumConcurrentChildren(' . $concurrency . ');'
            . '$manager->useProgressBar(); $manager->run(); echo "unreachable";');
        self::assertSame(2, $status, $output);
        self::assertStringContainsString('currently running children will complete', $output);
        self::assertStringContainsString('Gracefully shut down because of a child process fatal', $output);
        self::assertStringNotContainsString('unreachable', $output);
        self::assertStringContainsString('100.00%', $output);
    }

    #[TestWith([StopOnFatalMethodEnum::NORMAL, SIGTERM])]
    #[TestWith([StopOnFatalMethodEnum::IMMEDIATE, SIGKILL])]
    public function testFatalShutdownSignalsAllTrackedChildren(StopOnFatalMethodEnum $method, int $signal): void {
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[101, 256], [102, ' . $signal . ']];'
            . '$manager->setChunkSize(2); $manager->setMaximumConcurrentChildren(2);'
            . '$manager->stopOnFatalMethod(\Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum::' . $method->name . ');'
            . '$manager->run(); echo "unreachable";');
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('currently running children will be terminated', $output);
        // 101 already exited and was reaped: only the still running 102 is signalled
        // ... and reaped before the parent exits
        self::assertStringEndsWith("kill:102:$signal;wait:102;", $output);
        self::assertStringNotContainsString("kill:101:", $output);
        self::assertStringNotContainsString('unreachable', $output);
    }

    #[TestWith([SIGTERM, false])]
    #[TestWith([SIGQUIT, false])]
    #[TestWith([SIGINT, false])]
    #[TestWith([SIGTERM, true])]
    #[TestWith([SIGQUIT, true])]
    #[TestWith([SIGINT, true])]
    public function testTerminationSignalsExitParentOrChild(int $signal, bool $child): void {
        [$status, $output] = $this->subprocess(
            '(new \ReflectionProperty($manager, "childPIDs"))->setValue($manager, [101, 102]);'
            . ($child ? '(new \ReflectionProperty($manager, "parentPID"))->setValue($manager, -1);' : '')
            . '$manager->signalHandler(' . $signal . ', null); echo "unreachable";');
        self::assertSame($child ? 0 : 1, $status, $output);
        self::assertSame($child ? '' : 'kill:101:' . SIGKILL . ';1kill:102:' . SIGKILL . ';1', $output);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testChildKilledBySignalMakesParentExitWithFailure(bool $stopOnChildFatal): void {
        // Even when an error handler would swallow errors, the parent must not exit 0
        [$status, $output] = $this->subprocess('set_error_handler(static fn(): bool => true);'
            . '$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[101, ' . SIGKILL . '], [102, 0]];'
            . '$manager->setChunkSize(2); $manager->setMaximumConcurrentChildren(1);'
            . '$manager->stopOnChildFatal(' . var_export($stopOnChildFatal, true) . ');'
            . '$manager->run(); echo "unreachable";');
        self::assertSame(2, $status, $output);
        self::assertStringContainsString('terminated by unhandled signal ' . SIGKILL, $output);
        self::assertStringNotContainsString('unreachable', $output);
        if($stopOnChildFatal) {
            // Graceful shutdown: no more children are forked after the crash
            self::assertStringNotContainsString('fork:102;', $output);
        }
        else {
            self::assertStringContainsString('fork:102;', $output);
        }
    }

    public function testChildKilledBySignalTerminatesSiblingsWithImmediateShutdown(): void {
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[101, ' . SIGSEGV . '], [102, ' . SIGKILL . ']];'
            . '$manager->setChunkSize(2); $manager->setMaximumConcurrentChildren(2);'
            . '$manager->stopOnFatalMethod(\Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum::IMMEDIATE);'
            . '$manager->run(); echo "unreachable";');
        self::assertSame(1, $status, $output);
        self::assertStringEndsWith('kill:102:' . SIGKILL . ';wait:102;', $output);
        self::assertStringNotContainsString('kill:101:', $output);
    }

    public function testStopProcessingPreventsForking(): void {
        $manager = $this->manager();
        $this->set($manager, 'data', new ArrayIterator([1]));
        $this->set($manager, 'stopProcessing', true);
        $manager->run();
        self::assertSame(0, $this->get($manager, 'chunksProcessed'));
        self::assertSame([], $this->get($manager, 'childPIDs'));
    }

    public function testNonIterableChunksAreRejected(): void {
        $manager = $this->manager();
        $data = new class([1]) extends ArrayIterator {
            public function generateChunks(int $chunkSize): void {}
        };
        $this->set($manager, 'data', $data);
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Each data chunk must be iterable');
        $manager->run();
    }

    public function testRealForkCompletesAndDisplaysProgress(): void {
        [$status, $output] = $this->subprocess('$manager->useProgressBar(); $manager->run(); echo "returned;";', false);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Chunks', $output);
        self::assertStringContainsString('initialize:[10,20,30];parent-check:1;process;finish;exit;', $output);
        self::assertStringContainsString('100.00%', $output);
        self::assertStringEndsWith(PHP_EOL . 'returned;', $output);
    }
}
