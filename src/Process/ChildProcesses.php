<?php
// Signals must be delivered while forking and waiting for children, also without asynchronous signal handling.
declare(ticks = 1);

namespace Jspeedz\MultiProcessor\Process;

use Closure;
use Jspeedz\MultiProcessor\Exception\ProcessException;

/**
 * Forks, tracks, reaps and terminates the child processes of the parent.
 *
 * Only our own children are reaped, by PID: reaping any child would also reap processes the caller
 * started itself (like popen()), stealing their exit status from pclose()/proc_close().
 *
 * @internal
 */
final class ChildProcesses {
    /**
     * @var array<int, int> PIDs of the children that are not reaped yet
     */
    private array $pids = [];

    /**
     * Number of SIGCHLD signals received, to tell whether a child exited since the children were last checked.
     *
     * @var non-negative-int
     */
    private int $exitSignals = 0;

    /**
     * Seconds to wait for SIGKILLed children to be reaped. A child stuck in an uninterruptible system call
     * (like I/O on a dead NFS mount) only dies once that call returns, so the parent gives up waiting on it.
     *
     * @var non-negative-float
     */
    private float $killReapTimeout = 5.0;

    /**
     * Longest sleep between checks on the children while a process we didn't fork stays waitable.
     * Any SIGCHLD cuts the sleep short, so this only bounds the delay when a child exit slips in just before it.
     */
    private int $foreignExitPollMicroseconds = 1_000_000;

    /**
     * Fork the current process. In the parent, the child gets the priority and is tracked until it is reaped.
     *
     * @param int<-20, 19> $priority
     *
     * @return int The child PID in the parent, 0 in the child
     *
     * @throws ProcessException
     */
    public function fork(int $priority): int {
        $pid = pcntl_fork();
        if($pid === -1) {
            throw new ProcessException('Could not fork');
        }
        if($pid > 0) {
            pcntl_setpriority($priority, $pid);
            $this->pids[] = $pid;
        }

        return $pid;
    }

    /**
     * @return non-negative-int
     */
    public function count(): int {
        return count($this->pids);
    }

    /**
     * @return array<int, int>
     */
    public function pids(): array {
        return $this->pids;
    }

    /**
     * Record that a child exited (SIGCHLD): it is reaped by wait(), which only needs to know it happened.
     */
    public function notifyExit(): void {
        $this->exitSignals++;
    }

    /**
     * Stop tracking a reaped child, so it is never signaled after its PID may have been reused.
     */
    public function forget(int $pid): void {
        $key = array_search($pid, $this->pids, true);
        if($key !== false) {
            unset($this->pids[$key]);
        }
    }

    /**
     * Reap children as they exit.
     *
     * @param bool $all Wait until all children exited, or only until at least one did (freeing up a slot)
     * @param Closure(int, int): void $onExit Called with the PID and wait status of every reaped child
     * @param-immediately-invoked-callable $onExit
     *
     * @throws ProcessException When tracked children can no longer be waited for (reaped elsewhere)
     */
    public function wait(bool $all, Closure $onExit): void {
        while($this->pids !== []) {
            $exitSignals = $this->exitSignals;
            $reaped = false;
            foreach($this->pids as $pid) {
                $result = $this->reap($pid, $status, WNOHANG);
                if($result === 0) {
                    // Still running
                    continue;
                }
                if($result === -1) {
                    if(pcntl_get_last_error() === PCNTL_EINTR) {
                        continue;
                    }
                    // Without this, the concurrency limit would be exceeded and a failed child would go unnoticed.
                    throw new ProcessException('Lost track of child process ' . $pid . ': it was reaped elsewhere');
                }

                $onExit($result, $status);
                $reaped = true;
            }

            if($reaped) {
                if(!$all) {
                    return;
                }
                continue;
            }

            $this->sleepUntilAChildExits($exitSignals);
        }
    }

    /**
     * Signal all tracked children and reap them, so none is left running (or as a zombie) once the parent exits.
     * Children that survive a catchable signal for longer than the grace period (blocked in a system call,
     * ignoring the signal, ...) are killed with SIGKILL, so the parent never waits on them indefinitely.
     *
     * @param non-negative-float $gracePeriod Seconds children get to exit on a catchable signal
     */
    public function terminate(int $signal, float $gracePeriod = 0.0): void {
        $remaining = $this->pids;
        foreach($remaining as $pid) {
            posix_kill($pid, $signal);
        }

        if($signal !== SIGKILL) {
            $remaining = $this->reapWithin($remaining, $gracePeriod);
            foreach($remaining as $pid) {
                posix_kill($pid, SIGKILL);
            }
        }

        $remaining = $this->reapWithin($remaining, $this->killReapTimeout);
        if($remaining !== []) {
            echo PHP_EOL . 'Gave up waiting for killed child processes ' . implode(', ', $remaining)
                . ' (stuck in an uninterruptible system call?)' . PHP_EOL;
        }
    }

    /**
     * Block until any child process has exited, without reaping it.
     *
     * @param non-negative-int $exitSignals The SIGCHLD count from before the children were last checked
     */
    private function sleepUntilAChildExits(int $exitSignals): void {
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
        if(in_array($pid, $this->pids, true) || $this->exitSignals !== $exitSignals) {
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
    private function reap(int $pid, ?int &$status, int $flags = 0): int {
        $previousMask = [];
        pcntl_sigprocmask(SIG_BLOCK, Signals::TERMINATION, $previousMask);
        try {
            $waitStatus = 0;
            $result = pcntl_waitpid($pid, $waitStatus, $flags);
            $status = is_int($waitStatus) ? $waitStatus : 0;
            if($result > 0 && (pcntl_wifexited($status) || pcntl_wifsignaled($status))) {
                $this->forget($result);
            }
        }
        finally {
            // A termination signal that arrived meanwhile is handled right here
            if(is_array($previousMask)) {
                pcntl_sigprocmask(SIG_SETMASK, $previousMask);
            }
            else {
                // The previous mask is unknown: only undo our own blocking
                pcntl_sigprocmask(SIG_UNBLOCK, Signals::TERMINATION);
            }
        }

        return $result;
    }

    /**
     * Reap the given children as they exit, until all are reaped or the time is up.
     *
     * @param array<int, int> $pids
     * @param non-negative-float $seconds
     *
     * @return array<int, int> The children that are still running
     */
    private function reapWithin(array $pids, float $seconds): array {
        // hrtime() is monotonic: changes to the system clock don't shorten or lengthen the wait
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
        while($pids !== []) {
            foreach($pids as $key => $pid) {
                $result = $this->reap($pid, $status, WNOHANG);
                if($result === 0 || ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR)) {
                    // Still running, or interrupted: check again
                    continue;
                }
                // Reaped, or not ours to wait for anymore (reaped elsewhere): never signal it again
                $this->forget($pid);
                unset($pids[$key]);
            }
            if($pids === [] || hrtime(true) >= $deadline) {
                break;
            }
            usleep(10_000);
        }

        return $pids;
    }
}
