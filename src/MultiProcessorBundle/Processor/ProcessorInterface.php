<?php
namespace MultiProcessorBundle\MultiProcessor\Processor;

interface ProcessorInterface {
    public function setData(iterable $data);

    public function getData();

    public function init();

    public function process();

    public function finish();

    /**
     * Exits the current process, with optional exit value.
     *
     * return void
     */
    public function exit();
}
