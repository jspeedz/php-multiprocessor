<?php
namespace Jspeedz\MultiProcessor\Tests\Iterator;

use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayIterator::class)]
final class ArrayIteratorTest extends TestCase {
    public static function chunks(): iterable {
        yield 'empty' => [[], 2, true, []];
        yield 'partial final chunk' => [[10, 20, 30], 2, true, [[10, 20], [2 => 30]]];
        yield 'one item per chunk' => [[10, 20], 1, true, [[10], [1 => 20]]];
        yield 'chunk larger than input' => [[10, 20], 10, true, [[10, 20]]];
        yield 'sparse keys reindexed' => [[4 => 'a', 9 => 'b'], 1, true, [['a'], [1 => 'b']]];
        yield 'associative keys reindexed' => [['a' => 10, 'b' => 20], 2, true, [[10, 20]]];
        yield 'preserved sparse keys' => [[0 => 'a', 9 => 'b', 12 => 'c'], 2, false, [[0 => 'a', 9 => 'b'], [12 => 'c']]];
    }

    #[DataProvider('chunks')]
    public function testChunking(array $data, int $size, bool $reindex, array $expected): void {
        $iterator = new ArrayIterator($data, $reindex);
        $iterator->generateChunks($size);

        self::assertCount(count($expected), $iterator);
        self::assertSame($expected, iterator_to_array($iterator));
        self::assertSame($expected, iterator_to_array($iterator), 'Iteration must be repeatable.');
    }

    public function testCursorAndRewind(): void {
        $iterator = new ArrayIterator([5 => 'a', 6 => 'b'], false);

        self::assertSame(5, $iterator->key());
        self::assertSame('a', $iterator->current());
        self::assertTrue($iterator->valid());
        self::assertSame('b', $iterator->next());
        self::assertSame(6, $iterator->key());
        self::assertFalse($iterator->next());
        self::assertFalse($iterator->valid());
        self::assertFalse($iterator->current());
        self::assertSame('a', $iterator->rewind());
        self::assertSame(5, $iterator->key());
    }

    public function testEmptyIteratorHasNoCurrentItem(): void {
        $iterator = new ArrayIterator([]);

        self::assertFalse($iterator->rewind());
        self::assertFalse($iterator->current());
        self::assertFalse($iterator->valid());
        self::assertNull($iterator->key());
    }

    public function testCallbackRunsOnEveryAdvanceIncludingPastTheLastItem(): void {
        $iterator = new ArrayIterator(['a', 'b']);
        $calls = 0;
        $iterator->setIterationCallback(function() use (&$calls): void { $calls++; });

        $iterator->rewind();
        $iterator->current();
        self::assertSame(0, $calls);
        self::assertSame('b', $iterator->next());
        self::assertSame(1, $calls);
        self::assertFalse($iterator->next());
        self::assertSame(2, $calls);
    }

    public static function invalidSizes(): iterable {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidSizes')]
    public function testInvalidChunkSizeIsRejected(int $size): void {
        $iterator = new ArrayIterator([1]);
        $this->expectException(\ValueError::class);
        $iterator->generateChunks($size);
    }
}
