<?php
require_once __DIR__ . '/../utility/processorcores.php';

use MultiProcessor\Utility\ProcessorCores;

die(print_r(ProcessorCores::getNumberOfCores(), true));
