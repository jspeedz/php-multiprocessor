<?php
namespace MultiProcessor\Iterator;

require_once __DIR__ . '/iterator.php';

/**
 * Class ArrayIterator
 *
 * @see http://php.net/manual/en/spl.iterators.php
 * @package MultiProcessor\Iterator
 */
class ArrayIterator extends Iterator {
	/**
	 * @var integer
	 */
	protected $chunkCount = 0;

	/**
	 * @var array
	 */
	private $data = [];

	/**
	 * @var integer
	 */
	private $pointer = 0;

	public function __construct(array $data) {
		$this->data = $data;
	}

	/**
	 * @param integer $chunkSize
	 */
	public function generateChunks(int $chunkSize) {
		$this->data = array_chunk($this->data, $chunkSize, true);
		$this->chunkCount = count($this->data);
	}

	/**
	 * @return boolean|mixed
	 */
	public function next() {
		$this->pointer += 1;
		if(isset($this->data[$this->pointer])) {
			return $this->data[$this->pointer];
		}
		else {
			return false;
		}
	}

	/**
	 * @return bool|mixed
	 */
	public function rewind() {
		$this->pointer = 0;
		if(empty($this->data)) {
			return false;
		}
		return $this->data[$this->pointer];
	}

	/**
	 * @return bool|array
	 */
	public function current() {
		if(isset($this->data[$this->pointer])) {
			return $this->data[$this->pointer];
		}
		else {
			return false;
		}
	}

	/**
	 * @return integer
	 */
	public function key() {
		return $this->pointer;
	}

	/**
	 * return the number of chunks.
	 *
	 * @return integer
	 */
	public function count() {
		return $this->chunkCount;
	}
	
	/**
	 * @return boolean
	 */
	public function valid() {
		return isset($this->data[$this->pointer]);
	}
}
