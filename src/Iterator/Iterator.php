<?php
namespace Jspeedz\MultiProcessor\Iterator;

use Countable;

abstract class Iterator implements IteratorInterface, Countable {
    /**
     * This iterator does not wrap another iterator.
     */
    public function getInnerIterator(): ?\Iterator {
        return null;
    }
}
