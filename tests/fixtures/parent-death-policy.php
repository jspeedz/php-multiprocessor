<?php

use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
use Jspeedz\MultiProcessor\Tests\Support\ProcessScenario;

require __DIR__ . '/../../vendor/autoload.php';
$config = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);

function parentDeathEvent(string $event, array $details = []): void {
    $line = json_encode(['event' => $event, 'pid' => getmypid(), ...$details], JSON_THROW_ON_ERROR) . "\n";
    if(file_put_contents($GLOBALS['config']['log'], $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
        throw new RuntimeException('Could not record parent-death event.');
    }
}

function parentDeathWait(Closure $condition): void {
    $deadline = hrtime(true) + 10_000_000_000;
    while(!$condition()) {
        if(hrtime(true) >= $deadline) {
            parentDeathEvent('timeout');
            exit(43);
        }
        usleep(1000);
    }
}

if(posix_setsid() === -1) {
    throw new RuntimeException('Could not isolate fixture process group.');
}
parentDeathEvent('session');
// Workers inherit the writer. EOF proves all workers exited even though the
// supervisor cannot waitpid() grandchildren reparented to the system reaper.
$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if($sockets === false) {
    throw new RuntimeException('Could not create lifetime pipe.');
}
$managerPID = pcntl_fork();
if($managerPID === -1) {
    throw new RuntimeException('Could not fork manager.');
}
if($managerPID === 0) {
    fclose($sockets[0]);
    parentDeathEvent('manager-start');
    $processor = new class($config) extends ProcessorAbstract {
        public function __construct(private array $config) {}

        public function initialize(): void {
            parentDeathEvent('start');
            register_shutdown_function(static fn() => parentDeathEvent('child-shutdown'));
        }

        public function process(): void {
            $first = true;
            foreach($this->getData() as $item) {
                parentDeathEvent('processed', ['item' => $item]);
                if($first) {
                    parentDeathEvent('held');
                    parentDeathWait(fn(): bool => is_file($this->config['log'] . '.parent-dead'));
                    parentDeathEvent('parent-loss-observed');
                    $first = false;
                }
            }
        }

        public function finish(): void { parentDeathEvent('finish'); }
        public function exit(): void {
            parentDeathEvent('exit');
            parent::exit();
        }
    };
    $manager = new MultiProcessor(new ArrayIterator(range(0, ($config['concurrency'] + 2) * 20 - 1)), $processor);
    $manager->setChunkSize(20);
    $manager->setMaximumConcurrentChildren($config['concurrency']);
    $manager->stopOnParentFatal($config['parentCheck']);
    $manager->stopOnFatalMethod(StopOnFatalMethodEnum::from($config['method']));
    $manager->useProgressBar(false);
    $manager->run();
    parentDeathEvent('unexpected-return');
    exit(44);
}

fclose($sockets[1]);
parentDeathWait(static fn(): bool => count(array_filter(ProcessScenario::events($config['log']),
    static fn(array $event): bool => $event['event'] === 'held')) === $config['concurrency']);
if(!posix_kill($managerPID, SIGKILL) || pcntl_waitpid($managerPID, $status) !== $managerPID) {
    throw new RuntimeException('Could not kill and reap manager.');
}
parentDeathEvent('manager-reaped', ['managerPID' => $managerPID, 'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null]);
touch($config['log'] . '.parent-dead');
stream_set_blocking($sockets[0], false);
parentDeathWait(static function() use ($sockets): bool {
    stream_get_contents($sockets[0]);
    return feof($sockets[0]);
});
fclose($sockets[0]);
parentDeathEvent('workers-exited');
