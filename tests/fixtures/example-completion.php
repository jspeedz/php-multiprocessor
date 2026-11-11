<?php

use Jspeedz\MultiProcessor\Callback\Close\StreamResources;
use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\MultiProcessor;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../examples/CompletionLog.php';
require __DIR__ . '/../../examples/Processor/TestProcessor.php';

$items = array_map('strval', range(0, 9999));
$log = new CompletionLog($argv[1]);
// Use the real hashing/verification processor with a fixed inexpensive cost.
$manager = new MultiProcessor(new ArrayIterator($items), new TestProcessor($log, 4));
$manager->setMaximumConcurrentChildren(8);
$manager->setChunkSize(5);
$manager->stopOnParentFatal(true);
$manager->stopOnChildFatal(true);
$manager->stopOnFatalMethod(StopOnFatalMethodEnum::IMMEDIATE);
$manager->addCloseResourceCallback((new StreamResources)->getCallback(), ResourceCloseCallbackTriggerEnum::ONCE);
$manager->useProgressBar(false);
$manager->run();
$log->verify($items);
