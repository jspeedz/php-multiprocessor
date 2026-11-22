<?php
namespace Jspeedz\MultiProcessor\Logging\Monolog;

use Closure;
use Jspeedz\MultiProcessor\Logging\LogOutputLock;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\HandlerWrapper;
use Monolog\LogRecord;

/**
 * Wraps a Monolog handler that writes to output shared by forked processes (stdout/stderr), and holds the
 * LogOutputLock while it writes. A passthrough until the lock is enabled. The lock is shared by the process that
 * enabled it and its forks only, other commands writing to the same output are not locked out.
 *
 * A batch is written under a single lock, so it stays contiguous in the output.
 *
 * Requires monolog/monolog 3.
 */
class CrossProcessLockHandler extends HandlerWrapper {
    public function __construct(
        HandlerInterface $handler,
        private readonly LogOutputLock $lock,
    ) {
        parent::__construct($handler);
    }

    public function handle(LogRecord $record): bool {
        return $this->withLock(fn(): bool => parent::handle($record));
    }

    /**
     * @param array<LogRecord> $records
     */
    public function handleBatch(array $records): void {
        $this->withLock(function() use ($records): void {
            parent::handleBatch($records);
        });
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    private function withLock(Closure $callback): mixed {
        if(!$this->lock->isEnabled()) {
            return $callback();
        }

        $this->lock->acquire();
        try {
            return $callback();
        }
        finally {
            $this->lock->release();
        }
    }
}
