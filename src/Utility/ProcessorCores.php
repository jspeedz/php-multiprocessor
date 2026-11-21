<?php
namespace Jspeedz\MultiProcessor\Utility;

class ProcessorCores {
    /**
     * Cached core count. 0 means "not detected yet"; set it to a positive value to skip detection.
     *
     * @var non-negative-int
     */
    public static int $numberOfCores = 0;

    /**
     * Returns the number of processor cores, falling back to 1 when it cannot be detected.
     *
     * @return positive-int
     */
    public static function getNumberOfCores(): int {
        if(self::$numberOfCores > 0) {
            return self::$numberOfCores;
        }

        $cores = 0;
        if(is_file('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            if($cpuInfo !== false) {
                $cores = preg_match_all('/^processor/m', $cpuInfo);
            }
        }
        else if('win' == strtolower(substr(PHP_OS, 0, 3))) {
            $process = @popen('wmic cpu get NumberOfCores', 'rb');
            if(false !== $process) {
                fgets($process);
                $cores = intval(fgets($process));
                pclose($process);
            }
        }
        else {
            $process = @popen('sysctl -n hw.ncpu 2>/dev/null', 'rb');
            if(false !== $process) {
                $output = stream_get_contents($process);
                if($output !== false) {
                    $cores = intval(trim($output));
                }
                pclose($process);
            }
        }

        // Could not be detected (or nonsensical result): default to a single core.
        self::$numberOfCores = max(1, (int) $cores);

        return self::$numberOfCores;
    }
}
