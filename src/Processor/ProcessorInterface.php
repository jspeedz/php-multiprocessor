<?php
namespace Jspeedz\MultiProcessor\Processor;

interface ProcessorInterface {
    /**
     * @param iterable $data
     */
    public function setData(iterable $data): void;

    /**
     * @return iterable
     */
    public function getData(): iterable;

    /**
     * Use this if you need to do some custom task before processing a chunk
     */
    public function initialize(): void;

    public function process(): void;

    /**
     * Use this if you need to do some custom task after finishing a chunk
     */
    public function finish(): void;

    /**
     * Should exit the current process
     */
    public function exit(): void;
}
