<?php

use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;

require __DIR__ . '/../../vendor/autoload.php';

[$log, $items, $chunkSize, $concurrency, $parentCheck, $stopOnFatal, $progress, $method, $priority] = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$processor = new class($log) extends ProcessorAbstract {
    public int $processed = 0;
    public array $completed = [];

    public function __construct(private string $log) {}

    private function record(string $stage): void {
        file_put_contents($this->log, json_encode([
            'pid' => posix_getpid(),
            'stage' => $stage,
            'items' => iterator_to_array($this->getData()),
            'processed' => $this->processed,
            'completed' => $this->completed,
            'parentCheck' => $this->parentAliveCheck !== null,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    public function initialize(): void { $this->record('initialize'); }

    public function process(): void {
        foreach($this->getData() as $item) {
            $this->completed[] = $item;
            $this->processed++;
        }
        $this->record('process');
    }

    public function finish(): void { $this->record('finish'); }

    public function exit(): void {
        $this->record('exit');
        parent::exit();
    }
};
$manager = new MultiProcessor(new ArrayIterator($items), $processor);
$manager->useProgressBar($progress);
$manager->stopOnParentFatal($parentCheck);
$manager->stopOnChildFatal($stopOnFatal);
$manager->stopOnFatalMethod(StopOnFatalMethodEnum::from($method));
$manager->setPriority($priority);
$manager->setChunkSize($chunkSize);
$manager->setMaximumConcurrentChildren($concurrency);
$once = $always = 0;
$manager->addCloseResourceCallback(function() use (&$once): void { $once++; }, ResourceCloseCallbackTriggerEnum::ONCE);
$manager->addCloseResourceCallback(function() use (&$always): void { $always++; });
$manager->run();
file_put_contents($log . '.parent', json_encode(['pid' => posix_getpid(), 'once' => $once, 'always' => $always, 'processed' => $processor->processed], JSON_THROW_ON_ERROR));
