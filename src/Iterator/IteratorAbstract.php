<?php
namespace Jspeedz\MultiProcessor\Iterator;

use Closure;
use Countable;

abstract class IteratorAbstract implements IteratorInterface, Countable {
    /**
     * @var null|Closure $iterationCallback
     */
    protected $iterationCallback;

    /**
     * This iterator does not wrap another iterator.
     */
    public function getInnerIterator(): ?\Iterator {
        return null;
    }

    /**
     * @param Closure $callback
     */
    public function setIterationCallback(Closure $callback): void {
        $this->iterationCallback = $callback;
    }

    public function executeIterationCallback(): void {
        if($this->iterationCallback !== null) {
            call_user_func_array($this->iterationCallback, []);
        }
    }
}
