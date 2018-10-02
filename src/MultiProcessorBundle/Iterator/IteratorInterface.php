<?php
namespace MultiProcessorBundle\MultiProcessor\Iterator;

use OuterIterator;

interface IteratorInterface extends OuterIterator {
    /**
     * Generate the number of chunks requested.
     *
     * @param integer $chunkSize
     */
    public function generateChunks(int $chunkSize);

    public function getInnerIterator();
}
