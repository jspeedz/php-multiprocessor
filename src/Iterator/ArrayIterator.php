<?php
namespace Jspeedz\MultiProcessor\Iterator;

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
     * Keeps the initial key of the first element in the set data.
     * This adds support for arrays starting with an index > 0, like array_chunked arrays
     *
     * @var null|int
     */
    private $initialPointer;

    /**
     * @var null|int
     */
    private $pointer;

    /**
     * @param array $data
     * @param bool $reIndexArray = true Prevents errors when array element are unset, and the indexes are not continuous
     */
    public function __construct(array $data, bool $reIndexArray = true) {
        if($reIndexArray) {
            $data = array_values($data);
        }
        reset($data);

        $this->pointer = $this->initialPointer = key($data);

        $this->data = $data;
    }

    /**
     * @param integer $chunkSize
     */
    public function generateChunks(int $chunkSize): void {
        $this->data = array_chunk($this->data, $chunkSize, true);
        $this->chunkCount = count($this->data);
    }

    /**
     * @return boolean|mixed
     */
    public function next() {
        $this->pointer += 1;

        $this->executeIterationCallback();

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
    #[\ReturnTypeWillChange]
    public function rewind() {
        $this->pointer = $this->initialPointer;
        if(empty($this->data)) {
            return false;
        }

        return $this->data[$this->pointer];
    }

    /**
     * @return bool|array
     */
    #[\ReturnTypeWillChange]
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
    public function key(): int {
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
