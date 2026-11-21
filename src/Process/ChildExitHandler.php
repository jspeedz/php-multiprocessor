<?php
namespace Jspeedz\MultiProcessor\Process;

use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Exception\NonCleanExitException;
use Jspeedz\MultiProcessor\Settings;

/**
 * Interprets the exit status of reaped children, and applies the stop on child fatal policy when one crashed.
 *
 * @internal
 */
final class ChildExitHandler {
    /**
     * At least one child failed: the parent must exit non-zero once the run is over.
     */
    public private(set) bool $childFailed = false;

    /**
     * A child fataled with the `graceful` stop method: running children complete, no new ones are forked.
     */
    public private(set) bool $gracefulShutdown = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly ChildProcesses $children,
    ) {}

    /**
     * Handle the exit status of a reaped child.
     *
     * @throws NonCleanExitException
     */
    public function handle(int $pid, int $status): void {
        if(!pcntl_wifsignaled($status) && !pcntl_wifexited($status)) {
            // Non-clean exit
            throw new NonCleanExitException('Non clean exit?');
        }

        // The child is reaped, so make sure its PID is forgotten before anything below signals the remaining
        // children: the kernel may hand this PID to an unrelated process. ChildProcesses normally did this already.
        $this->children->forget($pid);

        if(pcntl_wifsignaled($status)) {
            // Child was killed by a signal it didn't handle (segfault, OOM killer, external SIGKILL, ...).
            // Its chunk was not (fully) processed, so this is a child fatal.
            echo PHP_EOL . 'A child was terminated by unhandled signal ' . pcntl_wtermsig($status) . ' (see the SIG* PHP constants)' . PHP_EOL;

            $this->handleFatal();
            return;
        }

        // Clean exit, exit code returned by processor in exit() statement
        $exitCode = pcntl_wexitstatus($status);
        if($exitCode !== 0) {
            $this->childFailed = true;

            // Child might have fataled: PHP exit code of 1 or 255.
            // A child interrupted by a termination signal exits with 128 + signal instead: failed, but no fatal.
            if(in_array($exitCode, [1, 255], true)) {
                $this->handleFatal();
            }
        }
    }

    /**
     * A child crashed: mark the run as failed and stop the other children, depending on the settings.
     */
    private function handleFatal(): void {
        $this->childFailed = true;

        if($this->gracefulShutdown || !$this->settings->stopOnChildFatal) {
            return;
        }

        if($this->settings->stopOnFatalMethod === StopOnFatalMethodEnum::GRACEFUL) {
            echo PHP_EOL . 'A child fataled, shutting down ' . $this->children->count() . ' processes (currently running children will complete)' . PHP_EOL;

            $this->gracefulShutdown = true;
            return;
        }

        echo PHP_EOL . 'A child fataled, shutting down ' . $this->children->count() . ' processes (currently running children will be terminated)' . PHP_EOL;

        $this->children->terminate(
            $this->settings->stopOnFatalMethod === StopOnFatalMethodEnum::NORMAL ? SIGTERM : SIGKILL,
            $this->settings->terminateGracePeriod,
        );

        exit(1);
    }
}
