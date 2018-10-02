<?php
namespace MultiProcessor\Utility;

class ProcessorCores {
	public static $numberOfCores;

	public static function getNumberOfCores() {
		if(!empty(self::$numberOfCores)) {
			return self::$numberOfCores;
		}
		self::$numberOfCores = 1;
		if(is_file('/proc/cpuinfo')) {
			$cpuInfo = file_get_contents('/proc/cpuinfo');
			preg_match_all('/^processor/m', $cpuInfo, $matches);
			self::$numberOfCores = count($matches[0]);
		}
		else if('win' == strtolower(substr(PHP_OS, 0, 3))) {
			$process = @popen('wmic cpu get NumberOfCores', 'rb');
			if(false !== $process) {
				fgets($process);
				self::$numberOfCores = intval(fgets($process));
				pclose($process);
			}
		}
		else {
			$process = @popen('sysctl -a', 'rb');
			if(false !== $process) {
				$output = stream_get_contents($process);
				preg_match('/hw.ncpu: (\d+)/', $output, $matches);
				if($matches) {
					self::$numberOfCores = intval($matches[1][0]);
				}
				pclose($process);
			}
		}

		return self::$numberOfCores;
	}
}
