<?php
namespace Jspeedz\MultiProcessor\Utility;

class ProcessorCores {
    /**
     * @var non-negative-int
     */
    public static int $numberOfCores = 0;

    /**
     * @return non-negative-int
     */
    public static function getNumberOfCores(): int {
        if(!empty(self::$numberOfCores)) {
            return self::$numberOfCores;
        }

        self::$numberOfCores = 1;
        if(is_file('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            if($cpuInfo !== false) {
                preg_match_all('/^processor/m', $cpuInfo, $matches);
                self::$numberOfCores = count($matches[0]);
            }
        }
        else if('win' == strtolower(substr(PHP_OS, 0, 3))) {
            $process = @popen('wmic cpu get NumberOfCores', 'rb');
            if(false !== $process) {
                fgets($process);
                $cores = intval(fgets($process));
                assert($cores >= 0);
                self::$numberOfCores = $cores;
                pclose($process);
            }
        }
        else {
            $process = @popen('sysctl -a', 'rb');
            if(false !== $process) {
                $output = stream_get_contents($process);
                preg_match('/hw.ncpu: (\d+)/', $output, $matches);
                if($matches) {
                    $cores = intval($matches[1]);
                    assert($cores >= 0);
                    self::$numberOfCores = $cores;
                }
                pclose($process);
            }
        }

        return self::$numberOfCores;
    }
}
