<?php
namespace Jspeedz\MultiProcessor\Tests\Logging;

use Jspeedz\MultiProcessor\Logging\LogOutputLock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(LogOutputLock::class)]
final class LogOutputLockTest extends TestCase {
    private string $directory;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/' . uniqid('multiprocessor-log-lock-', true);
        self::assertTrue(mkdir($this->directory));
    }

    protected function tearDown(): void {
        self::removeRecursively($this->directory);
    }

    private static function removeRecursively(string $path): void {
        if(is_dir($path)) {
            foreach(glob($path . '/*') ?: [] as $child) {
                self::removeRecursively($child);
            }
            rmdir($path);
        }
        elseif(file_exists($path)) {
            unlink($path);
        }
    }

    private function enabledLock(): LogOutputLock {
        $lock = new LogOutputLock($this->directory);
        $lock->enable();

        return $lock;
    }

    private static function property(LogOutputLock $lock, string $name): mixed {
        return (new ReflectionProperty($lock, $name))->getValue($lock);
    }

    /**
     * Simulates the state a forked child inherits: same owner and lock file, but another PID.
     */
    private static function simulateFork(LogOutputLock $lock): void {
        if(self::property($lock, 'handlePid') !== null) {
            new ReflectionProperty($lock, 'handlePid')->setValue($lock, posix_getpid() + 1);
        }
        new ReflectionProperty($lock, 'ownerPid')->setValue($lock, posix_getpid() + 1);
    }

    /**
     * Whether another open file description, like another process would have, can take the lock right now.
     */
    private static function isLockAvailableToOthers(LogOutputLock $lock): bool {
        $handle = fopen((string) $lock->getLockFilePath(), 'c');
        self::assertNotFalse($handle);
        $locked = flock($handle, LOCK_EX | LOCK_NB);
        if($locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $locked;
    }

    public function testIsDisabledWithoutALockFileUntilEnabled(): void {
        $lock = new LogOutputLock($this->directory);

        self::assertFalse($lock->isEnabled());
        self::assertNull($lock->getLockFilePath());
    }

    public function testEnableNamesTheLockFileAfterTheEnablingProcess(): void {
        $lock = $this->enabledLock();

        self::assertTrue($lock->isEnabled());
        self::assertSame(
            sprintf('%s/multiprocessor-log-output-%d.lock', $this->directory, posix_getpid()),
            $lock->getLockFilePath(),
        );
        // The file is only created by the first acquire()
        self::assertFileDoesNotExist((string) $lock->getLockFilePath());
    }

    public function testTheLockDirectoryDefaultsToTheTemporaryDirectory(): void {
        $lock = new LogOutputLock();
        $lock->enable();

        self::assertSame(
            sprintf('%s/multiprocessor-log-output-%d.lock', rtrim(sys_get_temp_dir(), '/'), posix_getpid()),
            $lock->getLockFilePath(),
        );
    }

    public function testATrailingSlashOnTheDirectoryIsIgnored(): void {
        $lock = new LogOutputLock($this->directory . '/');
        $lock->enable();

        self::assertSame(
            sprintf('%s/multiprocessor-log-output-%d.lock', $this->directory, posix_getpid()),
            $lock->getLockFilePath(),
        );
    }

    public function testEnablingAgainInAForkedChildKeepsTheParentsLockFile(): void {
        $lock = $this->enabledLock();
        $parentLockFile = $lock->getLockFilePath();
        self::simulateFork($lock);

        $lock->enable();

        self::assertSame($parentLockFile, $lock->getLockFilePath());
    }

    public function testLocksEnabledByDifferentProcessesUseDifferentFiles(): void {
        $thisCommand = $this->enabledLock();
        $otherCommand = new LogOutputLock($this->directory);
        // Another command, enabled by another process
        new ReflectionProperty($otherCommand, 'ownerPid')->setValue($otherCommand, posix_getpid() + 1);
        new ReflectionProperty($otherCommand, 'lockFilePath')->setValue(
            $otherCommand,
            sprintf('%s/multiprocessor-log-output-%d.lock', $this->directory, posix_getpid() + 1),
        );

        $thisCommand->acquire();

        self::assertNotSame($thisCommand->getLockFilePath(), $otherCommand->getLockFilePath());
        self::assertTrue(self::isLockAvailableToOthers($otherCommand));
        $thisCommand->release();
    }

    public function testAcquireBeforeEnablingThrows(): void {
        $lock = new LogOutputLock($this->directory);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot acquire the log output lock before it is enabled');

        $lock->acquire();
    }

    public function testAcquireHoldsAnExclusiveLockUntilReleased(): void {
        $lock = $this->enabledLock();

        $lock->acquire();
        self::assertFalse(self::isLockAvailableToOthers($lock));

        $lock->release();
        self::assertTrue(self::isLockAvailableToOthers($lock));
    }

    public function testNestedAcquireOnlyUnlocksOnTheOutermostRelease(): void {
        $lock = $this->enabledLock();
        $lock->acquire();
        $lock->acquire();

        $lock->release();
        self::assertFalse(self::isLockAvailableToOthers($lock));
        self::assertSame(1, self::property($lock, 'depth'));

        $lock->release();
        self::assertTrue(self::isLockAvailableToOthers($lock));
        self::assertSame(0, self::property($lock, 'depth'));
    }

    public function testReleaseWithoutAcquireThrows(): void {
        $lock = $this->enabledLock();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot release the log output lock, it is not held by this process');

        $lock->release();
    }

    public function testReleasingMoreOftenThanAcquiredThrows(): void {
        $lock = $this->enabledLock();
        $lock->acquire();
        $lock->release();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot release the log output lock, it is not held by this process');

        $lock->release();
    }

    public function testAcquireCreatesAMissingLockDirectory(): void {
        $directory = $this->directory . '/cache/php-multiprocessor/locks';
        $lock = new LogOutputLock($directory);
        $lock->enable();

        $lock->acquire();

        self::assertDirectoryExists($directory);
        self::assertFileExists((string) $lock->getLockFilePath());
        self::assertFalse(self::isLockAvailableToOthers($lock));
        $lock->release();
    }

    public function testAcquireThrowsWhenTheLockDirectoryCannotBeCreated(): void {
        // A regular file in the way of the directory
        self::assertNotFalse(file_put_contents($this->directory . '/file', ''));
        $directory = $this->directory . '/file/locks';
        $lock = new LogOutputLock($directory);
        $lock->enable();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Could not create log output lock directory "%s": ', $directory));

        $lock->acquire();
    }

    public function testAcquireThrowsWhenTheLockFileCannotBeOpened(): void {
        $lock = $this->enabledLock();
        // A directory in the way of the lock file
        self::assertTrue(mkdir((string) $lock->getLockFilePath()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Could not open log output lock file "%s": ', $lock->getLockFilePath()));

        $lock->acquire();
    }

    public function testAcquireReusesTheHandleWithinTheSameProcess(): void {
        $lock = $this->enabledLock();
        $lock->acquire();
        $lock->release();
        $handle = self::property($lock, 'handle');

        $lock->acquire();
        $lock->release();

        self::assertSame($handle, self::property($lock, 'handle'));
    }

    public function testAcquireReopensAHandleThatWasClosedExternally(): void {
        $lock = $this->enabledLock();
        $lock->acquire();
        $lock->release();
        $handle = self::property($lock, 'handle');
        self::assertIsResource($handle);
        // Like the StreamResources close callback does before forking
        fclose($handle);

        $lock->acquire();

        $newHandle = self::property($lock, 'handle');
        self::assertIsResource($newHandle);
        self::assertNotSame($handle, $newHandle);
        self::assertFalse(self::isLockAvailableToOthers($lock));
        $lock->release();
    }

    public function testAcquireAfterForkOpensANewHandleToTheSameFileAndResetsTheInheritedDepth(): void {
        $lock = $this->enabledLock();
        $lockFile = $lock->getLockFilePath();
        $lock->acquire();
        $inheritedHandle = self::property($lock, 'handle');
        self::simulateFork($lock);

        $lock->acquire();

        self::assertIsClosedResource($inheritedHandle);
        self::assertIsResource(self::property($lock, 'handle'));
        self::assertSame(posix_getpid(), self::property($lock, 'handlePid'));
        self::assertSame(1, self::property($lock, 'depth'));
        self::assertSame($lockFile, $lock->getLockFilePath());

        $lock->release();
        self::assertTrue(self::isLockAvailableToOthers($lock));
    }

    public function testReleaseAfterForkThrowsForALockOnlyTheParentHeld(): void {
        $lock = $this->enabledLock();
        $lock->acquire();
        self::simulateFork($lock);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot release the log output lock, it is not held by this process');

        $lock->release();
    }

    public function testRemoveLockFileRemovesTheOwnersFileAndClosesItsHandle(): void {
        $lock = $this->enabledLock();
        $lock->acquire();
        $lock->release();
        $handle = self::property($lock, 'handle');
        self::assertFileExists((string) $lock->getLockFilePath());

        $lock->removeLockFile();

        self::assertFileDoesNotExist((string) $lock->getLockFilePath());
        self::assertIsClosedResource($handle);
        self::assertNull(self::property($lock, 'handle'));
    }

    public function testRemoveLockFileDoesNothingInAForkedChild(): void {
        $lock = $this->enabledLock();
        $lock->acquire();
        $lock->release();
        self::simulateFork($lock);

        $lock->removeLockFile();

        self::assertFileExists((string) $lock->getLockFilePath());
    }

    public function testRemoveLockFileDoesNothingWhileTheLockIsHeld(): void {
        $lock = $this->enabledLock();
        $lock->acquire();

        $lock->removeLockFile();

        self::assertFileExists((string) $lock->getLockFilePath());
        self::assertFalse(self::isLockAvailableToOthers($lock));
        $lock->release();
    }

    public function testRemoveLockFileIsANoOpWhenDisabledOrNothingWasWritten(): void {
        $disabled = new LogOutputLock($this->directory);
        $disabled->removeLockFile();

        $unused = $this->enabledLock();
        $unused->removeLockFile();

        self::assertSame([], glob($this->directory . '/*'));
    }
}
