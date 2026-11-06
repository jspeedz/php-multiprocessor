<?php

use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;

require __DIR__ . '/../../vendor/autoload.php';

[$log, $chunkSize, $concurrency, $parentCheck] = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$processor = new class($log) extends ProcessorAbstract {
    public int $processed = 0;

    public function __construct(private string $log) {}

    private function record(string $stage): void {
        file_put_contents($this->log, json_encode([
            'pid' => posix_getpid(),
            'stage' => $stage,
            'items' => iterator_to_array($this->getData()),
            'processed' => $this->processed,
            'parentCheck' => $this->parentAliveCheck !== null,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    public function initialize(): void { $this->record('initialize'); }

    public function process(): void {
        $this->processed += iterator_count($this->getData());
        $this->record('process');
    }

    public function finish(): void { $this->record('finish'); }

    public function exit(): void {
        $this->record('exit');
        parent::exit();
    }
};
$manager = new MultiProcessor(new ArrayIterator([10, 20, 30, 40, 50]), $processor);
$manager->useProgressBar(false);
$manager->stopOnParentFatal($parentCheck);
$manager->setChunkSize($chunkSize);
$manager->setMaximumConcurrentChildren($concurrency);
$once = $always = 0;
$manager->addCloseResourceCallback(function() use (&$once): void { $once++; }, 'once');
$manager->addCloseResourceCallback(function() use (&$always): void { $always++; });
$manager->run();
echo json_encode(['pid' => posix_getpid(), 'once' => $once, 'always' => $always, 'processed' => $processor->processed], JSON_THROW_ON_ERROR);
