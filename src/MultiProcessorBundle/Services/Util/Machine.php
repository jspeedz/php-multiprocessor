<?php
namespace MultiProcessorBundle\Services\Util;

use Symfony\Component\DependencyInjection\ContainerInterface;

class Machine {
    /**
     * Get the owner of the current script
     *
     * @param ContainerInterface $container
     *
     * @return null|string
     */
    public function getCurrentScriptOwner(ContainerInterface $container): ?string {
        $user = get_current_user();

        return $user;
    }

    /**
     * Return the number of usable cores on the current machine
     *
     * @return int|null Number of available cores on this machine, null if detection failed
     */
    public function getNumberOfCores(): ?int {
        $numberOfCores = null;
        if(is_file('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuInfo, $matches);
            $numberOfCores = count($matches[0]);
        }
        else if('win' === strtolower(substr(PHP_OS, 0, 3))) {
            $process = @popen('wmic cpu get NumberOfCores', 'rb');
            if(false !== $process) {
                fgets($process);
                $numberOfCores = intval(fgets($process));
                pclose($process);
            }
        }
        else {
            $process = @popen('sysctl -a', 'rb');
            if(false !== $process) {
                $output = stream_get_contents($process);
                preg_match('/hw.ncpu: (\d+)/', $output, $matches);
                if($matches) {
                    $numberOfCores = intval($matches[1][0]);
                }
                pclose($process);
            }
        }

        return $numberOfCores;
    }
}