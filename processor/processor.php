<?php
namespace MultiProcessor\Processor;

require_once __DIR__ . '/processorinterface.php';

abstract class Processor implements ProcessorInterface {
	/**
	 * @var iterable
	 */
	private $data;

	/**
	 * @var boolean|string
	 */
	private $result = true;

	/**
	 * @param iterable $data
	 */
	public function setData(iterable $data) {
		$this->data = $data;
	}

	/**
	 * @return iterable
	 */
	public function getData() {
		return $this->data;
	}

	/**
	 * Exits the current process, with optional exit value.
	 */
	public function exit() {
		// 0 is successful, can be 1-254 for custom exit codes..
		exit(0);
	}
}
