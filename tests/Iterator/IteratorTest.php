<?php
namespace Jspeedz\MultiProcessor\Tests\Iterator;

use Closure;
use Jspeedz\MultiProcessor\Iterator\Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Iterator::class)]
final class IteratorTest extends TestCase {
    public function testHasNoInnerIterator(): void {
        $iterator = new class extends Iterator {
            public function generateChunks(int $chunkSize): void {}
            public function setIterationCallback(Closure $callback): void {}
            public function executeIterationCallback(): void {}
            public function current(): mixed { return null; }
            public function key(): mixed { return null; }
            public function next(): void {}
            public function rewind(): void {}
            public function valid(): bool { return false; }
            public function count(): int { return 0; }
        };

        self::assertNull($iterator->getInnerIterator());
    }
}
