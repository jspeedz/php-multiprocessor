<?php
namespace Jspeedz\MultiProcessor\Iterator;

use Closure;
use Countable;

abstract class IteratorAbstract implements IteratorInterface, Countable {
    /**
     * @var null|Closure $iterationCallback
     */
    protected $iterationCallback;

    public function getInnerIterator() {}

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
