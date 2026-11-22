<?php
namespace Jspeedz\MultiProcessor\Tests\Logging\Symfony\Console;

use Jspeedz\MultiProcessor\Logging\LogOutputLock;
use Jspeedz\MultiProcessor\Logging\Symfony\Console\LogOutputLockOptionTrait;
use LogicException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversTrait(LogOutputLockOptionTrait::class)]
final class LogOutputLockOptionTraitTest extends TestCase {
    private static function lock(): LogOutputLock {
        return new LogOutputLock();
    }

    public function testAddsAValuelessOption(): void {
        $option = new LogOutputLockOptionCommand()->getDefinition()->getOption('lock-log-output');

        self::assertSame(LogOutputLockOptionCommand::LOCK_LOG_OUTPUT_OPTION, $option->getName());
        self::assertFalse($option->acceptValue());
        self::assertFalse($option->getDefault());
        self::assertStringContainsString('slow down', $option->getDescription());
    }

    public static function inputs(): iterable {
        yield 'option given' => [['--lock-log-output' => true], true];
        yield 'option not given' => [[], false];
    }

    #[DataProvider('inputs')]
    public function testEnablesTheLockBeforeExecuteOnlyWhenOptedIn(array $input, bool $expectedEnabled): void {
        $lock = self::lock();
        $command = new LogOutputLockOptionCommand();
        $command->setLogOutputLock($lock);

        new CommandTester($command)->execute($input);

        self::assertSame($expectedEnabled, $command->enabledDuringExecute);
        self::assertSame($expectedEnabled, $lock->isEnabled());
    }

    public function testGetLogOutputLockReturnsTheInjectedLock(): void {
        $lock = self::lock();
        $command = new LogOutputLockOptionCommand();
        $command->setLogOutputLock($lock);

        self::assertSame($lock, $command->getLogOutputLock());
    }

    public function testGetLogOutputLockThrowsWhenNothingWasInjected(): void {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf(
            'No LogOutputLock was set on %s. Enable autowiring for the command, or call setLogOutputLock() before running it.',
            LogOutputLockOptionCommand::class,
        ));

        new LogOutputLockOptionCommand()->getLogOutputLock();
    }

    public function testCommandWithoutTheOptionRunsWithoutAnInjectedLock(): void {
        $command = new LogOutputLockOptionCommand();

        self::assertSame(0, new CommandTester($command)->execute([]));
        self::assertNull($command->enabledDuringExecute);
    }

    public function testOptionGivenWithoutAnInjectedLockFailsBeforeExecute(): void {
        $command = new LogOutputLockOptionCommand();

        try {
            new CommandTester($command)->execute(['--lock-log-output' => true]);
            self::fail('Expected exception was not thrown');
        }
        catch(LogicException) {
            self::assertNull($command->enabledDuringExecute);
        }
    }

    public function testSymfonyAutowiringInjectsTheLockThroughTheRequiredAttribute(): void {
        $container = new ContainerBuilder();
        $container->register(LogOutputLock::class)
            ->setArgument('$lockDirectory', sys_get_temp_dir())
            // Public only so the test can fetch it for the comparison
            ->setPublic(true);
        $container->register(LogOutputLockOptionCommand::class)
            ->setAutowired(true)
            ->setPublic(true);
        $container->compile();

        $command = $container->get(LogOutputLockOptionCommand::class);

        self::assertInstanceOf(LogOutputLockOptionCommand::class, $command);
        self::assertSame($container->get(LogOutputLock::class), $command->getLogOutputLock());
    }
}
