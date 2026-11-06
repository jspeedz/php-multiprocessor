<?php
namespace Jspeedz\MultiProcessor\Tests\Utility;

use Jspeedz\MultiProcessor\Utility\ProcessorCores;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessorCores::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProcessorCoresTest extends TestCase {
    protected function setUp(): void {
        require dirname(__DIR__) . '/fixtures/processor-cores-platform.php';
        ProcessorCores::$numberOfCores = 0;
    }

    public function testExplicitCoreCountSkipsPlatformDetection(): void {
        ProcessorCores::$numberOfCores = 16;

        self::assertSame(16, ProcessorCores::getNumberOfCores());
        self::assertSame([], ProcessorCoresPlatform::$calls);
    }

    public static function cpuInfo(): iterable {
        yield 'one processor' => ["processor\t: 0\nmodel name\t: Test CPU\n", 1];
        yield 'multiple processors' => ["processor\t: 0\n\nprocessor\t: 1\n\nprocessor\t: 2\n", 3];
        yield 'only line starts count' => ["model name: processor\n processor: 0\nprocessor: 1\n", 1];
        yield 'empty file falls back to one' => ['', 1];
        yield 'no processor entries falls back to one' => ["model name: Test CPU\n", 1];
        yield 'unreadable file' => [false, 1];
    }

    #[DataProvider('cpuInfo')]
    public function testLinuxCountsProcessorEntries(string|false $cpuInfo, int $expected): void {
        // /proc/cpuinfo takes precedence even if the OS name is Windows.
        define('Jspeedz\\MultiProcessor\\Utility\\PHP_OS', 'WINNT');
        ProcessorCoresPlatform::$cpuInfo = $cpuInfo;

        self::assertSame($expected, ProcessorCores::getNumberOfCores());
        self::assertSame($expected, ProcessorCores::$numberOfCores);
        self::assertSame([
            ['is_file', '/proc/cpuinfo'],
            ['file_get_contents', '/proc/cpuinfo'],
        ], ProcessorCoresPlatform::$calls);
    }

    public function testDetectedCoreCountIsCachedUntilReset(): void {
        ProcessorCoresPlatform::$cpuInfo = "processor: 0\nprocessor: 1\n";
        self::assertSame(2, ProcessorCores::getNumberOfCores());
        $calls = ProcessorCoresPlatform::$calls;

        ProcessorCoresPlatform::$cpuInfo = "processor: 0\n";
        self::assertSame(2, ProcessorCores::getNumberOfCores());
        self::assertSame($calls, ProcessorCoresPlatform::$calls);

        ProcessorCores::$numberOfCores = 0;
        self::assertSame(1, ProcessorCores::getNumberOfCores());
        self::assertCount(4, ProcessorCoresPlatform::$calls);
    }

    public function testUndetectableCoreCountFallsBackToOneAndIsCached(): void {
        // Empty /proc/cpuinfo: nothing detected
        self::assertSame(1, ProcessorCores::getNumberOfCores());
        self::assertSame(1, ProcessorCores::$numberOfCores);

        ProcessorCoresPlatform::$cpuInfo = "processor: 0\nprocessor: 1\n";
        self::assertSame(1, ProcessorCores::getNumberOfCores());
        self::assertCount(2, ProcessorCoresPlatform::$calls);
    }

    public static function windowsOutput(): iterable {
        yield 'one core' => ["NumberOfCores\r\n1\r\n", 1];
        yield 'multiple digits and whitespace' => ["NumberOfCores\r\n  32  \r\n", 32];
        yield 'zero cores falls back to one' => ["NumberOfCores\r\n0\r\n", 1];
        yield 'missing value falls back to one' => ["NumberOfCores\r\n", 1];
    }

    #[DataProvider('windowsOutput')]
    public function testWindowsSkipsHeaderAndReadsCoreCount(string $output, int $expected): void {
        $this->configurePlatform('WINNT', $output);

        self::assertSame($expected, ProcessorCores::getNumberOfCores());
        self::assertSame($expected, ProcessorCores::$numberOfCores);
        $this->assertCommandWasClosed('wmic cpu get NumberOfCores');
    }

    public static function sysctlOutput(): iterable {
        yield 'one core' => ["1\n", 1];
        yield 'multiple digits' => ["16\n", 16];
        yield 'zero cores falls back to one' => ["0\n", 1];
        yield 'unknown key falls back to one' => ["sysctl: unknown oid 'hw.ncpu'\n", 1];
        yield 'empty output' => ['', 1];
    }

    #[DataProvider('sysctlOutput')]
    public function testOtherPlatformsReadSysctlCoreCount(string $output, int $expected): void {
        $this->configurePlatform('Darwin', $output);

        self::assertSame($expected, ProcessorCores::getNumberOfCores());
        self::assertSame($expected, ProcessorCores::$numberOfCores);
        $this->assertCommandWasClosed('sysctl -n hw.ncpu');
    }

    public static function failedCommands(): iterable {
        yield 'Windows' => ['WINNT', 'wmic cpu get NumberOfCores'];
        yield 'other platforms' => ['FreeBSD', 'sysctl -n hw.ncpu'];
    }

    #[DataProvider('failedCommands')]
    public function testFailedCommandFallsBackToOneCoreAndCachesIt(string $os, string $command): void {
        $this->configurePlatform($os, false);

        self::assertSame(1, ProcessorCores::getNumberOfCores());
        self::assertSame(1, ProcessorCores::$numberOfCores);
        self::assertSame(1, ProcessorCores::getNumberOfCores());
        self::assertSame([
            ['is_file', '/proc/cpuinfo'],
            ['popen', $command, 'rb'],
        ], ProcessorCoresPlatform::$calls);
    }

    private function configurePlatform(string $os, string|false $output): void {
        define('Jspeedz\\MultiProcessor\\Utility\\PHP_OS', $os);
        ProcessorCoresPlatform::$cpuInfoExists = false;
        ProcessorCoresPlatform::$processOutput = $output;
    }

    private function assertCommandWasClosed(string $command): void {
        self::assertSame([
            ['is_file', '/proc/cpuinfo'],
            ['popen', $command, 'rb'],
            ['pclose'],
        ], ProcessorCoresPlatform::$calls);
    }
}
