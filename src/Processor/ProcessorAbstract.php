<?php
namespace Jspeedz\MultiProcessor\Processor;

use Closure;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\Iterator\IteratorInterface;

abstract class ProcessorAbstract implements ProcessorInterface {
    /**
     * @var iterable<int, mixed>|null
     */
    private ?iterable $data = null;

    protected ?Closure $parentAliveCheck = null;

    /**
     * Whether to check if the parent is alive, and kill myself if not
     *
     * @param Closure $callback
     */
    public function setParentAliveCheckCallback(Closure $callback): void {
        $this->parentAliveCheck = $callback;

        if($this->data !== null) {
            // Make sure we re-set the iterator so the callback is registered
            $this->setData($this->data);
        }
    }

    /**
     * @param iterable<int, mixed> $data
     *
     * @return void
     */
    public function setData(iterable $data): void {
        if(is_array($data)) {
            $data = new ArrayIterator($data);
        }

        // Only our own iterators support an iteration callback; other iterables are used as-is
        if($this->parentAliveCheck !== null && $data instanceof IteratorInterface) {
            // If callback returns false it'll stop iterating
            $data->setIterationCallback($this->parentAliveCheck);
        }

        $this->data = $data;
    }

    /**
     * Use this if you need to do some custom task before processing a chunk
     */
    public function initialize(): void {}

    /**
     * @return iterable<int, mixed>|null
     */
    public function getData(): ?iterable {
        return $this->data;
    }

    /**
     * Use this if you need to do some custom task after finishing a chunk
     */
    public function finish(): void {}

    /**
     * Should exit the current process, overwrite if you need a custom exit code
     */
    public function exit(): void {
        // 0 is successful, can be 1-254 for custom exit codes..
        exit(0);
    }
}
