<?php
namespace Jspeedz\MultiProcessor\Callback\Iterator;

use Closure;

class ParentIsAlive {
    /**
     * Get a callback to check if the parent process is still alive.
     *
     * @param int $parentPID
     *
     * @return Closure
     */
    public function getCallback(int $parentPID): Closure {
        $executions = 0;

        return function() use ($parentPID, &$executions): bool {
            if($executions % 10 === 0 && posix_getpgid($parentPID) === false) {
                // Only check every 10 items, otherwise it's a bit much, even though it only takes 0.01-0.02ms per call
                // The parent process died, I should stop myself as well..
                exit(0);
            }

            $executions++;

            return true;
        };
    }
}
