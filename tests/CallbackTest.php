<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Callback\Close\DoctrineConnections;
use Jspeedz\MultiProcessor\Callback\Iterator\ParentIsAlive;
use PHPUnit\Framework\TestCase;

final class CallbackTest extends TestCase {
    public function testDoctrineCallbackClosesEveryConnectionWhenInvoked(): void {
        $first = new class {
            public int $closed = 0;
            public function close(): void { $this->closed++; }
        };
        $second = clone $first;
        $registry = new class($first, $second) {
            public function __construct(private object $first, private object $second) {}
            public function getConnections(): array {
                return ['first' => $this->first, 'second' => $this->second];
            }
        };
        $callback = (new DoctrineConnections)->getCallback($registry);

        self::assertSame(0, $first->closed);
        self::assertSame(0, $second->closed);
        $callback();
        self::assertSame(1, $first->closed);
        self::assertSame(1, $second->closed);
        $callback();
        self::assertSame(2, $first->closed);
        self::assertSame(2, $second->closed);
    }

    public function testParentCheckAllowsIterationWhileParentIsAlive(): void {
        $callback = (new ParentIsAlive)->getCallback(posix_getpid());

        // Cross multiple ten-item intervals at which the OS check is performed.
        for($i = 0; $i < 25; $i++) {
            self::assertTrue($callback());
        }
    }
}
