<?php
require_once __DIR__ . '/../multiprocessor.php';
require_once __DIR__ . '/../utility/processorcores.php';
require_once __DIR__ . '/../iterator/arrayiterator.php';
require_once __DIR__ . '/../processor/test.php';

use MultiProcessor\MultiProcessor;
use MultiProcessor\Utility\ProcessorCores;

$randomHashGenerator = function() {
	return md5(microtime());
};

$array = [];
for($i = 0; $i < 1000; $i++) {
	$array[] = $randomHashGenerator();
}

$data = new \MultiProcessor\Iterator\ArrayIterator($array);
$processor = new \MultiProcessor\Processor\Test();

$multiProcessor = new MultiProcessor($data, $processor);
$multiProcessor->setMaximumConcurrentChildren(ProcessorCores::getNumberOfCores());
$multiProcessor->setChunkSize(5);
$multiProcessor->run();
