<?php
namespace MultiProcessorBundle\MultiProcessor\Processor;

use Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

abstract class ProcessorAbstract implements ProcessorInterface, ContainerAwareInterface {
    use ContainerAwareTrait;

    /**
     * @var iterable
     */
    private $data;

    /**
     * @var boolean|string
     */
    private $result = true;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @param Logger $logger
     */
    public function setLogger(Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * @param iterable $data
     */
    public function setData(iterable $data) {
        $this->data = $data;
    }

    /**
     * @return iterable
     */
    public function getData() {
        return $this->data;
    }

    /**
     * Exits the current process, with optional exit value.
     */
    public function exit() {
        // 0 is successful, can be 1-254 for custom exit codes..
        exit(0);
    }
}
