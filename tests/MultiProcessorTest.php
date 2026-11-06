<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Exception\InvalidPriorityException;
use Jspeedz\MultiProcessor\Exception\StopOnFatalMethodException;
use Jspeedz\MultiProcessor\Exception\UnsupportedException;
use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\MultiProcessor;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultiProcessorTest extends TestCase {
    private array $handlers = [];
    private bool $asyncSignals;

    protected function setUp(): void {
        $this->asyncSignals = pcntl_async_signals();
        foreach([SIGTERM, SIGINT, SIGCHLD] as $signal) {
            $this->handlers[$signal] = pcntl_signal_get_handler($signal);
        }
    }

    protected function tearDown(): void {
        foreach($this->handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        pcntl_async_signals($this->asyncSignals);
    }

    private function manager(): MultiProcessor {
        $processor = new class extends ProcessorAbstract {
            public function process(): void {}
        };
        $manager = new MultiProcessor(new ArrayIterator([]), $processor);
        $manager->useProgressBar(false);
        return $manager;
    }

    public static function invalidSettings(): iterable {
        yield 'priority below minimum' => ['setPriority', -21, InvalidPriorityException::class, 'Invalid process priority (-21)'];
        yield 'priority above maximum' => ['setPriority', 20, InvalidPriorityException::class, 'Invalid process priority (20)'];
        yield 'unknown shutdown method' => ['stopOnFatalMethod', 'invalid', StopOnFatalMethodException::class, 'Unknown stop on fatal method (invalid)'];
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejected(string $method, int|string $value, string $exception, string $message): void {
        $manager = $this->manager();
        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        $manager->$method($value);
    }

    public function testUnknownResourceCallbackTriggerIsRejected(): void {
        $manager = $this->manager();
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Unknown trigger (sometimes)');
        $manager->addCloseResourceCallback(static function(): void {}, 'sometimes');
    }

    public function testEmptyRunClosesResourcesOnceWithoutCallingPerForkCallbacks(): void {
        $manager = $this->manager();
        $calls = [];
        $manager->addCloseResourceCallback(function() use (&$calls): void { $calls[] = 'once'; }, 'once');
        $manager->addCloseResourceCallback(function() use (&$calls): void { $calls[] = 'second'; }, 'once');
        $manager->addCloseResourceCallback(function() use (&$calls): void { $calls[] = 'always'; });

        $manager->run();

        self::assertSame(['once', 'second'], $calls);
        $this->expectOutputString('');
    }
}
