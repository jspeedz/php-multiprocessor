<?php
require_once __DIR__ . '/../multiprocessor.php';
require_once __DIR__ . '/../utility/processorcores.php';
require_once __DIR__ . '/../iterator/arrayiterator.php';
require_once __DIR__ . '/../processor/test.php';

use MultiProcessor\MultiProcessor;
use MultiProcessor\Utility\ProcessorCores;

$data = new \MultiProcessor\Iterator\ArrayIterator(array_merge(array_fill(0, 100, md5(microtime())), ['2', '3', '4', '5', '6', '7', 'u', 'v', 'w', 'x', 'y', 'z']));
$processor = new \MultiProcessor\Processor\Test();

$multiProcessor = new MultiProcessor($data, $processor);
//$multiProcessor->setMaximumConcurrentChildren(ProcessorCores::getNumberOfCores());
$multiProcessor->setMaximumConcurrentChildren(4);
$multiProcessor->setChunkSize(2);
$multiProcessor->run();
