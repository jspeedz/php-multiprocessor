<?php
namespace Jspeedz\MultiProcessor\Tests\Process;

use Jspeedz\MultiProcessor\Exception\UnsupportedException;
use Jspeedz\MultiProcessor\Process\Signals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(Signals::class)]
final class SignalsTest extends TestCase {
    #[TestWith([SIGCHLD, 'SIGCHLD'])]
    #[TestWith([SIGINT, 'SIGINT'])]
    #[TestWith([SIGQUIT, 'SIGQUIT'])]
    #[TestWith([SIGTERM, 'SIGTERM'])]
    public function testSignalNames(int $signal, string $name): void {
        self::assertSame($name, Signals::name($signal));
    }

    public function testUnknownSignalNamesAreRejected(): void {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Unknown signal string, add to method (' . SIGUSR1 . ')');
        Signals::name(SIGUSR1);
    }

    #[TestWith([SIGTERM, true])]
    #[TestWith([SIGQUIT, true])]
    #[TestWith([SIGINT, true])]
    #[TestWith([SIGCHLD, false])]
    #[TestWith([SIGKILL, false])]
    public function testTerminationSignals(int $signal, bool $termination): void {
        self::assertSame($termination, Signals::isTermination($signal));
    }
}
