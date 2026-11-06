<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessorTest extends TestCase {
    private function processor(): ProcessorAbstract {
        return new class extends ProcessorAbstract {
            public function process(): void {}
        };
    }

    public function testArraysAreWrappedAndReindexed(): void {
        $processor = $this->processor();
        $processor->setData([4 => 'a', 9 => 'b']);

        self::assertInstanceOf(ArrayIterator::class, $processor->getData());
        self::assertSame(['a', 'b'], iterator_to_array($processor->getData()));
    }

    public function testExistingIterableIsPreserved(): void {
        $processor = $this->processor();
        $data = new \ArrayIterator(['a' => 1]);
        $processor->setData($data);

        self::assertSame($data, $processor->getData());
        self::assertSame(['a' => 1], iterator_to_array($processor->getData()));
    }

    public static function callbackOrders(): iterable {
        yield 'callback before data' => [true];
        yield 'callback after data' => [false];
    }

    #[DataProvider('callbackOrders')]
    public function testParentCheckIsInstalledRegardlessOfSetterOrder(bool $callbackFirst): void {
        $processor = $this->processor();
        $calls = 0;
        $callback = function() use (&$calls): bool { $calls++; return true; };
        if($callbackFirst) {
            $processor->setParentAliveCheckCallback($callback);
        }
        $processor->setData([1, 2, 3]);
        if(!$callbackFirst) {
            $processor->setParentAliveCheckCallback($callback);
        }

        self::assertSame([1, 2, 3], iterator_to_array($processor->getData()));
        self::assertSame(3, $calls);

        $processor->setData([4]);
        self::assertSame([4], iterator_to_array($processor->getData()));
        self::assertSame(4, $calls, 'Replacing data must preserve the parent check.');
    }

    public function testReplacingParentCheckUpdatesTheExistingIterator(): void {
        $processor = $this->processor();
        $oldCalls = $newCalls = 0;
        $processor->setParentAliveCheckCallback(function() use (&$oldCalls): void { $oldCalls++; });
        $data = new ArrayIterator([1, 2]);
        $processor->setData($data);
        $processor->setParentAliveCheckCallback(function() use (&$newCalls): void { $newCalls++; });

        self::assertSame($data, $processor->getData());
        self::assertSame([1, 2], iterator_to_array($data));
        self::assertSame(0, $oldCalls);
        self::assertSame(2, $newCalls);
    }
}
