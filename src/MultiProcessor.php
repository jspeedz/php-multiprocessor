<?php
namespace Jspeedz\MultiProcessor;

use Closure;
use Jspeedz\MultiProcessor\Callback\Iterator\ParentIsAlive;
use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Exception\InvalidGracePeriodException;
use Jspeedz\MultiProcessor\Exception\InvalidPriorityException;
use Jspeedz\MultiProcessor\Exception\NonCleanExitException;
use Jspeedz\MultiProcessor\Exception\ProcessException;
use Jspeedz\MultiProcessor\Exception\UnsupportedException;
use Jspeedz\MultiProcessor\Iterator\IteratorAbstract;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
use Random\RandomException;

/**
 * @todo Logging via monolog
 * @todo Progress bar, inject. Use package?
 */
class MultiProcessor {
    protected int $parentPID;

    /**
     * @var array{
     *     priority: int,
     *     concurrentChildren: positive-int,
     *     chunkSize: positive-int,
     *     stopOnParentFatal: bool,
     *     stopOnChildFatal: bool,
     *     stopOnFatalMethod: StopOnFatalMethodEnum,
     *     terminateGracePeriod: float,
     *     useProgressBar: bool,
     * }
     */
    private array $settings = [
        'priority' => 19,
        'concurrentChildren' => 1,
        'chunkSize' => 10,
        'stopOnParentFatal' => true,
        'stopOnChildFatal' => true,
        'stopOnFatalMethod' => StopOnFatalMethodEnum::GRACEFUL,
        // Seconds children get to exit after SIGTERM on a `normal` fatal shutdown, before they are killed with SIGKILL
        'terminateGracePeriod' => 10.0,
        'useProgressBar' => true,
    ];

    /**
     * @var Closure[]
     */
    private array $closeResourceOnceCallbacks = [];

    /**
     * @var Closure[]
     */
    private array $closeResourceAlwaysCallbacks = [];

    private IteratorAbstract $data;

    private ProcessorAbstract $processor;

    /**
     * @var non-negative-int
     */
    private int $currentRunningChildren = 0;

    /**
     * Signals to bind to the signal handler.
     * Note: can't bind to SIGKILL as this event can't be overridden, it's just killed.
     *
     * @var list<int>
     */
    private array $signalHandlerEvent = [
        SIGTERM,
        SIGINT,
        SIGCHLD
    ];

    /**
     * @var int[]
     */
    private array $childPIDs = [];

    /**
     * @var non-negative-int
     */
    private int $chunksProcessed = 0;

    /**
     * @var positive-int
     */
    private int $timeStarted;

    private bool $gracefulShutdown = false;

    private bool $childFailed = false;

    /**
     * Seconds to wait for SIGKILLed children to be reaped. A child stuck in an uninterruptible system call
     * (like I/O on a dead NFS mount) only dies once that call returns, so the parent gives up waiting on it.
     */
    private float $killReapTimeout = 5.0;

    /**
     * Longest sleep between checks on the children while a process we didn't fork stays waitable.
     * Any SIGCHLD cuts the sleep short, so this only bounds the delay when a child exit slips in just before it.
     */
    private int $foreignExitPollMicroseconds = 1_000_000;

    /**
     * Number of SIGCHLD signals received, to tell whether a child exited since the children were last checked.
     *
     * @var non-negative-int
     */
    private int $childSignals = 0;

    /**
     * Signals whose handler terminates (and so signals) all tracked children.
     */
    private const array TERMINATION_SIGNALS = [
        SIGTERM,  // Default kill command
        SIGQUIT, // interrupt signal (CTRL+\)
        SIGINT, // interrupt signal (CTRL+C)
    ];

    /**
     * @var bool $stopProcessing If true, will kill the chunk loop
     */
    private bool $stopProcessing = false;

    public function __construct(IteratorAbstract $data, ProcessorAbstract $processor) {
        $this->data = $data;
        $this->processor = $processor;

        // Signal handlers are only registered by run(), which sets this again. This keeps a direct signalHandler() call
        // before run() from reading an uninitialized property.
        $this->parentPID = posix_getpid();
    }

    /**
     * Register the signal handlers for the process that is about to fork, see run().
     *
     * Keep this method above the code that forks and waits for children: declare(ticks) applies at compile time to the
     * code that follows it in this file, regardless of the condition around it.
     */
    private function registerSignalHandlers(): void {
        $useTicks = !function_exists('pcntl_async_signals');
        if(!$useTicks) {
            pcntl_async_signals(true);
            $useTicks = !pcntl_async_signals(null);
        }
        if($useTicks) {
            // Asynchronous signal handling is not supported, so usage of ticks is required, resulting in a bit more overhead.
            declare(ticks = 1);
        }

        $signalHandler = [$this, 'signalHandler'];
        foreach($this->signalHandlerEvent as $signal) {
            pcntl_signal($signal, $signalHandler);
        }
    }

    /**
     * The number of processes to fork at any given moment.
     *
     * @param positive-int $children
     */
    public function setMaximumConcurrentChildren(int $children): void {
        $this->settings['concurrentChildren'] = $children;
    }

    /**
     * Items to handle per chunk/child.
     *
     * @param positive-int $chunkSize
     */
    public function setChunkSize(int $chunkSize): void {
        $this->settings['chunkSize'] = $chunkSize;
    }

    /**
     * Set the priority for the child processes. Defaults to 19 if not set.
     *
     * @param int<-20, 19> $priority −20 is the highest priority and 19 the lowest.
     *
     * @throws InvalidPriorityException
     */
    public function setPriority(int $priority): void {
        if($priority < -20 || $priority > 19) {
            throw new InvalidPriorityException('Invalid process priority (' . $priority . ')');
        }
        $this->settings['priority'] = $priority;
    }

    /**
     * Enables stopping all processes when the parent/management process fatals
     *
     * @param bool $stopOnParentFatal = true
     */
    public function stopOnParentFatal(bool $stopOnParentFatal = true): void {
        $this->settings['stopOnParentFatal'] = $stopOnParentFatal;
    }

    /**
     * Enables stopping all processes when a child process fatals
     * Disabling this still makes the parent exit non-zero after all children finish if any child failed.
     *
     * @param bool $stopOnChildFatal = true
     */
    public function stopOnChildFatal(bool $stopOnChildFatal = true): void {
        $this->settings['stopOnChildFatal'] = $stopOnChildFatal;
    }

    /**
     * Enables stopping all processes when a child process fatals.
     *
     * @param StopOnFatalMethodEnum $stopOnFatalMethod = `graceful` will let running children complete, `normal` will kill all children with SIGTERM (and SIGKILL after the grace period, see setTerminateGracePeriod()), `immediate` will kill all children instantly SIGKILL
     */
    public function stopOnFatalMethod(
        StopOnFatalMethodEnum $stopOnFatalMethod = StopOnFatalMethodEnum::GRACEFUL,
    ): void {
        $this->settings['stopOnFatalMethod'] = $stopOnFatalMethod;
    }

    /**
     * How long children get to shut down after SIGTERM, when the `normal` stop on fatal method stops them.
     * Children still running after this period (blocked in a system call, ignoring SIGTERM, ...) are killed with SIGKILL.
     * Defaults to 10 seconds. Has no effect on the `graceful` and `immediate` methods.
     *
     * @param float $seconds 0 or more, 0 sends SIGKILL to children that didn't exit on SIGTERM right away.
     *
     * @throws InvalidGracePeriodException
     */
    public function setTerminateGracePeriod(float $seconds): void {
        if(!is_finite($seconds) || $seconds < 0) {
            throw new InvalidGracePeriodException('Invalid terminate grace period (' . var_export($seconds, true) . '), must be 0 seconds or more');
        }
        $this->settings['terminateGracePeriod'] = $seconds;
    }

    /**
     * Show a progress bar? Note that the time estimate is very rough.
     * It'll only be accurate when every handled item takes exactly the same time to process.
     *
     * @todo Refactor this, inject a progress bar if you need one (same goes for logger)
     */
    public function useProgressBar(bool $useProgressBar = true): void {
        $this->settings['useProgressBar'] = $useProgressBar;
    }

    /**
     * Register closures to close resource handles before forking.
     *
     * Makes sure all forks create their own resource handles by closing the current ones.
     * Re-using resources like file handles, MySQL or network connections will cause trouble when forks try to use them.
     * They will try to use the same handles/connections and collide.
     *
     * @param Closure $callback
     * @param ResourceCloseCallbackTriggerEnum $trigger always|once (always run callbacks before forking, or once before the first fork)
     */
    public function addCloseResourceCallback(
        Closure $callback,
        ResourceCloseCallbackTriggerEnum $trigger = ResourceCloseCallbackTriggerEnum::ALWAYS,
    ): void {
        if($trigger === ResourceCloseCallbackTriggerEnum::ONCE) {
            $this->closeResourceOnceCallbacks[] = $callback;
        }
        else {
            $this->closeResourceAlwaysCallbacks[] = $callback;
        }
    }

    /**
     * @param int $signal
     *
     * @return string
     *
     * @throws UnsupportedException
     */
    private function getStringFromSignal(int $signal): string {
        return match($signal) {
            SIGCHLD => 'SIGCHLD',
            SIGINT => 'SIGINT',
            SIGQUIT => 'SIGQUIT',
            SIGTERM => 'SIGTERM',
            default => throw new UnsupportedException('Unknown signal string, add to method (' . $signal . ')'),
        };
    }

    /**
     * @param non-negative-int $signo The signal being handled
     * @param mixed $siginfo If operating systems supports siginfo_t structures, this will be an array of signal information dependent on the signal
     *
     * @throws ProcessException
     * @throws UnsupportedException
     */
    public function signalHandler(int $signo, mixed $siginfo): void {
        if($this->parentPID === posix_getpid()) {
            // Parent
            // SIGTERM: default kill command, SIGQUIT: CTRL+\, SIGINT: CTRL+C
            if(in_array($signo, self::TERMINATION_SIGNALS, true)) {
                $this->stopProcessing = true;
                $this->terminateChildren(SIGKILL);

                exit(1);
            }
            if($signo === SIGCHLD) {
                // Child has terminated: it is reaped by waitForChildren(), which only needs to know it happened
                $this->childSignals++;
                return;
            }

            throw new ProcessException('Caught undefined signal (' . $this->getStringFromSignal($signo) . ')');
        }
        else {
            // Child
            if($signo === SIGCHLD) {
                // Terminated myself (should be a signal to the parent, but somehow sent to child as well)..
                // Nothing to do here
                return;
            }
            else if(in_array($signo, self::TERMINATION_SIGNALS, true)) {
                // Interrupted mid-chunk: exit like an uncaught signal would (128 + signal), so the parent sees the chunk failed.
                // Not 1 or 255: those are child fatals to the parent, which would stop the remaining children.
                // Let's kill myself
                exit(128 + $signo);
            }

            throw new ProcessException('Caught undefined signal (' . $this->getStringFromSignal($signo) . ')');
        }
    }

    /**
     * Run the data through the processor.
     *
     * @throws NonCleanExitException
     * @throws ProcessException
     * @throws UnsupportedException
     * @throws RandomException
     */
    public function run(): void {
        // The process that runs is the one that forks, so it is the parent the children check for. It is not necessarily
        // the process that constructed this object (that one may have forked since). Handlers are registered only now,
        // so signalHandler() never decides between parent and child based on a stale PID.
        $this->parentPID = posix_getpid();
        $this->registerSignalHandlers();
        $this->data->generateChunks($this->settings['chunkSize']);
        $this->timeStarted = time();

        if($this->settings['useProgressBar']) {
            echo 'Chunks                    Progress                           ' . str_repeat(' ', strlen((string) $this->data->count()) * 2 + 1) . 'Elapsed  / Remaining';
            echo "\r" . $this->getRunStatistics();
        }

        $this->closeAllResources(ResourceCloseCallbackTriggerEnum::ONCE);

        foreach($this->data as $chunk) {
            if($this->stopProcessing) {
                // We should not continue!
                break;
            }

            if(!is_iterable($chunk)) {
                throw new UnsupportedException('Each data chunk must be iterable');
            }

            $processor = clone $this->processor;
            $this->currentRunningChildren++;

            $this->closeAllResources(ResourceCloseCallbackTriggerEnum::ALWAYS);

            $pid = pcntl_fork();
            if($pid === -1) {
                throw new ProcessException('Could not fork');
            }
            else if($pid === 0) {
                // This is a child.
                $this->seedRandomGenerator();

                $processor->setData($chunk);
                if($this->settings['stopOnParentFatal']) {
                    $processor->setParentAliveCheckCallback(
                        (new ParentIsAlive)->getCallback($this->parentPID)
                    );
                }
                $processor->initialize();
                $processor->process();
                $processor->finish();
                $processor->exit();
            }
            else if($pid > 0) {
                // This is the parent, $pid contains the child PID.
                pcntl_setpriority($this->settings['priority'], $pid);

                $this->childPIDs[] = $pid;

                if($this->currentRunningChildren >= $this->settings['concurrentChildren'] || ($this->currentRunningChildren > 0 && $this->gracefulShutdown)) {
                    $this->waitForChildren(false);
                }

                if($this->gracefulShutdown) {
                    // Should gracefully shut down, don't execute any more chunks.
                    if($this->currentRunningChildren > 0) {
                        $this->waitForChildren(true);
                    }
                    exit(2);
                }
            }
        }

        if(isset($pid) && $pid > 0) {
            // This is the parent, children will have exited before here.
            $this->waitForChildren(true); // Wait for the remaining children.
            if($this->settings['useProgressBar']) {
                echo PHP_EOL;
            }

            if($this->childFailed) {
                // There was at least one child that crashed.
                // Make sure the parent returns a non-zero exit code, even with graceful shutdown enabled.
                exit(2);
            }
        }
    }

    /**
     * @return string
     */
    private function getRunStatistics(): string {
        $elapsedTime = time() - $this->timeStarted;
        if(empty($this->chunksProcessed)) {
            return  PHP_EOL . sprintf('%1$\' ' . (strlen((string) $this->data->count()) * 2 + 1) . 's', '0/' . $this->data->count()) . '    ' .
            ' [' . str_repeat(' ', 41) . '] ' . sprintf('%1$\' 6.2f%%', 0) .
            '     00:00:00 / ??:??:??';
        }
        $averageTimePerChunk = $elapsedTime / $this->chunksProcessed;
        $timeToGo = ceil($averageTimePerChunk * ($this->data->count() - $this->chunksProcessed));

        $s = $elapsedTime % 60;
        $m = (($elapsedTime - $s) % 3600) / 60;
        $h = ($elapsedTime - ($elapsedTime % 3600)) / 3600;
        $elapsedTime = ($h < 10 ? '0' : '') . (string) $h . ':'  . ($m < 10 ? '0' : '') . (string) $m . ':' . ($s < 10 ? '0' : '') . (string) $s;

        $s = $timeToGo % 60;
        $m = (($timeToGo - $s) % 3600) / 60;
        $h = ($timeToGo - ($timeToGo % 3600)) / 3600;
        $timeToGo = ($h < 10 ? '0' : '') . $h . ':'  . ($m < 10 ? '0' : '') . $m . ':' . ($s < 10 ? '0' : '') . $s;

        $percentage = $this->chunksProcessed * 100 / $this->data->count();

//        return "\r" . $this->chunksProcessed . '/' . $this->data->count() . '    ' .
        return "\r" . sprintf('%1$\' ' . (strlen((string) $this->data->count()) * 2 + 1) . 's', $this->chunksProcessed . '/' . $this->data->count()) . '    ' .
        ' [' . str_repeat('=', (int) round($percentage / 2.5)) . '>' . str_repeat(' ', 40 - (int) round($percentage / 2.5)) . '] ' . sprintf('%1$\' 6.2f%%', $percentage) .
        '     ' . $elapsedTime . ' / ' . $timeToGo;
    }

    /**
     * @param bool $finalChildren If this current wait is on the final children, or in between chunks
     *
     * @throws NonCleanExitException
     * @throws ProcessException When tracked children can no longer be waited for (reaped elsewhere)
     */
    private function waitForChildren(bool $finalChildren): void {
        // Final: wait until all forks are done processing.
        // Otherwise: wait until we can start the next fork, at least one of our children frees up a slot.
        //
        // Only our own children are reaped, by PID: reaping any child would also reap processes the caller
        // started itself (like popen()), stealing their exit status from pclose()/proc_close().
        while($this->childPIDs !== []) {
            $childSignals = $this->childSignals;
            $reaped = false;
            foreach($this->childPIDs as $childPID) {
                $result = $this->reapChild($childPID, $status, WNOHANG);
                if($result === 0) {
                    // Still running
                    continue;
                }
                if($result === -1) {
                    if(pcntl_get_last_error() === PCNTL_EINTR) {
                        continue;
                    }
                    // Without this, the concurrency limit would be exceeded and a failed child would go unnoticed.
                    throw new ProcessException('Lost track of child process ' . $childPID . ': it was reaped elsewhere');
                }

                $this->executeWaitForChildren($result, $status);
                $reaped = true;
            }

            if($reaped) {
                if(!$finalChildren) {
                    return;
                }
                continue;
            }

            $this->sleepUntilAChildExits($childSignals);
        }
    }

    /**
     * Block until any child process has exited, without reaping it.
     *
     * @param non-negative-int $childSignals The SIGCHLD count from before the children were last checked
     */
    private function sleepUntilAChildExits(int $childSignals): void {
        // WNOWAIT leaves an exited child waitable, for us to reap, or for pclose() when it isn't ours.
        // First check without blocking whether a process is waitable already: when it isn't ours, it stays
        // waitable until its owner reaps it, and waiting for it would return right away, over and over.
        if(!pcntl_waitid(P_ALL, 0, $info, WEXITED | WNOWAIT | WNOHANG) || !is_array($info)) {
            // Interrupted, or no children left at all: check our children again
            return;
        }
        $pid = $info['pid'] ?? 0;
        if($pid === 0) {
            // Nothing has exited yet: block until any child exits
            pcntl_waitid(P_ALL, 0, $info, WEXITED | WNOWAIT);
            return;
        }
        if(in_array($pid, $this->childPIDs, true) || $this->childSignals !== $childSignals) {
            // One of ours is waitable, or a child exited since we last checked
            return;
        }

        // Only a process we didn't fork is waitable: sleep until the next SIGCHLD, which interrupts the sleep.
        usleep($this->foreignExitPollMicroseconds);
    }

    /**
     * Wait for a tracked child, and forget it once it is reaped.
     *
     * Termination signals are blocked meanwhile: their handler signals all tracked children,
     * and must never see a PID that is reaped already, as the kernel may hand it to an unrelated process.
     *
     * @param-out int $status Set by pcntl_waitpid()
     *
     * @return int The waitpid() result: the child PID when reaped, 0 when still running (WNOHANG), -1 on errors
     */
    private function reapChild(int $childPID, ?int &$status, int $flags = 0): int {
        $previousMask = [];
        pcntl_sigprocmask(SIG_BLOCK, self::TERMINATION_SIGNALS, $previousMask);
        try {
            $result = pcntl_waitpid($childPID, $status, $flags);
            if($result > 0 && (pcntl_wifexited($status) || pcntl_wifsignaled($status))) {
                $this->forgetChild($result);
            }
        }
        finally {
            // A termination signal that arrived meanwhile is handled right here
            pcntl_sigprocmask(SIG_SETMASK, $previousMask);
        }

        return $result;
    }

    /**
     * Handle the exit status of a reaped child.
     *
     * @throws NonCleanExitException
     */
    private function executeWaitForChildren(int $childPID, int $status): void {
        // @todo do logging with the exit status?

        if(!pcntl_wifsignaled($status) && !pcntl_wifexited($status)) {
            // Non-clean exit
            throw new NonCleanExitException('Non clean exit?');
        }

        // The child is reaped, so make sure its PID is forgotten before anything below signals the remaining
        // children: the kernel may hand this PID to an unrelated process. reapChild() normally did this already.
        $this->forgetChild($childPID);

        if(pcntl_wifsignaled($status)) {
            // Child was killed by a signal it didn't handle (segfault, OOM killer, external SIGKILL, ...).
            // Its chunk was not (fully) processed, so this is a child fatal.
            echo PHP_EOL . 'A child was terminated by unhandled signal ' . pcntl_wtermsig($status) . ' (see the SIG* PHP constants)' . PHP_EOL;

            $this->handleChildFatal();
        }
        else {
            // Clean exit, exit code returned by processor in exit() statement
            $exitCode = pcntl_wexitstatus($status);
            if($exitCode !== 0) {
                $this->childFailed = true;

                // Child might have fataled: PHP exit code of 1 or 255
                if(in_array($exitCode, [
                    1,
                    255,
                ], true)) {
                    $this->handleChildFatal();
                }
            }
        }

        assert($this->currentRunningChildren > 0);
        $this->currentRunningChildren--;
        $this->chunksProcessed++;

        if($this->settings['useProgressBar']) {
            echo "\r" . $this->getRunStatistics();
        }

        if($this->gracefulShutdown && $this->currentRunningChildren === 0) {
            echo PHP_EOL . 'Gracefully shut down because of a child process fatal' . PHP_EOL;
        }
    }

    /**
     * A child crashed: mark the run as failed and stop the other children, depending on the settings.
     */
    private function handleChildFatal(): void {
        $this->childFailed = true;

        if($this->gracefulShutdown || !$this->settings['stopOnChildFatal']) {
            return;
        }

        if($this->settings['stopOnFatalMethod'] === StopOnFatalMethodEnum::GRACEFUL) {
            echo PHP_EOL . 'A child fataled, shutting down ' . count($this->childPIDs) . ' processes (currently running children will complete)' . PHP_EOL;

            $this->gracefulShutdown = true;
        }
        else {
            echo PHP_EOL . 'A child fataled, shutting down ' . count($this->childPIDs) . ' processes (currently running children will be terminated)' . PHP_EOL;

            $this->terminateChildren($this->settings['stopOnFatalMethod'] === StopOnFatalMethodEnum::NORMAL ? SIGTERM : SIGKILL);

            exit(1);
        }
    }

    /**
     * Signal all tracked children and reap them, so none is left running (or as a zombie) once the parent exits.
     * Children that survive a catchable signal for longer than the grace period (blocked in a system call,
     * ignoring the signal, ...) are killed with SIGKILL, so the parent never waits on them indefinitely.
     */
    private function terminateChildren(int $signal): void {
        $remaining = $this->childPIDs;
        foreach($remaining as $childPID) {
            posix_kill($childPID, $signal);
        }

        if($signal !== SIGKILL) {
            $remaining = $this->reapChildrenWithin($remaining, $this->settings['terminateGracePeriod']);
            foreach($remaining as $childPID) {
                posix_kill($childPID, SIGKILL);
            }
        }

        $remaining = $this->reapChildrenWithin($remaining, $this->killReapTimeout);
        if($remaining !== []) {
            echo PHP_EOL . 'Gave up waiting for killed child processes ' . implode(', ', $remaining)
                . ' (stuck in an uninterruptible system call?)' . PHP_EOL;
        }
    }

    /**
     * Reap the given children as they exit, until all are reaped or the time is up.
     *
     * @param int[] $childPIDs
     *
     * @return int[] The children that are still running
     */
    private function reapChildrenWithin(array $childPIDs, float $seconds): array {
        // hrtime() is monotonic: changes to the system clock don't shorten or lengthen the wait
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
        while($childPIDs !== []) {
            foreach($childPIDs as $key => $childPID) {
                $result = $this->reapChild($childPID, $status, WNOHANG);
                if($result === 0 || ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR)) {
                    // Still running, or interrupted: check again
                    continue;
                }
                // Reaped, or not ours to wait for anymore (reaped elsewhere): never signal it again
                $this->forgetChild($childPID);
                unset($childPIDs[$key]);
            }
            if($childPIDs === [] || hrtime(true) >= $deadline) {
                break;
            }
            usleep(10_000);
        }

        return $childPIDs;
    }

    /**
     * Stop tracking a reaped child, so it is never signalled after its PID may have been reused.
     */
    private function forgetChild(int $childPID): void {
        $key = array_search($childPID, $this->childPIDs, true);
        if($key !== false) {
            unset($this->childPIDs[$key]);
        }
    }

    /**
     * Close resources/connections so they will have to be re-initialized for all children.
     */
    private function closeAllResources(ResourceCloseCallbackTriggerEnum $trigger): void {
        if($trigger === ResourceCloseCallbackTriggerEnum::ONCE) {
            if(count($this->closeResourceOnceCallbacks) > 0) {
                foreach($this->closeResourceOnceCallbacks as $callback) {
                    $callback();
                }
            }
        }
        else if(count($this->closeResourceAlwaysCallbacks) > 0) {
            foreach($this->closeResourceAlwaysCallbacks as $callback) {
                $callback();
            }
        }
    }

    /**
     * Seed the random generator.
     * Without doing this some PHP methods that rely on the random generator might return the same value for every fork.
     *
     * @throws RandomException
     */
    private function seedRandomGenerator(): void {
        // random_int() gets a random seed via for example /dev/urandom, so this will work after forking too.
        srand(random_int(PHP_INT_MIN, PHP_INT_MAX));
    }
}
