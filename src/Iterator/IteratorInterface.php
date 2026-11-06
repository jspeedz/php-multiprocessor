<?php
namespace Jspeedz\MultiProcessor\Iterator;

use Closure;
use OuterIterator;

/**
 * @extends OuterIterator<mixed, mixed>
 */
interface IteratorInterface extends OuterIterator {
    /**
     * Generate the number of chunks requested.
     *
     * @param positive-int $chunkSize
     */
    public function generateChunks(int $chunkSize): void;

    public function getInnerIterator(): ?\Iterator;

    /**
     * @param Closure $callback
     */
    public function setIterationCallback(Closure $callback): void;

    public function executeIterationCallback(): void;
}
