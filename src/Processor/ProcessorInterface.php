<?php
namespace Jspeedz\MultiProcessor\Processor;

interface ProcessorInterface {
    /**
     * @param iterable<mixed, mixed> $data
     */
    public function setData(iterable $data): void;

    /**
     * @return iterable<mixed, mixed>|null
     */
    public function getData(): ?iterable;

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
