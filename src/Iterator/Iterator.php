<?php
namespace Jspeedz\MultiProcessor\Iterator;

abstract class Iterator implements IteratorInterface, \Countable {
	public function getInnerIterator() {}
}
