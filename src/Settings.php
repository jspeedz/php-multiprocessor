<?php
namespace Jspeedz\MultiProcessor;

use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Exception\InvalidGracePeriodException;
use Jspeedz\MultiProcessor\Exception\InvalidPriorityException;

/**
 * Configuration of a MultiProcessor run, see the setters on MultiProcessor for what each setting does.
 *
 * @internal
 */
final class Settings {
    /**
     * @var int<-20, 19>
     */
    public int $priority = 19 {
        /**
         * @throws InvalidPriorityException
         */
        set {
            if($value < -20 || $value > 19) {
                throw new InvalidPriorityException('Invalid process priority (' . $value . ')');
            }
            $this->priority = $value;
        }
    }

    /**
     * @var positive-int
     */
    public int $concurrentChildren = 1;

    /**
     * @var positive-int
     */
    public int $chunkSize = 10;

    public bool $stopOnParentFatal = true;

    public bool $stopOnChildFatal = true;

    public StopOnFatalMethodEnum $stopOnFatalMethod = StopOnFatalMethodEnum::GRACEFUL;

    /**
     * Seconds children get to exit after SIGTERM on a `normal` fatal shutdown, before they are killed with SIGKILL.
     *
     * @var non-negative-float
     */
    public float $terminateGracePeriod = 10.0 {
        /**
         * @throws InvalidGracePeriodException
         */
        set {
            if(!is_finite($value) || $value < 0) {
                throw new InvalidGracePeriodException(sprintf(
                    'Invalid terminate grace period (%s), must be 0 seconds or more',
                    var_export($value, true),
                ));
            }
            $this->terminateGracePeriod = $value;
        }
    }

    public bool $useProgressBar = true;
}
