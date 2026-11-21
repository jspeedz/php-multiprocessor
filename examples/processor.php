<?php

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/CompletionLog.php';
require_once __DIR__ . '/Processor/TestProcessor.php';

use Jspeedz\MultiProcessor\Callback\Close\StreamResources;
use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Utility\ProcessorCores;

$randomHashGenerator = function(int $id): string {
    // A unique identity lets verification detect missing and repeated items.
    return $id . ':' . md5(microtime());
};

$array = array_map(
    $randomHashGenerator,
    range(0, 9999)
);

$removeLogAfterVerification = true;
$completionPath = tempnam(sys_get_temp_dir(), 'multiprocessor-completed-');

if($completionPath === false) {
    throw new RuntimeException('Could not create the completion log.');
}
$parentPID = posix_getpid();

echo sprintf(
    'Writing tmp log to%s: %s' . PHP_EOL,
    $removeLogAfterVerification ? ' (removing log after verification)' : '',
    $completionPath,
);
register_shutdown_function(static function() use ($parentPID, $removeLogAfterVerification, $completionPath): void {
    // Children inherit shutdown handlers, but must not remove the shared log.
    if($removeLogAfterVerification && posix_getpid() === $parentPID) {
        unlink($completionPath);
    }
});
$completionLog = new CompletionLog($completionPath);

$data = new ArrayIterator($array);
$processor = new TestProcessor($completionLog);

$multiProcessor = new MultiProcessor($data, $processor);

$cores = ProcessorCores::getNumberOfCores() * 2;

echo sprintf('Using a maximum of %s concurrent forked processes' . PHP_EOL, $cores);

$multiProcessor->setMaximumConcurrentChildren($cores);
$multiProcessor->setChunkSize(250);

// Stops all children when the parent process is gone
$multiProcessor->stopOnParentFatal(true);

// Stops all children, and the parent when one of the forks crashes
$multiProcessor->stopOnChildFatal(true);

//$multiProcessor->stopOnFatalMethod(StopOnFatalMethodEnum::GRACEFUL);
//$multiProcessor->stopOnFatalMethod(StopOnFatalMethodEnum::NORMAL);
$multiProcessor->stopOnFatalMethod(StopOnFatalMethodEnum::IMMEDIATE);

$multiProcessor->addCloseResourceCallback(
    (new StreamResources)->getCallback(),
    ResourceCloseCallbackTriggerEnum::ONCE,
);

$multiProcessor->useProgressBar(true);

$multiProcessor->run();

// run() waits for the last children. A progress bar alone cannot prove completion.
$completionLog->verify($array);
echo 'Verified all ' . count($array) . ' items were handled exactly once.' . PHP_EOL;
