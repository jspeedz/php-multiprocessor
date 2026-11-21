<?php
namespace Jspeedz\MultiProcessor\Process;

use Jspeedz\MultiProcessor\Exception\UnsupportedException;

/**
 * The POSIX signals the parent and its children handle.
 *
 * @internal
 */
final class Signals {
    /**
     * Signals whose handler terminates (and so signals) all tracked children.
     */
    public const array TERMINATION = [
        SIGTERM, // Default kill command
        SIGQUIT, // Interrupt signal (CTRL+\)
        SIGINT, // Interrupt signal (CTRL+C)
    ];

    /**
     * Signals to bind to the signal handler.
     * Note: can't bind to SIGKILL as this event can't be overridden, it's just killed.
     */
    public const array HANDLED = [
        SIGTERM,
        SIGINT,
        SIGCHLD,
    ];

    /**
     * Bind the handler to the handled signals, delivered asynchronously where supported.
     *
     * Without asynchronous signal handling, signals are only delivered on ticks: every file with code that runs while
     * children are forked or waited for must `declare(ticks = 1)`, which applies at compile time to that file only.
     */
    public static function register(callable $handler): void {
        if(function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        foreach(self::HANDLED as $signal) {
            pcntl_signal($signal, $handler);
        }
    }

    public static function isTermination(int $signal): bool {
        return in_array($signal, self::TERMINATION, true);
    }

    /**
     * @throws UnsupportedException
     */
    public static function name(int $signal): string {
        return match($signal) {
            SIGCHLD => 'SIGCHLD',
            SIGINT => 'SIGINT',
            SIGQUIT => 'SIGQUIT',
            SIGTERM => 'SIGTERM',
            default => throw new UnsupportedException(sprintf(
                'Unknown signal string, add to method (%s)',
                $signal,
            )),
        };
    }
}
