<?php
namespace Jspeedz\MultiProcessor\Iterator;

use Closure;

interface IteratorInterface extends \OuterIterator {
	/**
	 * Generate the number of chunks requested.
	 *
	 * @param integer $chunkSize
	 */
    public function generateChunks(int $chunkSize): void;

	public function getInnerIterator();

    /**
     * @param Closure $callback
     */
    public function setIterationCallback(Closure $callback): void;

    public function executeIterationCallback(): void;
}
