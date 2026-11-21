<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Jspeedz\MultiProcessor\Utility\ProcessorCores;

die(print_r(ProcessorCores::getNumberOfCores(), true));
