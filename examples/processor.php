<?php
require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/Processor/TestProcessor.php';

use Jspeedz\MultiProcessor\Callback\Close\StreamResources;
use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Utility\ProcessorCores;

$randomHashGenerator = function() {
	return md5(microtime());
};

$array = array_map(
    $randomHashGenerator,
    array_fill(0, 100, null)
);

$data = new ArrayIterator($array);
$processor = new TestProcessor();

$multiProcessor = new MultiProcessor($data, $processor);

$multiProcessor->setMaximumConcurrentChildren(ProcessorCores::getNumberOfCores() * 2);
$multiProcessor->setChunkSize(5);

$multiProcessor->stopOnParentFatal(true); // Stops all children when the parent process is gone
$multiProcessor->stopOnChildFatal(true); // Stops all children, and the parent when one of the forks crashes
// $multiProcessor->stopOnFatalMethod(StopOnFatalMethodEnum::GRACEFUL);
// $multiProcessor->stopOnFatalMethod(StopOnFatalMethodEnum::NORMAL);
$multiProcessor->stopOnFatalMethod(StopOnFatalMethodEnum::IMMEDIATE);

$multiProcessor->addCloseResourceCallback((new StreamResources)->getCallback(), ResourceCloseCallbackTriggerEnum::ONCE);

$multiProcessor->useProgressBar(true);

$multiProcessor->run();
