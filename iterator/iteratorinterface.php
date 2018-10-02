<?php
namespace MultiProcessor\Iterator;

interface IteratorInterface extends \OuterIterator {
	/**
	 * Generate the number of chunks requested.
	 *
	 * @param integer $chunkSize
	 *
	 * @return @todo
	 */
	public function generateChunks(int $chunkSize);

	public function getInnerIterator();
}
