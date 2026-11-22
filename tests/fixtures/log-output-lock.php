<?php

use Jspeedz\MultiProcessor\Callback\Close\StreamResources;
use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\Logging\LogOutputLock;
use Jspeedz\MultiProcessor\Logging\Monolog\CrossProcessLockHandler;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

require __DIR__ . '/../../vendor/autoload.php';

// The test parses every output line as a JSON log record. Keep PHP diagnostics (for example a pcntl_setpriority()
// warning in restricted environments) out of that stream; a fatal still fails the test via the exit status.
ini_set('display_errors', '0');
ini_set('log_errors', '0');

// Every child writes JSON log records far larger than PIPE_BUF to the shared stdout pipe.
['lock' => $useLock, 'lockDirectory' => $lockDirectory, 'children' => $children, 'records' => $records, 'size' => $size]
    = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);

$lock = new LogOutputLock($lockDirectory);
if($useLock) {
    $lock->enable();
}
$stream = new StreamHandler('php://stdout');
$stream->setFormatter(new JsonFormatter());
$logger = new Logger('fixture', [new CrossProcessLockHandler($stream, $lock)]);

$processor = new class($logger, $lock, $records, $size) extends ProcessorAbstract {
    public function __construct(private Logger $logger, private LogOutputLock $lock, private int $records, private int $size) {}

    public function process(): void {
        foreach($this->getData() as $child) {
            for($i = 0; $i < $this->records; $i++) {
                $this->logger->info("child $child record $i", [
                    'lockFile' => $this->lock->getLockFilePath(),
                    'blob' => str_repeat(chr(65 + $child % 26), $this->size),
                ]);
            }
        }
    }
};

$logger->info('parent before fork', ['pid' => posix_getpid(), 'lockFile' => $lock->getLockFilePath()]);
$manager = new MultiProcessor(new ArrayIterator(range(0, $children - 1)), $processor);
$manager->setMaximumConcurrentChildren($children);
$manager->setChunkSize(1);
// Closes the parent's lock and log handles before forking, both must be reopened per process
$manager->addCloseResourceCallback((new StreamResources)->getCallback(), ResourceCloseCallbackTriggerEnum::ONCE);
$manager->useProgressBar(false);
$manager->run();
$logger->info('parent after run', ['lockFileExists' => is_file((string) $lock->getLockFilePath())]);
