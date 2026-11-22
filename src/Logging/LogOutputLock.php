<?php
namespace Jspeedz\MultiProcessor\Logging;

use LogicException;
use RuntimeException;

/**
 * Exclusive lock around log writes, shared by one process and the children it forks, so records they write to a
 * shared pipe like stdout/stderr are never interleaved.
 *
 * Writes to a pipe are only atomic up to PIPE_BUF bytes (4096 on Linux, 512 on macOS). Larger log records written by
 * concurrent forks can be split and mixed into each other, which breaks line based (JSON) log output.
 *
 * Disabled by default, call enable() before forking. The process that calls enable() owns the lock: the lock file is
 * named after its PID, and children inherit that name when they fork. Other commands running at the same time get
 * their own lock file, so they never wait for each other. The owner removes the lock file when it exits.
 *
 * Holding the lock makes processes wait for each other while they log, which can slow down log-heavy runs.
 *
 * flock() locks belong to an open file description. Forked children inherit the parent's descriptors and would share
 * its lock instead of competing for it, so every process opens its own handle to the lock file.
 *
 * @see \Jspeedz\MultiProcessor\Logging\Monolog\CrossProcessLockHandler
 */
class LogOutputLock {
    private const string FILE_NAME_FORMAT = 'multiprocessor-log-output-%d.lock';

    private readonly string $lockDirectory;

    /**
     * PID of the process that enabled the lock, the parent of the forks sharing it.
     */
    private ?int $ownerPid = null;

    private ?string $lockFilePath = null;

    /**
     * @var resource|null
     */
    private $handle = null;

    private ?int $handlePid = null;

    /**
     * Nesting depth, a log write may trigger another one in the same process (signal handlers, error handlers).
     * Only the outermost release() unlocks.
     *
     * @var non-negative-int
     */
    private int $depth = 0;

    /**
     * @param string|null $lockDirectory Writable directory for the lock file, created on first use when missing. Defaults to sys_get_temp_dir().
     */
    public function __construct(
        ?string $lockDirectory = null,
    ) {
        $this->lockDirectory = rtrim($lockDirectory ?? sys_get_temp_dir(), '/');
    }

    /**
     * Enables the lock for this process and the children it forks from now on. Call it before forking.
     * Calling it again, also in a forked child, keeps the existing owner.
     */
    public function enable(): void {
        if($this->ownerPid !== null) {
            return;
        }

        $this->ownerPid = posix_getpid();
        $this->lockFilePath = sprintf('%s/' . self::FILE_NAME_FORMAT, $this->lockDirectory, $this->ownerPid);

        // Runs in the children too when they exit, removeLockFile() only acts in the owner
        register_shutdown_function($this->removeLockFile(...));
    }

    public function isEnabled(): bool {
        return $this->ownerPid !== null;
    }

    /**
     * The lock file shared by the owner and its children, null while the lock is disabled.
     */
    public function getLockFilePath(): ?string {
        return $this->lockFilePath;
    }

    /**
     * Blocks until this process holds the lock.
     *
     * @throws LogicException When the lock is not enabled
     * @throws RuntimeException When the lock file cannot be opened or locked
     */
    public function acquire(): void {
        $lockFilePath = $this->getEnabledLockFilePath();
        $this->resetAfterFork();

        if($this->depth > 0) {
            $this->depth++;
            return;
        }

        $handle = $this->getHandle($lockFilePath);
        if(!flock($handle, LOCK_EX)) {
            throw new RuntimeException(sprintf('Could not acquire log output lock "%s"', $lockFilePath));
        }

        $this->depth = 1;
    }

    /**
     * @throws LogicException When the lock is not held by this process
     * @throws RuntimeException When the lock cannot be released
     */
    public function release(): void {
        $this->resetAfterFork();

        if($this->depth === 0 || $this->handle === null) {
            throw new LogicException('Cannot release the log output lock, it is not held by this process');
        }

        $this->depth--;
        if($this->depth > 0) {
            return;
        }

        if(!flock($this->handle, LOCK_UN)) {
            throw new RuntimeException(sprintf('Could not release log output lock "%s"', $this->lockFilePath));
        }
    }

    /**
     * Removes the lock file once the owner is done. Registered as shutdown function by enable(), does nothing in
     * forked children or while the lock is held.
     */
    public function removeLockFile(): void {
        if($this->ownerPid !== posix_getpid() || $this->depth > 0 || $this->lockFilePath === null) {
            return;
        }

        if(is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        $this->handlePid = null;

        if(is_file($this->lockFilePath)) {
            unlink($this->lockFilePath);
        }
    }

    /**
     * @throws LogicException
     */
    private function getEnabledLockFilePath(): string {
        if($this->lockFilePath === null) {
            throw new LogicException('Cannot acquire the log output lock before it is enabled');
        }

        return $this->lockFilePath;
    }

    /**
     * Drops a handle (and lock state) inherited from the parent process.
     * Closing the inherited copy does not release a lock the parent holds, the parent keeps its own descriptor.
     */
    private function resetAfterFork(): void {
        if($this->handlePid === null || $this->handlePid === posix_getpid()) {
            return;
        }

        if(is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
        $this->handlePid = null;
        $this->depth = 0;
    }

    /**
     * @return resource
     *
     * @throws RuntimeException
     */
    private function getHandle(string $lockFilePath) {
        // The handle may have been closed externally, for example by the StreamResources close callback
        if(is_resource($this->handle)) {
            return $this->handle;
        }

        $this->createLockDirectory();

        $handle = @fopen($lockFilePath, 'c');
        if($handle === false) {
            throw new RuntimeException(sprintf(
                'Could not open log output lock file "%s": %s',
                $lockFilePath,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        $this->handle = $handle;
        $this->handlePid = posix_getpid();

        return $handle;
    }

    /**
     * @throws RuntimeException
     */
    private function createLockDirectory(): void {
        if(is_dir($this->lockDirectory)) {
            return;
        }

        // Children logging at the same time may race to create it, so only fail when it still does not exist
        if(!@mkdir($this->lockDirectory, 0777, true) && !is_dir($this->lockDirectory)) {
            throw new RuntimeException(sprintf(
                'Could not create log output lock directory "%s": %s',
                $this->lockDirectory,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }
    }
}
