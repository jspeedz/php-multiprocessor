<?php
namespace MultiProcessorBundle\MultiProcessor\Iterator;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

abstract class IteratorAbstract implements IteratorInterface, \Countable, ContainerAwareInterface {
    use ContainerAwareTrait;

    /**
     * @return \Iterator;
     */
    public function getInnerIterator() {
    }
}
