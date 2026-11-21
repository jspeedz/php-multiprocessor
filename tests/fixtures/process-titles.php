<?php

use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
use Jspeedz\MultiProcessor\Tests\Support\ProcessScenario;

require __DIR__ . '/../../vendor/autoload.php';

$config = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);

/** The title other tools see, as listed by ps. */
function listedTitle(int $pid): string {
    return trim((string) shell_exec('ps -ww -o command= -p ' . $pid));
}

if($config['title'] !== null) {
    cli_set_process_title($config['title']);
}

$processor = new class($config['log']) extends ProcessorAbstract {
    public function __construct(private string $log) {}

    public function process(): void {
        ProcessScenario::record($this->log, 'child', [
            'items' => array_values(iterator_to_array($this->getData())),
            'title' => listedTitle(posix_getpid()),
            'parentTitle' => listedTitle(posix_getppid()),
        ]);
    }
};
$manager = new MultiProcessor(new ArrayIterator($config['items']), $processor);
$manager->useProgressBar(false);
$manager->setChunkSize(2);
$manager->setMaximumConcurrentChildren(2);
ProcessScenario::record($config['log'], 'before', ['title' => listedTitle(posix_getpid())]);
$manager->run();
ProcessScenario::record($config['log'], 'after', ['title' => listedTitle(posix_getpid())]);
