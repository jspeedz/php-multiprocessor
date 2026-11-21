<?php

namespace Jspeedz\MultiProcessor {
    // Observe real OS calls without replacing their behavior or returned statuses.
    function pcntl_fork(): int {
        $pid = \pcntl_fork();
        if($pid > 0) {
            \recordGracefulEvent('spawned', ['childPID' => $pid]);
        }
        return $pid;
    }

    function pcntl_waitpid(int $pid, ?int &$status, int $flags = 0): int {
        $result = \pcntl_waitpid($pid, $status, $flags);
        if($result > 0) {
            \recordGracefulEvent('reaped', [
                'childPID' => $result,
                'exitCode' => \pcntl_wifexited($status) ? \pcntl_wexitstatus($status) : null,
                'signal' => \pcntl_wifsignaled($status) ? \pcntl_wtermsig($status) : null,
            ]);
        }
        return $result;
    }
}

namespace {
    use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
    use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
    use Jspeedz\MultiProcessor\MultiProcessor;
    use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;

    require __DIR__ . '/../../vendor/autoload.php';

    [$log, $concurrency, $chunkSize] = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');

    function recordGracefulEvent(string $event, array $details = []): void {
        file_put_contents($GLOBALS['log'], json_encode([
            'event' => $event,
            'pid' => getmypid(),
            ...$details,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    function readGracefulEvents(): array {
        $stream = fopen($GLOBALS['log'], 'r');
        flock($stream, LOCK_SH);
        $contents = stream_get_contents($stream);
        flock($stream, LOCK_UN);
        fclose($stream);
        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $contents === '' ? [] : explode("\n", rtrim($contents, "\n")),
        );
    }

    function waitForGracefulCondition(\Closure $condition): void {
        $deadline = microtime(true) + 5;
        while(!$condition()) {
            if(microtime(true) >= $deadline) {
                recordGracefulEvent('timeout');
                exit(43);
            }
            // Poll an explicit condition; elapsed time never releases a child.
            usleep(1000);
        }
    }

    $parentPID = getmypid();
    register_shutdown_function(static function() use ($parentPID): void {
        if(getmypid() === $parentPID) {
            recordGracefulEvent('parent-exit');
        }
    });

    $processor = new class($log, $concurrency, $chunkSize) extends ProcessorAbstract {
        private int $chunk;

        public function __construct(
            private string $log,
            private int $concurrency,
            private int $chunkSize,
        ) {}

        public function initialize(): void {
            $items = iterator_to_array($this->getData(), false);
            $this->chunk = intdiv($items[0], $this->chunkSize);
            recordGracefulEvent('start', ['chunk' => $this->chunk]);
        }

        public function process(): void {
            if($this->chunk === $this->concurrency) {
                // Crash only after every earlier child was reaped successfully
                // and all children in the crashing batch have started.
                waitForGracefulCondition(function(): bool {
                    $events = readGracefulEvents();
                    $starts = array_filter($events, fn(array $event): bool =>
                        $event['event'] === 'start' && $event['chunk'] >= $this->concurrency);
                    $reaped = array_filter($events, static fn(array $event): bool =>
                        $event['event'] === 'reaped' && $event['exitCode'] === 0);
                    return count($starts) >= $this->concurrency && count($reaped) >= $this->concurrency;
                });
                register_shutdown_function(function(): void {
                    recordGracefulEvent('fatal', [
                        'chunk' => $this->chunk,
                        'errorType' => error_get_last()['type'] ?? null,
                    ]);
                });
                multiprocessor_graceful_test_undefined_function();
            }

            if($this->chunk > $this->concurrency) {
                // PHPUnit creates this marker only after reading the manager's
                // graceful-shutdown message. Siblings cannot finish before that.
                waitForGracefulCondition(fn(): bool => is_file($this->log . '.release'));
                recordGracefulEvent('released', ['chunk' => $this->chunk]);
            }

            foreach($this->getData() as $item) {
                recordGracefulEvent('processed', ['chunk' => $this->chunk, 'item' => $item]);
            }
        }

        public function finish(): void {
            recordGracefulEvent('finish', ['chunk' => $this->chunk]);
        }

        public function exit(): void {
            recordGracefulEvent('exit', ['chunk' => $this->chunk]);
            parent::exit();
        }
    };

    // One successful batch, one crashing batch, and two chunks that must never start.
    $items = range(0, (2 * $concurrency + 2) * $chunkSize - 1);
    $manager = new MultiProcessor(new ArrayIterator($items), $processor);
    $manager->setChunkSize($chunkSize);
    $manager->setMaximumConcurrentChildren($concurrency);
    $manager->stopOnChildFatal(true);
    $manager->stopOnFatalMethod(StopOnFatalMethodEnum::GRACEFUL);
    $manager->useProgressBar(false);
    $manager->run();
    recordGracefulEvent('unexpected-return');
}
