<?php
namespace MultiProcessor\Processor;

require_once __DIR__ . '/processor.php';

class Test extends Processor {
	public function init() {
	}

	public function process() {
//		throw new \Exception('x');
		foreach($this->getData(``) as $item) {
			sleep(1);
//			sleep(rand(1, 2));
//			echo $item . PHP_EOL;
		}
	}

	public function finish() {
	}

	/**
	 * Exits the current process, with optional exit value.
	 */
	public function exit() {
		exit(1);
	}
}
