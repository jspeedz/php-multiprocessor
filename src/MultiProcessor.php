<?php
namespace Jspeedz\MultiProcessor;

use Closure;
use Exception;
use Jspeedz\MultiProcessor\Callback\Iterator\ParentIsAlive;
use Jspeedz\MultiProcessor\Exception\{
    NonCleanExitException,
    StopOnFatalMethodException
};

/**
 * @todo Logging via monolog
 * @todo Progress bar, inject. Use package?
 * @todo Tests (how do I unit test code that forks? :/)
 */
class MultiProcessor {
	/**
	 * @var integer
	 */
	protected $parentPID;

	/**
	 * @var array
	 */
	private $settings = [
		'priority' => 19,
		'concurrentChildren' => 1,
		'chunkSize' => 10,
        'stopOnParentFatal' => true,
		'stopOnChildFatal' => true,
        'stopOnFatalMethod' => 'graceful',
        'useProgressBar' => true,
	];

    /**
     * @var Closure[]
     */
    private $closeResourceOnceCallbacks = [];

    /**
     * @var Closure[]
     */
    private $closeResourceAlwaysCallbacks = [];

	/**
	 * @var Iterator\IteratorAbstract
	 */
	private $data;

	/**
	 * @var Processor\ProcessorAbstract
	 */
	private $processor;

	/**
	 * @var integer
	 */
	private $currentRunningChildren = 0;

	/**
	 * Signals to bind to the signal handler.
	 * Note: can't bind to SIGKILL as this event can't be overridden, it's just killed.
	 *
	 * @var integer[]
	 */
	private $signalHandlerEvent = [
		SIGTERM,
		SIGINT,
		SIGCHLD
	];

	/**
	 * @var integer[]
	 */
	private $childPIDs = [];

	/**
	 * @var integer
	 */
	private $chunksProcessed = 0;

	/**
	 * @var integer
	 */
	private $timeStarted;

	/**
	 * @var boolean
	 */
	private $gracefulShutdown = false;

    /**
     * @var boolean $stopProcessing If true, will kill the chunk loop
     */
    private $stopProcessing = false;

    /**
     * @param Iterator\IteratorAbstract $data
     * @param Processor\ProcessorAbstract $processor
     */
    public function __construct(Iterator\IteratorAbstract $data, Processor\ProcessorAbstract $processor) {
		$this->data = $data;
		$this->processor = $processor;

        $useTicks = !function_exists('pcntl_async_signals');
        if(!$useTicks) {
            pcntl_async_signals(true);
            $useTicks = !pcntl_async_signals();
        }
        if($useTicks) {
            // Asynchronous signal handling is not supported, so usage of ticks is required, resulting in a bit more overhead.
            declare(ticks = 1);
        }

		$signalHandler = [$this, 'signalHandler'];
		foreach($this->signalHandlerEvent as $signal) {
			pcntl_signal($signal, $signalHandler);
		}

		$this->parentPID = posix_getpid();
	}

	/**
	 * The number of processes to fork at any given moment.
	 *
	 * @param integer $children
	 */
	public function setMaximumConcurrentChildren(int $children): void {
		$this->settings['concurrentChildren'] = $children;
	}

	/**
	 * Items to handle per chunk/child.
	 *
	 * @param integer $chunkSize
	 */
	public function setChunkSize(int $chunkSize): void {
		$this->settings['chunkSize'] = $chunkSize;
	}

	/**
	 * Set the priority for the child processes. Defaults to 19 if not set.
	 *
	 * @param integer $priority −20 is the highest priority and 19 the lowest.
	 *
	 * @throws Exception
	 */
	public function setPriority(int $priority): void {
		if($priority < -20 || $priority > 19) {
			throw new Exception('Invalid process priority (' . $priority . ')');
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
     *
     * @param bool $stopOnChildFatal = true
     */
    public function stopOnChildFatal(bool $stopOnChildFatal = true): void {
        $this->settings['stopOnChildFatal'] = $stopOnChildFatal;
    }

    /**
     * Enables stopping all processes when a child process fatals.
     *
     * @param string $stopOnFatalMethod = graceful|normal|immediate `graceful` will let running children complete, `normal` will kill all children with SIGTERM, `immediate` will kill all children instantly SIGKILL
     * @throws StopOnFatalMethodException
     */
    public function stopOnFatalMethod(string $stopOnFatalMethod = 'graceful'): void {
        if(!in_array($stopOnFatalMethod, [
            'graceful',
            'normal',
            'immediate',
        ])) {
            throw new StopOnFatalMethodException('Unknown stop on fatal method (' . $stopOnFatalMethod . ')');
        }
        $this->settings['stopOnFatalMethod'] = $stopOnFatalMethod;
    }

    /**
	 * Show a progress bar? Note that the time estimate is very rough.
	 * It'll only be accurate when every handled item takes exactly the same time to process.
     *
	 * @todo Refactor this, inject a progress bar if you need one (same goes for logger)
     *
     * @param bool $useProgressBar
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
     * @param string $trigger always|once (always run callbacks before forking, or once before the first fork)
     *
     * @throws Exception
     */
    public function addCloseResourceCallback(Closure $callback, string $trigger = 'always'): void {
        if(!in_array($trigger, [
            'once',
            'always'
        ])) {
            throw new Exception('Unknown trigger (' . $trigger . ')');
        }

        if($trigger === 'once') {
            $this->closeResourceOnceCallbacks[] = $callback;
        }
        else {
            $this->closeResourceAlwaysCallbacks[] = $callback;
        }
	}

    /**
     * @param int $signal
     *
     * @return string|null
     * @throws Exception
     */
    private function getStringFromSignal(int $signal): ?string {
        switch($signal) {
            case SIGCHLD: return 'SIGCHLD'; // Child termination
            case SIGINT: return 'SIGINT'; // interrupt signal (CTRL+C)
            case SIGQUIT: return 'SIGQUIT'; // interrupt signal (CTRL+\)
            case SIGTERM: return 'SIGTERM'; // Default kill command
            default:
                throw new Exception('Unknown signal string, add to method (' . $signal . ')');
        }

        return null;
    }

    /**
     * @param integer $signal
     * @param array $signalInfo
     *
     * @throws Exception
     */
    public function signalHandler(int $signal, array $signalInfo): void {
        if($this->parentPID === posix_getpid()) {
            // Parent
            if(in_array($signal, [
                SIGTERM, // Default kill command
                SIGQUIT, // interrupt signal (CTRL+\)
                SIGINT, // interrupt signal (CTRL+C)
            ])) {
                $this->stopProcessing = true;
                foreach($this->childPIDs as $childPID) {
                    echo posix_kill($childPID, SIGKILL);
                }

                exit(1);
            }
            if($signal === SIGCHLD) {
                // Child has terminated, nothing to do here
                return;
            }

            throw new Exception('Caught undefined signal (' . $this->getStringFromSignal($signal) . ')');
        }
        else {
            // Child
            if(in_array($signal, [
                SIGCHLD, // Terminated myself (should be a signal to the parent, but somehow sent to child as well)..
            ])) {
                // Nothing to do here
                return;
            }
            else if(in_array($signal, [
                SIGTERM, // Default kill command
                SIGQUIT, // interrupt signal (CTRL+\)
                SIGINT, // interrupt signal (CTRL+C)
            ])) {
                // Let's kill myself
                exit;
            }

            throw new Exception('Caught undefined signal (' . $this->getStringFromSignal($signal) . ')');
        }
    }

	/**
	 * Run the data through the processor.
	 *
	 * @throws Exception
	 */
	public function run(): void {
		$this->data->generateChunks($this->settings['chunkSize']);
		$this->timeStarted = time();

        if($this->settings['useProgressBar']) {
            echo 'Chunks                    Progress                           ' . str_repeat(' ', strlen($this->data->count()) * 2 + 1) . 'Elapsed  / Remaining';
            echo "\r" . $this->getRunStatistics();
        }

        $this->closeAllResources('once');

		foreach($this->data as $chunk) {
            if($this->stopProcessing) {
                // We should not continue!
                break;
            }

			$processor = clone $this->processor;
			$this->currentRunningChildren++;

            $this->closeAllResources('always');

			$pid = pcntl_fork();
			if($pid === -1) {
				throw new Exception('Could not fork');
			}
			else if($pid === 0) {
				// This is a child.
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
                    exit(2);
				}
			}
		}

		if(isset($pid) && $pid > 0) {
			// This is the parent,children will be exited before here.
			$this->waitForChildren(true); // Wait for the remaining children.
			if($this->settings['useProgressBar']) {
				echo PHP_EOL;
			}
		}
	}

	/**
	 * @return string
	 */
	private function getRunStatistics(): string {
		$elapsedTime = time() - $this->timeStarted;
		if(empty($this->chunksProcessed)) {
			return  PHP_EOL . sprintf('%1$\' ' . (string) (strlen($this->data->count()) * 2 + 1) . 's', '0/' . $this->data->count()) . '    ' .
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
		$timeToGo = ($h < 10 ? '0' : '') . (string) $h . ':'  . ($m < 10 ? '0' : '') . (string) $m . ':' . ($s < 10 ? '0' : '') . (string) $s;

		$percentage = $this->chunksProcessed * 100 / $this->data->count();

//		return "\r" . $this->chunksProcessed . '/' . $this->data->count() . '    ' .
		return "\r" . sprintf('%1$\' ' . (string) (strlen($this->data->count()) * 2 + 1) . 's', $this->chunksProcessed . '/' . $this->data->count()) . '    ' .
		' [' . str_repeat('=', round($percentage / 2.5)) . '>' . str_repeat(' ', 40 - round($percentage / 2.5)) . '] ' . sprintf('%1$\' 6.2f%%', $percentage) .
		'     ' . $elapsedTime . ' / ' . $timeToGo;
	}

    /**
     * @param bool $finalChildren If this current wait is on the final children, or in between chunks
     *
     * @throws NonCleanExitException
     */
    private function waitForChildren(bool $finalChildren): void {
        if($finalChildren) {
            // Wait until the final forks are done processing
            while($childPID = pcntl_waitpid(0, $status) !== -1) {
                $this->executeWaitForChildren($childPID, $status);
            }
        }
        else {
            // Wait until we can start the next fork
            if($childPID = pcntl_waitpid(0, $status) !== -1) {
                $this->executeWaitForChildren($childPID, $status);
            }
        }
	}

    /**
     * @param int $childPID
     * @param int $status
     *
     * @throws NonCleanExitException
     */
    private function executeWaitForChildren(int $childPID, int $status) {
        // @todo return value, do logging with it?

        if(pcntl_wifsignaled($status)) {
            // Child terminated by signal, which wasn't handled
            $signal = pcntl_wtermsig($status);
            trigger_error('Child terminated by unhandled signal (' . $signal . ', see PCNTL_* PHP contstants and linux fork() documentation)', E_USER_ERROR);
        }
        else if(pcntl_wifexited($status)) {
            // Clean exit, exit code returned by processor in exit() statement
            $exitCode = pcntl_wexitstatus($status);
            if($exitCode !== 0) {
                // Child might have fataled.
                if(in_array($exitCode, [
                    1,
                    255,
                ])) {
                    // PHP exit code of 1 or 255
                    if(!$this->gracefulShutdown && $this->settings['stopOnChildFatal']) {
                        if($this->settings['stopOnFatalMethod'] === 'graceful') {
                            echo PHP_EOL . 'A child fataled, shutting down ' . count($this->childPIDs) . ' processes (currently running children will complete)' . PHP_EOL;

                            $this->gracefulShutdown = true;
                        }
                        else {
                            echo PHP_EOL . 'A child fataled, shutting down ' . count($this->childPIDs) . ' processes (currently running children will be terminated)' . PHP_EOL;

                            $signal = $this->settings['stopOnFatalMethod'] === 'normal' ? SIGTERM : SIGKILL;
                            foreach($this->childPIDs as $childPID) {
                                posix_kill($childPID, $signal);
                            }

                            exit(1);
                        }
                    }
                }
            }
            else {
                unset($this->childPIDs[array_search($childPID, $this->childPIDs)]);
            }
        }
        else {
            // Non clean exit
            throw new NonCleanExitException('Non clean exit?');
        }

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
     * Close resources/connections so they will have to be re-initialized for all children.
     *
     * @param string $trigger once|always
     *
     * @throws Exception
     */
    private function closeAllResources(string $trigger): void {
        if(!in_array($trigger, [
            'once',
            'always'
        ])) {
            throw new Exception('Unknown trigger (' . $trigger . ')');
        }

        if($trigger === 'once') {
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
}
