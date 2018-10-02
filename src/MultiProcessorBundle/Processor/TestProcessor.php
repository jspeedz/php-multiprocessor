<?php
namespace MultiProcessorBundle\MultiProcessor\Processor;

class TestProcessor extends ProcessorAbstract {
    public function init() {
    }

    public function process() {
        $doctrine = $this->container->get('doctrine');
        $em = $doctrine->getManager();

        foreach($this->getData(``) as $item) {
            if($item instanceof \MultiProcessorBundle\Entity\SomeEntity) {
                // Doing some real hard work
                echo PHP_EOL . $item->getId() . PHP_EOL;
            }
            sleep(1);
        }
        $em->flush();
    }

    public function finish() {
    }

    /**
     * Exits the current process, with optional exit value.
     */
    public function exit() {
        exit(0);
    }
}
