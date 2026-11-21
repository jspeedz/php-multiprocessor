<?php
// Signals must be delivered while running, also without asynchronous signal handling, see Signals::register().
declare(ticks = 1);

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
use Jspeedz\MultiProcessor\Process\ChildExitHandler;
use Jspeedz\MultiProcessor\Process\ChildProcesses;
use Jspeedz\MultiProcessor\Process\ProcessTitle;
use Jspeedz\MultiProcessor\Process\Signals;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
use Jspeedz\MultiProcessor\Progress\ProgressBar;
use Jspeedz\MultiProcessor\Resource\ResourceCloser;
use Random\RandomException;

/**
 * Forks a child process per chunk of the data, and runs the processor on that chunk in the child.
 *
 * @todo Logging via monolog
 * @todo Progress bar, inject. Use package?
 */
class MultiProcessor {
    protected int $parentPID;

    private readonly Settings $settings;

    private readonly ResourceCloser $resourceCloser;

    private readonly ChildProcesses $children;

    private readonly ChildExitHandler $exitHandler;

    private ?ProgressBar $progressBar = null;

    /**
     * Process title from before run() changed it, the base of the titles of the parent and its children.
     */
    private string $baseProcessTitle = '';

    /**
     * @var non-negative-int
     */
    private int $chunksProcessed = 0;

    /**
     * @var bool $stopProcessing If true, will kill the chunk loop
     */
    private bool $stopProcessing = false;

    public function __construct(
        private IteratorAbstract $data,
        private ProcessorAbstract $processor,
    ) {
        $this->settings = new Settings();
        $this->resourceCloser = new ResourceCloser();
        $this->children = new ChildProcesses();
        $this->exitHandler = new ChildExitHandler($this->settings, $this->children);

        // Signal handlers are only registered by run(), which sets this again. This keeps a direct signalHandler() call
        // before run() from reading an uninitialized property.
        $this->parentPID = posix_getpid();
    }

    /**
     * The number of processes to fork at any given moment.
     *
     * @param positive-int $children
     */
    public function setMaximumConcurrentChildren(int $children): void {
        $this->settings->concurrentChildren = $children;
    }

    /**
     * Items to handle per chunk/child.
     *
     * @param positive-int $chunkSize
     */
    public function setChunkSize(int $chunkSize): void {
        $this->settings->chunkSize = $chunkSize;
    }

    /**
     * Set the priority for the child processes. Defaults to 19 if not set.
     *
     * @param int<-20, 19> $priority −20 is the highest priority and 19 the lowest.
     *
     * @throws InvalidPriorityException
     */
    public function setPriority(int $priority): void {
        $this->settings->priority = $priority;
    }

    /**
     * Enables stopping all processes when the parent/management process fatals.
     *
     * @param bool $stopOnParentFatal Default: true
     */
    public function stopOnParentFatal(bool $stopOnParentFatal = true): void {
        $this->settings->stopOnParentFatal = $stopOnParentFatal;
    }

    /**
     * Enables stopping all processes when a child process fatals.
     * Disabling this still makes the parent exit non-zero after all children finish if any child failed.
     *
     * @param bool $stopOnChildFatal Default: true
     */
    public function stopOnChildFatal(bool $stopOnChildFatal = true): void {
        $this->settings->stopOnChildFatal = $stopOnChildFatal;
    }

    /**
     * Enables stopping all processes when a child process fatals.
     *
     * @param StopOnFatalMethodEnum $stopOnFatalMethod StopOnFatalMethodEnum::GRACEFUL will let running children complete, StopOnFatalMethodEnum::NORMAL will kill all children with SIGTERM (and SIGKILL after the grace period, see setTerminateGracePeriod()), StopOnFatalMethodEnum::IMMEDIATE will kill all children instantly SIGKILL. Default: StopOnFatalMethodEnum::GRACEFUL
     */
    public function stopOnFatalMethod(
        StopOnFatalMethodEnum $stopOnFatalMethod = StopOnFatalMethodEnum::GRACEFUL,
    ): void {
        $this->settings->stopOnFatalMethod = $stopOnFatalMethod;
    }

    /**
     * How long children get to shut down after SIGTERM, when the `normal` stop on fatal method stops them.
     * Children still running after this period (blocked in a system call, ignoring SIGTERM, ...) are killed with SIGKILL.
     * Defaults to 10 seconds. Has no effect on the `graceful` and `immediate` methods.
     *
     * @param non-negative-float $seconds 0 or more, 0 sends SIGKILL to children that didn't exit on SIGTERM right away.
     *
     * @throws InvalidGracePeriodException
     */
    public function setTerminateGracePeriod(float $seconds): void {
        $this->settings->terminateGracePeriod = $seconds;
    }

    /**
     * Show a progress bar? Note that the time estimate is very rough.
     * It'll only be accurate when every handled item takes exactly the same time to process.
     *
     * @todo Refactor this, inject a progress bar if you need one (same goes for logger)
     */
    public function useProgressBar(bool $useProgressBar = true): void {
        $this->settings->useProgressBar = $useProgressBar;
    }

    /**
     * Register closures to close resource handles before forking.
     *
     * Makes sure all forks create their own resource handles by closing the current ones.
     * Re-using resources like file handles, MySQL or network connections will cause trouble when forks try to use them.
     * They will try to use the same handles/connections and collide.
     *
     * @param Closure $callback
     * @param ResourceCloseCallbackTriggerEnum $trigger ResourceCloseCallbackTriggerEnum::ALWAYS runs the callback before forking echt process, or ResourceCloseCallbackTriggerEnum::ONCE before the first fork only. Default: ResourceCloseCallbackTriggerEnum::ALWAYS
     */
    public function addCloseResourceCallback(
        Closure $callback,
        ResourceCloseCallbackTriggerEnum $trigger = ResourceCloseCallbackTriggerEnum::ALWAYS,
    ): void {
        $this->resourceCloser->add($callback, $trigger);
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
            if(Signals::isTermination($signo)) {
                $this->stopProcessing = true;
                $this->children->terminate(SIGKILL);

                exit(1);
            }
            if($signo === SIGCHLD) {
                $this->children->notifyExit();
                return;
            }
        }
        else {
            // Child
            if($signo === SIGCHLD) {
                // Terminated myself (should be a signal to the parent, but somehow sent to child as well)..
                // Nothing to do here
                return;
            }
            if(Signals::isTermination($signo)) {
                // Interrupted mid-chunk: exit like an uncaught signal would (128 + signal), so the parent sees the chunk failed.
                // Not 1 or 255: those are child fatals to the parent, which would stop the remaining children.
                exit(128 + $signo);
            }
        }

        throw new ProcessException(sprintf(
            'Caught undefined signal (%s)',
            Signals::name($signo),
        ));
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
        Signals::register([$this, 'signalHandler']);
        $this->data->generateChunks($this->settings->chunkSize);

        $chunkCount = count($this->data);
        if($this->settings->useProgressBar) {
            $this->progressBar = new ProgressBar($chunkCount);
            $this->progressBar->start();
        }

        $this->resourceCloser->close(ResourceCloseCallbackTriggerEnum::ONCE);

        // Show the role of every process in its title, as listed by `ps`. Only works under the CLI SAPI.
        $this->baseProcessTitle = ProcessTitle::get();
        ProcessTitle::set(sprintf('%s (%s-main)', $this->baseProcessTitle, $this->parentPID));

        $chunkNumber = 0;
        foreach($this->data as $chunk) {
            if($this->stopProcessing) {
                // We should not continue!
                break;
            }

            if(!is_iterable($chunk)) {
                throw new UnsupportedException('Each data chunk must be iterable');
            }

            $chunkNumber++;
            $processor = clone $this->processor;

            $this->resourceCloser->close(ResourceCloseCallbackTriggerEnum::ALWAYS);

            $pid = $this->children->fork($this->settings->priority);
            if($pid === 0) {
                $this->runChild($processor, $chunk, $chunkNumber, $chunkCount);
                continue;
            }

            // This is the parent
            if($this->children->count() >= $this->settings->concurrentChildren || ($this->children->count() > 0 && $this->exitHandler->gracefulShutdown)) {
                $this->waitForChildren(false);
            }

            if($this->exitHandler->gracefulShutdown) {
                // Should gracefully shut down, don't execute any more chunks.
                if($this->children->count() > 0) {
                    $this->waitForChildren(true);
                }
                exit(2);
            }
        }

        if(isset($pid) && $pid > 0) {
            // This is the parent, children will have exited before here.
            $this->waitForChildren(true); // Wait for the remaining children.
            $this->progressBar?->finish();

            if($this->exitHandler->childFailed) {
                // There was at least one child that crashed.
                // Make sure the parent returns a non-zero exit code, even with graceful shutdown enabled.
                exit(2);
            }
        }

        if($this->parentPID === posix_getpid()) {
            // The script may carry on after the run, restore the title it had before.
            ProcessTitle::set($this->baseProcessTitle);
        }
    }

    /**
     * Process a chunk in the forked child. The processor normally exits the child once it is done.
     *
     * @param iterable<mixed> $chunk
     *
     * @throws RandomException
     */
    private function runChild(ProcessorAbstract $processor, iterable $chunk, int $chunkNumber, int $chunkCount): void {
        ProcessTitle::set(sprintf(
            '%s (%s-fork %s/%s)',
            $this->baseProcessTitle,
            $this->parentPID,
            $chunkNumber,
            $chunkCount,
        ));

        // Without seeding, some PHP methods that rely on the random generator might return the same value for every fork.
        // random_int() gets a random seed via for example /dev/urandom, so this will work after forking too.
        srand(random_int(PHP_INT_MIN, PHP_INT_MAX));

        $processor->setData($chunk);
        if($this->settings->stopOnParentFatal) {
            $processor->setParentAliveCheckCallback(
                (new ParentIsAlive)->getCallback($this->parentPID)
            );
        }
        $processor->initialize();
        $processor->process();
        $processor->finish();
        $processor->exit();
    }

    /**
     * @param bool $finalChildren If this current wait is on the final children, or in between chunks
     *
     * @throws NonCleanExitException
     * @throws ProcessException When tracked children can no longer be waited for (reaped elsewhere)
     */
    private function waitForChildren(bool $finalChildren): void {
        $this->children->wait($finalChildren, $this->onChildExit(...));
    }

    /**
     * Book a reaped child: handle its exit status, and report the progress.
     *
     * @throws NonCleanExitException
     */
    private function onChildExit(int $pid, int $status): void {
        $this->exitHandler->handle($pid, $status);

        $this->chunksProcessed++;
        $this->progressBar?->update($this->chunksProcessed);

        if($this->exitHandler->gracefulShutdown && $this->children->count() === 0) {
            echo PHP_EOL . 'Gracefully shut down because of a child process fatal' . PHP_EOL;
        }
    }
}
