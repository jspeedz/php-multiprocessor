<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Exception\NonCleanExitException;
use Jspeedz\MultiProcessor\Exception\UnsupportedException;
use Jspeedz\MultiProcessor\Exception\InvalidGracePeriodException;
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

    #[TestWith([-0.001, '-0.001'])]
    #[TestWith([INF, 'INF'])]
    #[TestWith([NAN, 'NAN'])]
    public function testInvalidTerminateGracePeriodsAreRejected(float $seconds, string $shown): void {
        $manager = $this->manager();
        $this->expectException(InvalidGracePeriodException::class);
        $this->expectExceptionMessage('Invalid terminate grace period (' . $shown . '), must be 0 seconds or more');
        $manager->setTerminateGracePeriod($seconds);
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

    public function testConstructorSetsDefaultSettingsWithoutRegisteringSignals(): void {
        pcntl_async_signals(false);
        foreach([SIGTERM, SIGINT, SIGCHLD] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        $manager = new MultiProcessor(new ArrayIterator([]), new class extends ProcessorAbstract {
            public function process(): void {}
        });

        self::assertSame(posix_getpid(), $this->get($manager, 'parentPID'));
        self::assertFalse(pcntl_async_signals());
        foreach([SIGTERM, SIGINT, SIGCHLD] as $signal) {
            self::assertSame(SIG_DFL, pcntl_signal_get_handler($signal));
        }
        self::assertSame([
            'priority' => 19,
            'concurrentChildren' => 1,
            'chunkSize' => 10,
            'stopOnParentFatal' => true,
            'stopOnChildFatal' => true,
            'stopOnFatalMethod' => StopOnFatalMethodEnum::GRACEFUL,
            'terminateGracePeriod' => 10.0,
            'useProgressBar' => true,
        ], $this->get($manager, 'settings'));
    }

    public function testRunRegistersSignalsForTheRunningProcess(): void {
        pcntl_async_signals(false);
        $manager = new MultiProcessor(new ArrayIterator([]), new class extends ProcessorAbstract {
            public function process(): void {}
        });
        $manager->useProgressBar(false);
        $this->set($manager, 'parentPID', -1);

        $manager->run();

        self::assertSame(posix_getpid(), $this->get($manager, 'parentPID'));
        self::assertTrue(pcntl_async_signals());
        foreach([SIGTERM, SIGINT, SIGCHLD] as $signal) {
            self::assertSame([$manager, 'signalHandler'], pcntl_signal_get_handler($signal));
        }
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
        $manager->setTerminateGracePeriod(2.5);
        self::assertSame([
            'priority' => $priority,
            'concurrentChildren' => 3,
            'chunkSize' => 7,
            'stopOnParentFatal' => false,
            'stopOnChildFatal' => false,
            'stopOnFatalMethod' => StopOnFatalMethodEnum::IMMEDIATE,
            'terminateGracePeriod' => 2.5,
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

    public function testWaitForNextSlotSkipsProcessesThatAreNotOurChildren(): void {
        // 999 is not one of our children: it is never reaped (the shim refuses waiting for any child),
        // the parent keeps waiting until 101 frees up a slot before forking 102
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[999, 0], [101, 0], [102, 0]];'
            . '$manager->setChunkSize(2); $manager->setMaximumConcurrentChildren(1); $manager->run();'
            . 'echo (new \\ReflectionProperty($manager, "chunksProcessed"))->getValue($manager);');
        self::assertSame(0, $status, $output);
        self::assertSame('fork:101;priority:19:101;sleep;wait:101;fork:102;priority:19:102;wait:102;2', $output);
    }

    public function testProcessesNotForkedByTheManagerAreLeftForTheirOwner(): void {
        // A popen() process that exits while the manager waits for its children keeps its exit status for pclose()
        [$status, $output] = $this->subprocess('$process = popen("exit 3", "r"); usleep(200_000);'
            . '$manager->run(); echo "pclose:" . pclose($process);', false);
        self::assertSame(0, $status, $output);
        self::assertStringEndsWith('pclose:3', $output);
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
            namespace Jspeedz\MultiProcessor\Callback\Iterator {
                // A simulated fork runs the "child" in the parent process itself, which is therefore its own parent
                function posix_getppid(): int { return \posix_getpid(); }
            }
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
                // $GLOBALS['waits'] lists [pid, status, (errno)] in the order children exit. A child only exits
                // once it is at the head of that list, unless it is waited for while blocking, or SIGKILLed.
                function pcntl_waitpid(int $pid, ?int &$status, int $flags = 0): int {
                    if($pid <= 0) {
                        throw new \LogicException("pcntl_waitpid($pid) would reap processes that are not ours");
                    }
                    $key = null;
                    $head = $GLOBALS['waits'][0] ?? null;
                    if(isset($GLOBALS['onWait'])) {
                        ($GLOBALS['onWait'])($pid);
                    }
                    if($head !== null && in_array($head[0], [$pid, -1], true)) {
                        $key = 0;
                    }
                    else if(!($flags & WNOHANG) || isset($GLOBALS['killed'][$pid])) {
                        foreach($GLOBALS['waits'] as $index => $wait) {
                            if($wait[0] === $pid) {
                                $key = $index;
                                break;
                            }
                        }
                    }
                    if($key === null) {
                        if($flags & WNOHANG) {
                            $GLOBALS['lastError'] = 0;
                            return 0;
                        }
                        $GLOBALS['lastError'] = PCNTL_ECHILD;
                        echo "wait:-1;";
                        return -1;
                    }
                    [$child, $status, $GLOBALS['lastError']] = $GLOBALS['waits'][$key] + [2 => 0];
                    array_splice($GLOBALS['waits'], $key, 1);
                    echo "wait:$child;";
                    return $child;
                }
                function pcntl_get_last_error(): int { return $GLOBALS['lastError'] ?? 0; }
                // Every sleep reports "a process we didn't fork exited", so the loop falls back to polling
                // $GLOBALS['waitable'] is the PID reported as exited (0: none yet). A blocking wait reports block;
                function pcntl_waitid(int $idtype, ?int $id, mixed &$info = [], int $flags = 0): bool {
                    $GLOBALS['sleeps'] = ($GLOBALS['sleeps'] ?? 0) + 1;
                    if($GLOBALS['sleeps'] > 20) {
                        throw new \LogicException('Waiting forever for children that never exit');
                    }
                    echo ($flags & WNOHANG) ? 'sleep;' : 'block;';
                    if(($GLOBALS['waits'][0][0] ?? null) === 999) {
                        // 999 is a process we didn't fork (like popen()), its owner reaps it
                        array_shift($GLOBALS['waits']);
                    }
                    $info = ['pid' => ($flags & WNOHANG) ? ($GLOBALS['waitable'] ?? 999) : 999];
                    return true;
                }
                function usleep(int $microseconds): void {
                    $GLOBALS['usleeps'][] = $microseconds;
                    if(isset($GLOBALS['onSleep'])) {
                        ($GLOBALS['onSleep'])();
                    }
                }
                // SIGKILL can't be caught: the child becomes reapable right away, unless listed in $GLOBALS['stuck']
                function posix_kill(int $pid, int $signal): bool {
                    echo "kill:$pid:$signal;";
                    if($signal === SIGKILL && !in_array($pid, $GLOBALS['stuck'] ?? [], true)) {
                        $GLOBALS['killed'][$pid] = true;
                    }
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

    #[TestWith([1, 'wait:101;fork:102;priority:19:102;wait:102;fork:103;priority:19:103;wait:103;'])]
    #[TestWith([2, 'fork:102;priority:19:102;wait:101;wait:102;fork:103;priority:19:103;wait:103;'])]
    #[TestWith([8, 'fork:102;priority:19:102;fork:103;priority:19:103;wait:101;wait:102;wait:103;'])]
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
        self::assertSame('once;always;fork:101;priority:5:101;wait:101;always;fork:102;priority:5:102;wait:102;', $output);
    }

    #[TestWith([1])]
    #[TestWith([255])]
    #[TestWith([42])]
    public function testNonzeroChildExitAllowsRemainingChunksButExitsParentWithFailure(int $exitCode): void {
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[101, ' . ($exitCode << 8) . '], [102, 0]];'
            . '$manager->stopOnChildFatal(false); $manager->setChunkSize(2); $manager->run(); echo "returned;";');
        self::assertSame(2, $status, $output);
        self::assertSame('fork:101;priority:19:101;wait:101;fork:102;priority:19:102;wait:102;', $output);
    }

    #[TestWith([SIGTERM])]
    #[TestWith([SIGQUIT])]
    #[TestWith([SIGINT])]
    public function testChildInterruptedBySignalFailsTheRunWithoutStoppingOtherChildren(int $signal): void {
        // A child that handled a termination signal exits with 128 + signal: its chunk failed, but it is no child fatal
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[101, ' . ((128 + $signal) << 8) . '], [102, 0]];'
            . '$manager->setChunkSize(2); $manager->setMaximumConcurrentChildren(1); $manager->run(); echo "returned;";');
        self::assertSame(2, $status, $output);
        self::assertStringContainsString('wait:101;fork:102;', $output);
        self::assertStringContainsString('wait:102;', $output);
        self::assertStringNotContainsString('shutting down', $output);
        self::assertStringNotContainsString('returned;', $output);
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
        self::assertSame('0', $output);
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
        // ... and reaped before the parent exits (SIGTERM gets a grace period, so it is polled)
        self::assertStringEndsWith("kill:102:$signal;wait:102;", $output);
        self::assertStringNotContainsString("kill:101:", $output);
        self::assertStringNotContainsString('unreachable', $output);
    }

    public function testNormalFatalShutdownKillsChildrenThatOutliveTheGracePeriod(): void {
        // 102 is still running (0) when the grace period is over, so it is killed with SIGKILL and then reaped
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[101, 256], [999, 0], [102, ' . SIGKILL . ']];'
            . '$manager->setTerminateGracePeriod(0.0);'
            . '$manager->setChunkSize(2); $manager->setMaximumConcurrentChildren(2);'
            . '$manager->stopOnFatalMethod(\\Jspeedz\\MultiProcessor\\Enum\\StopOnFatalMethodEnum::NORMAL);'
            . '$manager->run(); echo "unreachable";');
        self::assertSame(1, $status, $output);
        self::assertStringEndsWith('kill:102:' . SIGTERM . ';kill:102:' . SIGKILL . ';wait:102;', $output);
    }

    public function testChildrenReapedDuringTheGracePeriodAreNeverSignalledAgain(): void {
        // 102 exits on SIGTERM and is reaped in the grace period; then CTRL+C kills the children that are left:
        // only 103, as the kernel may have handed PID 102 to an unrelated process already.
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102, 103];'
            . '$GLOBALS["waits"] = [[101, 256], [102, 0], [999, 0], [103, ' . SIGKILL . ']];'
            . '$GLOBALS["onSleep"] = function() use ($manager): void {'
            . '  if(!in_array(102, (new \\ReflectionProperty($manager, "childPIDs"))->getValue($manager), true)) {'
            . '    unset($GLOBALS["onSleep"]); echo "SIGINT;"; $manager->signalHandler(SIGINT, null);'
            . '  }'
            . '};'
            . '$manager->setChunkSize(1); $manager->setMaximumConcurrentChildren(3);'
            . '$manager->stopOnFatalMethod(\\Jspeedz\\MultiProcessor\\Enum\\StopOnFatalMethodEnum::NORMAL);'
            . '$manager->run(); echo "unreachable";');
        self::assertSame(1, $status, $output);
        self::assertStringEndsWith('kill:102:' . SIGTERM . ';kill:103:' . SIGTERM . ';wait:102;SIGINT;kill:103:' . SIGKILL . ';wait:103;', $output);
    }

    public function testTerminationSignalsAreBlockedWhileReapingAChild(): void {
        // A termination signal handled between reaping a child and forgetting it would signal its (reused) PID.
        // Afterwards, the signal mask is restored as it was: the test runner may have blocked signals already.
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101];'
            . '$GLOBALS["waits"] = [[101, 0]];'
            . '\\pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $before);'
            . '$GLOBALS["onWait"] = function(): void {'
            . '  \\pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $mask);'
            . '  echo "blocked:" . (int) (array_diff([SIGTERM, SIGQUIT, SIGINT], $mask) === []) . ";";'
            . '};'
            . '$manager->run();'
            . '\\pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $after);'
            . '$terminationSignals = static fn(array $mask): array => array_values(array_intersect([SIGTERM, SIGQUIT, SIGINT], $mask));'
            . 'echo "after:" . (int) ($terminationSignals($before) === $terminationSignals($after));');
        self::assertSame(0, $status, $output);
        self::assertSame('fork:101;priority:19:101;blocked:1;wait:101;after:1', $output);
    }

    public function testParentGivesUpOnChildrenThatSurviveSigkill(): void {
        // 102 is stuck in an uninterruptible system call (dead NFS mount): SIGKILL can't end it, don't wait forever
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102];'
            . '$GLOBALS["waits"] = [[101, 256], [999, 0], [102, ' . SIGKILL . ']];'
            . '$GLOBALS["stuck"] = [102];'
            . '(new \\ReflectionProperty($manager, "killReapTimeout"))->setValue($manager, 0.0);'
            . '$manager->setChunkSize(2); $manager->setMaximumConcurrentChildren(2);'
            . '$manager->stopOnFatalMethod(\\Jspeedz\\MultiProcessor\\Enum\\StopOnFatalMethodEnum::IMMEDIATE);'
            . '$manager->run(); echo "unreachable";');
        self::assertSame(1, $status, $output);
        self::assertStringEndsWith('kill:102:' . SIGKILL . ';' . PHP_EOL
            . 'Gave up waiting for killed child processes 102 (stuck in an uninterruptible system call?)' . PHP_EOL, $output);
    }

    #[TestWith([0, false, 'sleep;block;', []])]
    #[TestWith([101, false, 'sleep;', []])]
    #[TestWith([999, false, 'sleep;', [1_000_000]])]
    #[TestWith([999, true, 'sleep;', []])]
    public function testSleepingUntilAChildExits(int $waitable, bool $childSignalled, string $expected, array $usleeps): void {
        // Only when a process we didn't fork stays waitable, sleep until SIGCHLD instead of waiting (which wouldn't block)
        [$status, $output] = $this->subprocess('$GLOBALS["waitable"] = ' . $waitable . ';'
            . '(new \\ReflectionProperty($manager, "childPIDs"))->setValue($manager, [101]);'
            . ($childSignalled ? '$manager->signalHandler(SIGCHLD, null);' : '')
            . '(new \\ReflectionMethod($manager, "sleepUntilAChildExits"))->invoke($manager, 0);'
            . 'echo json_encode($GLOBALS["usleeps"] ?? []);');
        self::assertSame(0, $status, $output);
        self::assertSame($expected . json_encode($usleeps), $output);
    }

    public function testInterruptedWaitIsRetried(): void {
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101];'
            . '$GLOBALS["waits"] = [[-1, 0, PCNTL_EINTR], [101, 0]];'
            . '$manager->run(); echo "done";');
        self::assertSame(0, $status, $output);
        self::assertSame('fork:101;priority:19:101;wait:-1;sleep;wait:101;done', $output);
    }

    #[TestWith([1, 'fork:101;priority:19:101;wait:-1;Lost track of child process 101'])]
    #[TestWith([2, 'fork:101;priority:19:101;fork:102;priority:19:102;wait:101;wait:-1;Lost track of child process 102'])]
    public function testLosingTrackOfRunningChildrenThrows(int $concurrency, string $expected): void {
        // waitpid() fails while children are still tracked (reaped elsewhere): don't fork past the limit or exit 0
        [$status, $output] = $this->subprocess('$GLOBALS["forks"] = [101, 102, 103];'
            . '$GLOBALS["waits"] = [' . ($concurrency === 2 ? '[101, 0], ' : '') . '[-1, 0, PCNTL_ECHILD]];'
            . '$manager->setChunkSize(1); $manager->setMaximumConcurrentChildren(' . $concurrency . ');'
            . 'try { $manager->run(); } catch(\\Jspeedz\\MultiProcessor\\Exception\\ProcessException $e) { echo $e->getMessage(); }');
        self::assertSame(0, $status, $output);
        self::assertSame($expected . ': it was reaped elsewhere', $output);
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
            . '$GLOBALS["waits"] = [[101, ' . SIGKILL . '], [102, ' . SIGKILL . ']];'
            . ($child ? '(new \ReflectionProperty($manager, "parentPID"))->setValue($manager, -1);' : '')
            . '$manager->signalHandler(' . $signal . ', null); echo "unreachable";');
        self::assertSame($child ? 128 + $signal : 1, $status, $output);
        // The parent kills and reaps its children
        self::assertSame($child ? '' : 'kill:101:' . SIGKILL . ';kill:102:' . SIGKILL . ';wait:101;wait:102;', $output);
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

    public function testRealForkChildInterruptedBySigtermFailsTheRunWithoutStoppingOtherChildren(): void {
        // A real SIGTERM reaches the child's handler mid-chunk: the parent reaps 128 + SIGTERM, which fails the run
        // (exit 2) but is no child fatal, so the next chunk is still processed
        [$status, $output] = $this->subprocess(<<<'PHP'
            $manager = new \Jspeedz\MultiProcessor\MultiProcessor(
                new \Jspeedz\MultiProcessor\Iterator\ArrayIterator([1, 2]),
                new class extends \Jspeedz\MultiProcessor\Processor\ProcessorAbstract {
                    public function process(): void {
                        foreach($this->getData() as $item) {
                            if($item === 1) {
                                register_shutdown_function(static fn() => print('child-shutdown;'));
                                posix_kill(posix_getpid(), SIGTERM);
                                for($tries = 0; $tries < 500; $tries++) {
                                    // The handler runs asynchronously, but not necessarily before posix_kill() returns
                                    usleep(10_000);
                                }
                                echo 'survived;';
                            }
                            echo "item:$item;";
                        }
                    }
                },
            );
            $manager->useProgressBar(false);
            $manager->setChunkSize(1);
            $manager->setMaximumConcurrentChildren(1);
            $manager->run();
            echo 'unreachable';
            PHP, false);
        self::assertSame(2, $status, $output);
        // Shutdown functions still run, as the handler exits normally instead of dying by the signal
        self::assertMatchesRegularExpression('/child-shutdown;.*item:2;$/s', $output);
        self::assertStringNotContainsString('survived;', $output);
        self::assertStringNotContainsString('item:1;', $output);
        self::assertStringNotContainsString('A child fataled', $output);
    }

    public function testRealForkRunFromAProcessForkedAfterConstructionKeepsItsChildrenAlive(): void {
        // The children's parent-alive check must compare against the process that forked them, not the constructing one
        [$status, $output] = $this->subprocess(<<<'PHP'
            $manager = new \Jspeedz\MultiProcessor\MultiProcessor(
                new \Jspeedz\MultiProcessor\Iterator\ArrayIterator([10, 20, 30]),
                new class extends \Jspeedz\MultiProcessor\Processor\ProcessorAbstract {
                    public function process(): void {
                        foreach($this->getData() as $item) {
                            echo "item:$item;";
                        }
                    }
                },
            );
            $manager->useProgressBar(false);
            $manager->setChunkSize(3);
            $worker = pcntl_fork();
            if($worker === 0) {
                $manager->run();
                exit(0);
            }
            pcntl_waitpid($worker, $workerStatus);
            echo 'worker:' . pcntl_wexitstatus($workerStatus) . ';';
            PHP, false);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('item:10;item:20;item:30;', $output);
        self::assertStringEndsWith('worker:0;', $output);
    }
}
