<?php
namespace Jspeedz\MultiProcessor\Iterator;

/**
 * @see http://php.net/manual/en/spl.iterators.php
 */
class ArrayIterator extends IteratorAbstract {
    /**
     * @var non-negative-int
     */
    protected int $chunkCount = 0;

    /**
     * @var array<int, mixed>
     */
    private array $data = [];

    /**
     * Keeps the initial key of the first element in the set data.
     * This adds support for arrays starting with an index > 0, like array_chunked arrays
     */
    private ?int $initialPointer;

    private ?int $pointer;

    /**
     * @param array<int, mixed> $data
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
     * @param positive-int $chunkSize
     */
    public function generateChunks(int $chunkSize): void {
        $this->data = array_chunk($this->data, $chunkSize, true);
        $this->chunkCount = count($this->data);
    }

    /**
     * @return false|mixed
     */
    #[\ReturnTypeWillChange]
    public function next(): mixed {
        $this->pointer += 1;

        $this->executeIterationCallback();

        if(array_key_exists($this->pointer, $this->data)) {
            return $this->data[$this->pointer];
        }
        else {
            return false;
        }
    }

    /**
     * @return false|mixed
     */
    #[\ReturnTypeWillChange]
    public function rewind(): mixed {
        $this->pointer = $this->initialPointer;
        if(
            empty($this->data) ||
            $this->pointer === null ||
            !array_key_exists($this->pointer, $this->data)
        ) {
            return false;
        }

        return $this->data[$this->pointer];
    }

    /**
     * @return false|mixed
     */
    #[\ReturnTypeWillChange]
    public function current(): mixed {
        if($this->pointer !== null && array_key_exists($this->pointer, $this->data)) {
            return $this->data[$this->pointer];
        }
        else {
            return false;
        }
    }

    /**
     * @return int|null Null when there is no data to iterate
     */
    public function key(): ?int {
        return $this->pointer;
    }

    /**
     * return the number of chunks.
     *
     * @return non-negative-int
     */
    public function count(): int {
        return $this->chunkCount;
    }

    public function valid(): bool {
        return $this->pointer !== null && array_key_exists($this->pointer, $this->data);
    }
}
