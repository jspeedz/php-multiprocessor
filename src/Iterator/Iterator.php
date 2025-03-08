<?php
namespace Jspeedz\MultiProcessor\Iterator;

use Countable;

abstract class Iterator implements IteratorInterface, Countable {
    public function getInnerIterator() {}
}
