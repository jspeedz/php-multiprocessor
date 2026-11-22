<?php
namespace Jspeedz\MultiProcessor\Tests\Logging\Monolog;

use DateTimeImmutable;
use Jspeedz\MultiProcessor\Logging\LogOutputLock;
use Jspeedz\MultiProcessor\Logging\Monolog\CrossProcessLockHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CrossProcessLockHandler::class)]
final class CrossProcessLockHandlerTest extends TestCase {
    /**
     * @var list<string>
     */
    private array $calls = [];

    private HandlerInterface&MockObject $inner;

    private LogOutputLock&MockObject $lock;

    private function handler(bool $lockEnabled, int $expectedLocks): CrossProcessLockHandler {
        $this->calls = [];
        $this->inner = $this->createMock(HandlerInterface::class);
        $this->lock = $this->createMock(LogOutputLock::class);
        $this->lock->method('isEnabled')->willReturn($lockEnabled);
        $this->lock->expects(self::exactly($expectedLocks))->method('acquire')
            ->willReturnCallback(function(): void { $this->calls[] = 'acquire'; });
        $this->lock->expects(self::exactly($expectedLocks))->method('release')
            ->willReturnCallback(function(): void { $this->calls[] = 'release'; });

        return new CrossProcessLockHandler($this->inner, $this->lock);
    }

    private static function record(): LogRecord {
        return new LogRecord(new DateTimeImmutable(), 'app', Level::Notice, 'A message');
    }

    public static function lockStates(): iterable {
        yield 'enabled, handled' => [true, true, ['acquire', 'handle', 'release']];
        yield 'enabled, bubbles' => [true, false, ['acquire', 'handle', 'release']];
        yield 'disabled, handled' => [false, true, ['handle']];
        yield 'disabled, bubbles' => [false, false, ['handle']];
    }

    #[DataProvider('lockStates')]
    public function testHandleWritesUnderTheLockOnlyWhenEnabled(bool $lockEnabled, bool $handled, array $expectedCalls): void {
        $handler = $this->handler($lockEnabled, $lockEnabled ? 1 : 0);
        $record = self::record();
        $this->inner->expects(self::once())->method('handle')->with($record)
            ->willReturnCallback(function() use ($handled): bool {
                $this->calls[] = 'handle';
                return $handled;
            });

        self::assertSame($handled, $handler->handle($record));
        self::assertSame($expectedCalls, $this->calls);
    }

    public static function batchLockStates(): iterable {
        yield 'enabled' => [true, ['acquire', 'handleBatch', 'release']];
        yield 'disabled' => [false, ['handleBatch']];
    }

    #[DataProvider('batchLockStates')]
    public function testHandleBatchWritesTheWholeBatchUnderASingleLock(bool $lockEnabled, array $expectedCalls): void {
        $handler = $this->handler($lockEnabled, $lockEnabled ? 1 : 0);
        $records = [self::record(), self::record()];
        $this->inner->expects(self::once())->method('handleBatch')->with($records)
            ->willReturnCallback(function(): void { $this->calls[] = 'handleBatch'; });

        $handler->handleBatch($records);

        self::assertSame($expectedCalls, $this->calls);
    }

    public function testHandleReleasesTheLockWhenTheInnerHandlerThrows(): void {
        $handler = $this->handler(true, 1);
        $this->inner->expects(self::once())->method('handle')
            ->willThrowException(new RuntimeException('Write failed'));

        try {
            $handler->handle(self::record());
            self::fail('Expected exception was not thrown');
        }
        catch(RuntimeException $exception) {
            self::assertSame('Write failed', $exception->getMessage());
        }

        self::assertSame(['acquire', 'release'], $this->calls);
    }

    public function testHandleDoesNotWriteWhenTheLockCannotBeAcquired(): void {
        $inner = $this->createMock(HandlerInterface::class);
        $inner->expects(self::never())->method('handle');
        $lock = $this->createMock(LogOutputLock::class);
        $lock->method('isEnabled')->willReturn(true);
        $lock->expects(self::once())->method('acquire')
            ->willThrowException(new RuntimeException('Could not acquire log output lock'));
        $lock->expects(self::never())->method('release');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not acquire log output lock');

        new CrossProcessLockHandler($inner, $lock)->handle(self::record());
    }

    public function testIsHandlingIsDelegatedWithoutLocking(): void {
        $handler = $this->handler(true, 0);
        $record = self::record();
        $this->inner->expects(self::once())->method('isHandling')->with($record)->willReturn(false);

        self::assertFalse($handler->isHandling($record));
    }
}
