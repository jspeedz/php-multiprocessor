<?php
namespace Jspeedz\MultiProcessor\Callback\Iterator;

use Closure;

class ParentIsAlive {
    /**
     * Get a callback to check if the parent process is still alive.
     *
     * @param int $parentPID The PID of the process that forked the current one
     *
     * @return Closure
     */
    public function getCallback(int $parentPID): Closure {
        $executions = 0;

        return function() use ($parentPID, &$executions): bool {
            // Only check every 10 items, otherwise it's a bit much, even though it only takes 0.01-0.02ms per call.
            // Compare our own parent instead of checking whether $parentPID exists: once the parent died, its PID may be
            // reused by an unrelated process, while an orphan is re-parented (to init or a subreaper) and never gets it back.
            if($executions % 10 === 0 && posix_getppid() !== $parentPID) {
                // The parent process died, I should stop myself as well..
                // Non-zero: the chunk was not fully processed, which whoever reaps this process should be able to tell.
                exit(1);
            }

            $executions++;

            return true;
        };
    }
}
