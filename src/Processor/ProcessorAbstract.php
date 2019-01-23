<?php
namespace Jspeedz\MultiProcessor\Processor;

use Closure;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;

abstract class ProcessorAbstract implements ProcessorInterface {
	/**
	 * @var iterable
	 */
	private $data;

    /**
     * @var Closure|null
     */
    protected $parentAliveCheck;

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
	 * @param iterable $data
	 */
	public function setData(iterable $data): void {
        if(is_array($data)) {
            $data = new ArrayIterator($data);
        }

        if($this->parentAliveCheck !== null) {
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
	 * @return iterable
	 */
	public function getData(): iterable {
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
