<?php
namespace MultiProcessorBundle\MultiProcessor\Iterator;

/**
 * @see http://php.net/manual/en/spl.iterators.php
 */
class ArrayIterator extends IteratorAbstract {
    /**
     * @var int
     */
    protected $chunkCount = 0;

    /**
     * @var array
     */
    private $data = [];

    /**
     * @var int
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
    public function count(): int {
        return $this->chunkCount;
    }

    /**
     * @return boolean
     */
    public function valid(): bool {
        return isset($this->data[$this->pointer]);
    }
}
