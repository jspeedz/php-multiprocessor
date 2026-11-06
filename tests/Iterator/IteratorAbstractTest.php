<?php
namespace Jspeedz\MultiProcessor\Tests\Iterator;

use Jspeedz\MultiProcessor\Iterator\IteratorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IteratorAbstract::class)]
final class IteratorAbstractTest extends TestCase {
    private function iterator(): IteratorAbstract {
        return new class extends IteratorAbstract {
            public function generateChunks(int $chunkSize): void {}
            public function current(): mixed { return null; }
            public function key(): mixed { return null; }
            public function next(): void {}
            public function rewind(): void {}
            public function valid(): bool { return false; }
            public function count(): int { return 0; }
        };
    }

    public function testHasNoInnerIterator(): void {
        self::assertNull($this->iterator()->getInnerIterator());
    }

    public function testExecutingWithoutACallbackDoesNothing(): void {
        $this->expectNotToPerformAssertions();

        $this->iterator()->executeIterationCallback();
    }

    public function testCallbackRunsWithoutArgumentsOnEveryExecution(): void {
        $iterator = $this->iterator();
        $calls = [];
        $iterator->setIterationCallback(function(...$arguments) use (&$calls): void {
            $calls[] = $arguments;
        });

        self::assertSame([], $calls, 'Setting the callback must not execute it.');

        $iterator->executeIterationCallback();
        self::assertSame([[]], $calls);

        $iterator->executeIterationCallback();
        self::assertSame([[], []], $calls);
    }

    public function testReplacingCallbackOnlyExecutesTheNewCallback(): void {
        $iterator = $this->iterator();
        $oldCalls = $newCalls = 0;
        $iterator->setIterationCallback(function() use (&$oldCalls): void { $oldCalls++; });
        $iterator->executeIterationCallback();

        $iterator->setIterationCallback(function() use (&$newCalls): void { $newCalls++; });
        $iterator->executeIterationCallback();

        self::assertSame(1, $oldCalls);
        self::assertSame(1, $newCalls);
    }

    public function testCallbackExceptionsArePropagated(): void {
        $iterator = $this->iterator();
        $exception = new \RuntimeException('Iteration failed.');
        $iterator->setIterationCallback(function() use ($exception): void {
            throw $exception;
        });

        $this->expectExceptionObject($exception);

        $iterator->executeIterationCallback();
    }
}
