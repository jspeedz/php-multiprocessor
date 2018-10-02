<?php
namespace MultiProcessorBundle\MultiProcessor\Test;

use MultiProcessorBundle\MultiProcessor\Iterator\QueryResultIterator;
use MultiProcessorBundle\MultiProcessor\MultiProcessor;
use MultiProcessorBundle\MultiProcessor\Iterator\ArrayIterator;
use MultiProcessorBundle\MultiProcessor\Processor\TestProcessor;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * @todo Make this a service/command, incject container
 *
 * @package MultiProcessorBundle\MultiProcessor\Test
 */
class Test implements ContainerAwareInterface {
    use ContainerAwareTrait;

    /**
     * @param array $options
     *
     * @throws \Exception
     */
    public function run(array $options = []): void {
        parent::run($options);

        echo 'Running array iterator' . PHP_EOL;
        $this->runArrayIterator();

//        echo 'Running query result iterator' . PHP_EOL;
//        $this->runQueryResultIterator();
    }

    public function runArrayIterator() {
        $data = new ArrayIterator(array_merge(array_fill(0, 100, md5(microtime())), [
            '2',
            '3',
            '4',
            '5',
            '6',
            '7',
            'u',
            'v',
            'w',
            'x',
            'y',
            'z'
        ]));

        $processor = new TestProcessor();

        $multiProcessor = new MultiProcessor($data, $processor);

        $multiProcessor->setContainer($this->container);
        $multiProcessor->setLogger($this->container->get('logger'));

        $multiProcessor->setMaximumConcurrentChildren($this->container->get('util_machine')->getNumberOfCores() * 2);
        $multiProcessor->setChunkSize(2);

        $multiProcessor->run();
    }

    public function runQueryResultIterator() {
        $repository = $this->container->get('doctrine')->getRepository('MultiProcessorBundle:SomeEntity');
        $query = $repository->fetchPaginateQueryWithItems();

        $data = new QueryResultIterator($query);
        $data->setContainer($this->container);

        $processor = new TestProcessor();

        $multiProcessor = new MultiProcessor($data, $processor);

        $multiProcessor->setContainer($this->container);
        $multiProcessor->setLogger($this->container->get('logger'));

        $multiProcessor->setMaximumConcurrentChildren($this->container->get('util_machine')->getNumberOfCores() * 2);
        $multiProcessor->setChunkSize(2);

        $multiProcessor->run();
    }
}
