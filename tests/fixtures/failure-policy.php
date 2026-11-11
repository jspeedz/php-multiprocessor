<?php

namespace Jspeedz\MultiProcessor {
    // Observe real syscalls. No successful fork, exit status, or signal is faked.
    function pcntl_fork(): int {
        $chunk = $GLOBALS['nextChunk']++;
        $pid = \pcntl_fork();
        if($pid > 0) {
            $GLOBALS['children'][$pid] = $chunk;
            \policyEvent('spawned', ['childPID' => $pid, 'chunk' => $chunk]);
        }
        return $pid;
    }

    function pcntl_waitpid(int $pid, ?int &$status, int $flags = 0): int {
        $result = \pcntl_waitpid($pid, $status, $flags);
        if($result > 0) {
            $GLOBALS['reaped'][$result] = true;
            if(count($GLOBALS['config']['failChunks']) > 1
                && in_array($GLOBALS['children'][$result], $GLOBALS['config']['failChunks'], true)) {
                // For the simultaneous-failure cases, both failures must be
                // waitable before the manager detects either. WNOWAIT preserves
                // the other child's real status for the manager to reap itself.
                foreach($GLOBALS['children'] as $other => $chunk) {
                    if(!isset($GLOBALS['reaped'][$other]) && in_array($chunk, $GLOBALS['config']['failChunks'], true)) {
                        \policyWait(static function() use ($other): bool {
                            return \pcntl_waitid(P_PID, $other, $info, WEXITED | WNOWAIT | WNOHANG)
                                && ($info['pid'] ?? 0) === $other;
                        });
                    }
                }
            }
            \policyEvent('reaped', [
                'childPID' => $result, 'chunk' => $GLOBALS['children'][$result],
                'exitCode' => \pcntl_wifexited($status) ? \pcntl_wexitstatus($status) : null,
                'signal' => \pcntl_wifsignaled($status) ? \pcntl_wtermsig($status) : null,
            ]);
        }
        return $result;
    }

    function posix_kill(int $pid, int $signal): bool {
        \policyEvent('signal', ['childPID' => $pid, 'signal' => $signal]);
        return \posix_kill($pid, $signal);
    }
}

namespace {
    use Jspeedz\MultiProcessor\Callback\Close\StreamResources;
    use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
    use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
    use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
    use Jspeedz\MultiProcessor\MultiProcessor;
    use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
    use Jspeedz\MultiProcessor\Tests\Support\ProcessScenario;

    require __DIR__ . '/../../vendor/autoload.php';
    $config = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    $nextChunk = 0;
    $children = $reaped = [];
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');

    function policyEvent(string $event, array $details = []): void {
        $line = json_encode(['event' => $event, 'pid' => getmypid(), 'time' => hrtime(true), ...$details], JSON_THROW_ON_ERROR) . "\n";
        if(file_put_contents($GLOBALS['config']['log'], $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('Could not record policy event.');
        }
    }

    function policyWait(Closure $condition): void {
        $deadline = hrtime(true) + 15_000_000_000;
        while(!$condition()) {
            if(hrtime(true) >= $deadline) {
                policyEvent('timeout');
                exit(43);
            }
            usleep(1000);
        }
    }

    if(posix_setsid() === -1) {
        throw new RuntimeException('Could not isolate fixture process group.');
    }
    policyEvent('session');
    $parentPID = getmypid();
    register_shutdown_function(static function() use ($parentPID): void {
        if(getmypid() === $parentPID) {
            policyEvent('parent-exit');
        }
    });

    $processor = new class($config) extends ProcessorAbstract {
        private int $chunk;

        public function __construct(private array $config) {}

        private function inWave(): bool {
            return $this->chunk >= $this->config['waveStart'] && $this->chunk <= $this->config['waveEnd'];
        }

        private function failAt(string $stage): void {
            if(!in_array($this->chunk, $this->config['failChunks'], true) || $stage !== $this->config['stage']) {
                return;
            }
            policyEvent('failure-ready', ['chunk' => $this->chunk]);
            policyWait(function(): bool {
                $events = ProcessScenario::events($this->config['log']);
                $ready = array_filter($events, fn(array $event): bool =>
                    in_array($event['event'], ['held', 'failure-ready'], true));
                $earlier = array_filter($events, fn(array $event): bool =>
                    $event['event'] === 'reaped' && $event['chunk'] < $this->config['waveStart']);
                return count($ready) === $this->config['waveEnd'] - $this->config['waveStart'] + 1
                    && count($earlier) === $this->config['waveStart'];
            });
            policyEvent('failure', ['chunk' => $this->chunk, 'stage' => $stage]);
            if(in_array($this->config['failure'], ['sigkill', 'external-sigkill'], true)) {
                if($this->config['failure'] === 'external-sigkill') {
                    policyEvent('kill-requested', ['chunk' => $this->chunk]);
                }
                else {
                    \posix_kill(getmypid(), SIGKILL);
                }
                policyWait(static fn(): bool => false);
            }
            if($this->config['failure'] === 'exit1') {
                exit(1);
            }
            if($this->config['failure'] === 'exit42') {
                exit(42);
            }
            if($this->config['failure'] === 'exception') {
                throw new RuntimeException('Intentional lifecycle failure.');
            }
            multiprocessor_policy_undefined_function();
        }

        public function initialize(): void {
            $items = iterator_to_array($this->getData(), false);
            $this->chunk = intdiv($items[0], $this->config['size']);
            policyEvent('start', ['chunk' => $this->chunk]);
            $handler = pcntl_signal_get_handler(SIGTERM);
            pcntl_signal(SIGTERM, function(int $signal, mixed $info) use ($handler): void {
                policyEvent('term-received', ['chunk' => $this->chunk]);
                if(!$this->config['ignoreTerm']) {
                    $handler($signal, $info);
                }
            });
            $this->failAt('initialize');
            policyEvent('initialized', ['chunk' => $this->chunk]);
        }

        public function process(): void {
            $this->failAt('process');
            $completed = 0;
            foreach($this->getData() as $item) {
                if($completed === 2) {
                    $this->failAt('mid-process');
                }
                policyEvent('processed', ['chunk' => $this->chunk, 'item' => $item]);
                $completed++;
                if($completed === 1 && $this->inWave() && !in_array($this->chunk, $this->config['failChunks'], true)) {
                    policyEvent('held', ['chunk' => $this->chunk]);
                    policyWait(fn(): bool => is_file($this->config['log'] . '.release'));
                    policyEvent('released', ['chunk' => $this->chunk]);
                }
            }
        }

        public function finish(): void {
            $this->failAt('finish');
            policyEvent('finish', ['chunk' => $this->chunk]);
        }

        public function exit(): void {
            $this->failAt('exit');
            policyEvent('exit', ['chunk' => $this->chunk]);
            parent::exit();
        }
    };
    $manager = new MultiProcessor(new ArrayIterator(range(0, $config['count'] - 1)), $processor);
    $manager->setChunkSize($config['size']);
    $manager->setMaximumConcurrentChildren($config['concurrency']);
    $manager->stopOnChildFatal($config['stop']);
    $manager->stopOnParentFatal($config['parentCheck']);
    $manager->stopOnFatalMethod(StopOnFatalMethodEnum::from($config['method']));
    $manager->useProgressBar($config['progress']);
    $manager->setPriority($config['priority']);
    $manager->setTerminateGracePeriod($config['grace']);
    $manager->addCloseResourceCallback(static function(): void {
        policyEvent('close-once');
        (new StreamResources)->getCallback()();
    }, ResourceCloseCallbackTriggerEnum::ONCE);
    $manager->addCloseResourceCallback(static function(): void { policyEvent('close-always'); });
    $manager->run();
    policyEvent('unexpected-return');
}
