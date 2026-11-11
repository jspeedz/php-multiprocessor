<?php

namespace Jspeedz\MultiProcessor {
    function pcntl_fork(): int {
        $attempt = ++$GLOBALS['attempts'];
        if($GLOBALS['config']['kind'] === 'fork' && $attempt === $GLOBALS['config']['at']) {
            if($attempt > 1) {
                \infrastructureWaitForChild();
            }
            \infrastructureEvent('fork-failed');
            return -1;
        }
        $pid = \pcntl_fork();
        if($pid > 0) {
            \infrastructureEvent('spawned', ['childPID' => $pid]);
        }
        return $pid;
    }
}

namespace {
    use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
    use Jspeedz\MultiProcessor\Exception\ProcessException;
    use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
    use Jspeedz\MultiProcessor\MultiProcessor;
    use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
    use Jspeedz\MultiProcessor\Tests\Support\ProcessScenario;

    require __DIR__ . '/../../vendor/autoload.php';
    $config = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    $attempts = 0;

    function infrastructureEvent(string $event, array $details = []): void {
        $line = json_encode(['event' => $event, 'pid' => getmypid(), ...$details], JSON_THROW_ON_ERROR) . "\n";
        if(file_put_contents($GLOBALS['config']['log'], $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('Could not record infrastructure event.');
        }
    }

    function infrastructureWaitForChild(): void {
        $deadline = hrtime(true) + 10_000_000_000;
        while(!in_array('held', array_column(ProcessScenario::events($GLOBALS['config']['log']), 'event'), true)) {
            if(hrtime(true) >= $deadline) {
                infrastructureEvent('timeout');
                exit(43);
            }
            usleep(1000);
        }
    }

    if(posix_setsid() === -1) {
        throw new RuntimeException('Could not isolate fixture process group.');
    }
    infrastructureEvent('session');
    $processor = new class($config['log']) extends ProcessorAbstract {
        public function __construct(private string $log) {}
        public function initialize(): void { infrastructureEvent('held'); }
        public function process(): void {
            $deadline = hrtime(true) + 10_000_000_000;
            while(!is_file($this->log . '.release')) {
                if(hrtime(true) >= $deadline) {
                    infrastructureEvent('timeout');
                    exit(43);
                }
                usleep(1000);
            }
            foreach($this->getData() as $item) {
                infrastructureEvent('processed', ['item' => $item]);
            }
        }
        public function finish(): void { infrastructureEvent('finish'); }
    };
    $manager = new MultiProcessor(new ArrayIterator(range(0, 19)), $processor);
    $manager->useProgressBar(false);
    $manager->setChunkSize(5);
    $manager->setMaximumConcurrentChildren(3);
    if($config['kind'] === 'callback') {
        $calls = 0;
        $manager->addCloseResourceCallback(static function() use (&$calls, $config): void {
            $calls++;
            infrastructureEvent('callback', ['call' => $calls]);
            if($calls === $config['at']) {
                if($calls > 1) {
                    infrastructureWaitForChild();
                }
                throw new RuntimeException('Intentional close-resource callback failure.');
            }
        }, ResourceCloseCallbackTriggerEnum::from($config['trigger']));
    }
    try {
        $manager->run();
        infrastructureEvent('unexpected-return');
    }
    catch(ProcessException | RuntimeException $exception) {
        $tracked = (new ReflectionProperty($manager, 'childPIDs'))->getValue($manager);
        infrastructureEvent('exception', ['class' => $exception::class, 'message' => $exception->getMessage(), 'tracked' => $tracked]);
        // This cleanup belongs to the caller/fixture. The test does not claim
        // run() automatically stops existing children when it throws.
        touch($config['log'] . '.release');
        foreach($tracked as $pid) {
            if(\pcntl_waitpid($pid, $status) !== $pid) {
                throw new RuntimeException('Fixture could not reap existing child.');
            }
            infrastructureEvent('caller-reaped', ['childPID' => $pid,
                'exitCode' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null]);
        }
    }
}
