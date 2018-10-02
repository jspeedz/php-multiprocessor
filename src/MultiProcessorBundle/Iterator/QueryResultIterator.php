<?php
namespace MultiProcessorBundle\MultiProcessor\Iterator;

use Doctrine\ORM\Query;
use Knp\Bundle\PaginatorBundle\KnpPaginatorBundle;

/**
 * Please note, if your query is slow the KnpPaginator is extremely slow, so it won't be able to fork fast enough to actually work well
 *
 * @see http://php.net/manual/en/spl.iterators.php
 *
 * @uses KnpPaginatorBundle
 */
class QueryResultIterator extends IteratorAbstract {
    /**
     * @var int
     */
    protected $chunkCount = 0;

    /**
     * @var Query
     */
    private $query;

    /**
     * @var int
     */
    private $pointer = 1;
    private $chunkSize;

    /**
     * @param Query $query
     */
    public function __construct(Query $query) {
        $this->query = $query;
    }

    /**
     * @param integer $chunkSize
     */
    public function generateChunks(int $chunkSize) {
        $pagination = $this->container->get('knp_paginator');
        $this->chunkSize = $chunkSize;

        $pagination = $pagination->paginate($this->query, 1, $this->chunkSize);
        $this->chunkCount = ceil($pagination->getTotalItemCount() / $this->chunkSize);
    }

    /**
     * @return boolean|mixed
     * @throws \Exception
     */
    public function next() {
        $this->pointer += 1;

        if(!$this->valid()) {
            return false;
        }

        return $this->current();
    }

    /**
     * @return bool|mixed
     * @throws \Exception
     */
    public function rewind() {
        $this->pointer = 1;

        return $this->current();
    }

    /**
     * @return bool|array
     * @throws \Exception
     */
    public function current() {
        $pagination = $this->container->get('knp_paginator');
        $queryTime = microtime(true);
        $pagination = $pagination->paginate($this->query, $this->pointer, $this->chunkSize);
        $items = $pagination->getItems();
        if(microtime(true) - $queryTime > 0.5) {
            // 0.5 second (pulled randomly from nose number)
            throw new \Exception('QueryResultIterator is too slow with the current query, cannot fork fast enough, use an ArrayIterator instead');
        }

        if(count($items) > 0) {
            return $items;
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
        if($this->pointer <= $this->chunkCount) {
            return true;
        }

        return false;
    }
}
