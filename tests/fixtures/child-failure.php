<?php
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;

require __DIR__ . '/../../vendor/autoload.php';

[$items, $failureIndex, $exitCode, $stopOnFatal, $concurrency, $log] = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);

ini_set('display_errors', '0');
ini_set('log_errors', '0');

$processor = new class($log, $failureIndex, $exitCode) extends ProcessorAbstract {
    private $log;
    private $failureIndex;
    private $exitCode;

    public function __construct(string $log, int $failureIndex, int $exitCode) {
        $this->log = $log;
        $this->failureIndex = $failureIndex;
        $this->exitCode = $exitCode;
    }

    public function process(): void {
        foreach($this->getData() as $item) {
            if($item === $this->failureIndex) {
                if($this->exitCode === 255) {
                    // Exercise a real PHP fatal error in a child process.
                    multiprocessor_test_undefined_function();
                }
                exit($this->exitCode);
            }
            usleep(20000);
            file_put_contents($this->log, $item . "\n", FILE_APPEND | LOCK_EX);
        }
    }
};
$multiProcessor = new MultiProcessor(new ArrayIterator($items), $processor);
$multiProcessor->useProgressBar(false);
$multiProcessor->stopOnChildFatal($stopOnFatal);
$multiProcessor->stopOnFatalMethod(StopOnFatalMethodEnum::GRACEFUL);
$multiProcessor->setChunkSize(1);
$multiProcessor->setMaximumConcurrentChildren($concurrency);
$multiProcessor->run();
