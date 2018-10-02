<?php
namespace MultiProcessor\Iterator;

require_once __DIR__ . '/iteratorinterface.php';

abstract class Iterator implements IteratorInterface, \Countable {
	/**
	 * @return \Iterator;
	 */
	public function getInnerIterator() {

	}
}
