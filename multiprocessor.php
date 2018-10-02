<?php
namespace MultiProcessor;

require_once __DIR__ . '/iterator/iteratorinterface.php';
require_once __DIR__ . '/processor/processor.php';

/**
 * @todo logging
 * @todo clean shutdown
 * @todo Stop on child fatal
 *
 * Class MultiProcessor
 * @package MultiProcessor
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
		'stopOnChildFatal' => true
	];

	/**
	 * @var Iterator\Iterator
	 */
	private $data;

	/**
	 * @var Processor\Processor
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
	private $gracefullShutdown = false;

	public function __construct(Iterator\Iterator $data, Processor\Processor $processor) {
//		$data = $data->getIterator();
		$this->data = $data;
		$this->processor = $processor;

		declare(ticks = 1);

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
	public function setMaximumConcurrentChildren(int $children) {
		$this->settings['concurrentChildren'] = $children;
	}

	/**
	 * Items to handle per chunk/child.
	 *
	 * @param integer $chunkSize
	 */
	public function setChunkSize(int $chunkSize) {
		$this->settings['chunkSize'] = $chunkSize;
	}

	/**
	 * Set the priority for the child processes. Defaults to 19 if not set.
	 *
	 * @param integer $priority −20 is the highest priority and 19 the lowest.
	 *
	 * @throws \Exception
	 */
	public function setPriority(int $priority) {
		if($priority < -20 || $priority > 19) {
			throw new \Exception('Invalid process priority (' . $priority . ')');
		}
		$this->settings['priority'] = $priority;
	}

	/**
	 * @param integer $signal
	 *
	 * @throws \Exception
	 */
	private function signalHandler($signal) {
		switch($signal) {
			case SIGTERM:
				// Default kill command
				echo 'Caught SIGTERM' . PHP_EOL;
				$this->gracefullShutdown = true;
				exit;
			break;
			case SIGQUIT:
				// interrupt signal (CTRL+\)
				echo 'Caught SIGQUIT' . PHP_EOL;
				$this->gracefullShutdown = true;
				exit;
			break;
			case SIGINT:
				// interrupt signal (CTRL+C)
				echo 'Caught SIGINT' . PHP_EOL;
				$this->gracefullShutdown = true;
				exit;
			break;
			case SIGCHLD:
				// Child termination
//				while(pcntl_waitpid(0, $status) != -1) {
//					$status = pcntl_wexitstatus($status);
//					$this->currentRunningChildren--;
//					echo 'Child Exit: '.$this->currentRunningChildren . '---' . $status;
//					exit;
//				}
			break;
			default:
				throw new \Exception('Caught undefined signal (' . $signal . ')');
			break;
		}
	}

	/**
	 * Run the data through the processor.
	 *
	 * @throws \Exception
	 */
	public function run() {
		$this->data->generateChunks($this->settings['chunkSize']);
		$this->timeStarted = time();
		echo 'Chunks                    Progress                           ' . str_repeat(' ', strlen($this->data->count()) * 2 + 1) . 'Elapsed  / Remaining';
		echo "\r" . $this->getRunStatistics();
		foreach($this->data as $chunk) {
			$processor = clone $this->processor;
			$this->currentRunningChildren++;
			$pid = pcntl_fork();
			if($pid === -1) {
				throw new \Exception('Could not fork');
			}
			else if($pid === 0) {
				// This is a child.
				$processor->setData($chunk);
				$processor->process();
				$processor->exit();
			}
			else if($pid > 0) {
				// This is the parent, $pid contains the child PID.
				pcntl_setpriority($this->settings['priority'], $pid);

				$this->childPIDs[] = $pid;

				if($this->currentRunningChildren >= $this->settings['concurrentChildren'] || ($this->currentRunningChildren > 0 && $this->gracefullShutdown)) {
					$this->waitForChildren();
				}
				if($this->gracefullShutdown) {
					// Should gracefully shut down, don't execute any more chunks.
					break;
				}
			}
		}
		if(isset($pid) && $pid > 0) {
			// This is the parent,children will be exited before here.
			$this->waitForChildren(); // Wait for the remaining children.
		}
	}

	/**
	 * @return string
	 */
	private function getRunStatistics() {
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

	private function waitForChildren() {
		while($childPID = pcntl_waitpid(0, $status) !== -1) {
			// @todo return value, do logging with it?

			if(pcntl_wifsignaled($status)) {
				// Child terminated by signal, which wasn't handled.
				$signal = pcntl_wtermsig($status);
				trigger_error('Child terminated by unhandled signal (' . $signal . ')', E_USER_WARNING);
			}
			else if(pcntl_wifexited($status)) {
				// Clean exit, exit code returned by processor in exit() statement
				$exitCode = pcntl_wexitstatus($status);
				if($exitCode !== 0) {
					// Child might have fataled.
					if($exitCode === 255) {
						// PHP exit code of 255
						if(!$this->gracefullShutdown && $this->settings['stopOnChildFatal']) {
							echo PHP_EOL . 'A child fataled, gracefully shutting down (currently running children will complete)!' . PHP_EOL;
							$this->gracefullShutdown = true;
//							foreach($this->childPIDs as $childPID) {
//								posix_kill($childPID, SIGTERM);
//							}
//							posix_kill($this->parentPID, SIGTERM);
						}
					}
				}
				else {
					unset($this->childPIDs[array_search($childPID, $this->childPIDs)]);
				}
			}
			else {
				// Non clean exit

			}
			$this->currentRunningChildren--;
			$this->chunksProcessed++;
			echo "\r" . $this->getRunStatistics();
			if($this->gracefullShutdown && $this->currentRunningChildren === 0) {
				echo PHP_EOL . 'Gracefully shut down' . PHP_EOL;
			}
		}
	}
}
