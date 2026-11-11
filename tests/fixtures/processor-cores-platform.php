<?php
namespace Jspeedz\MultiProcessor\Tests\Utility {
    /** Platform substitutes loaded only inside isolated ProcessorCores tests. */
    final class ProcessorCoresPlatform {
        public static bool $cpuInfoExists = true;
        public static string|false $cpuInfo = '';
        public static string|false $processOutput = false;
        public static array $calls = [];
    }
}

namespace Jspeedz\MultiProcessor\Utility {
    use Jspeedz\MultiProcessor\Tests\Utility\ProcessorCoresPlatform;

    function is_file(string $filename): bool {
        ProcessorCoresPlatform::$calls[] = ['is_file', $filename];
        return ProcessorCoresPlatform::$cpuInfoExists;
    }

    function file_get_contents(string $filename): string|false {
        ProcessorCoresPlatform::$calls[] = ['file_get_contents', $filename];
        return ProcessorCoresPlatform::$cpuInfo;
    }

    function popen(string $command, string $mode) {
        ProcessorCoresPlatform::$calls[] = ['popen', $command, $mode];
        if(ProcessorCoresPlatform::$processOutput === false) {
            return false;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, ProcessorCoresPlatform::$processOutput);
        rewind($stream);
        return $stream;
    }

    function pclose($process): int {
        ProcessorCoresPlatform::$calls[] = ['pclose'];
        fclose($process);
        return 0;
    }
}
