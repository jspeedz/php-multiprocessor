<?php
namespace Jspeedz\MultiProcessor\Tests\Callback\Close;

use Closure;
use Jspeedz\MultiProcessor\Callback\Close\StreamResources;
use Jspeedz\MultiProcessor\Exception\UnsupportedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Granularity;

#[CoversClass(StreamResources::class)]
final class StreamResourcesTest extends TestCase {
    public function testGetCallbackReturnsAClosure(): void {
        self::assertInstanceOf(Closure::class, (new StreamResources())->getCallback());
    }

    public function testCallbackClosesFiles(): void {
        self::assertFalse($this->runIsolated(<<<'PHP'
            $file = tmpfile();
            (new StreamResources())->getCallback()();
            return is_resource($file);
            PHP));
    }

    public function testCallbackClosesDirectories(): void {
        self::assertFalse($this->runIsolated(<<<'PHP'
            $directory = opendir(sys_get_temp_dir());
            (new StreamResources())->getCallback()();
            return is_resource($directory);
            PHP));
    }

    public function testCallbackClosesStreamsOfEveryWrapper(): void {
        self::assertSame([
            'php://fd' => false,
            'compress.zlib' => false,
            'php://filter' => false,
            'socket' => false,
            'php://temp with options' => true,
        ], $this->runIsolated(<<<'PHP'
            $file = tempnam(sys_get_temp_dir(), 'close-streams-wrapper-');
            file_put_contents($file, 'data');
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $streams = [
                'php://fd' => fopen('php://fd/1', 'w'),
                'compress.zlib' => fopen('compress.zlib://' . $file, 'r'),
                'php://filter' => fopen('php://filter/read=string.toupper/resource=' . $file, 'r'),
                'socket' => $sockets[0],
                'php://temp with options' => fopen('php://temp/maxmemory:1024', 'r+'),
            ];
            (new StreamResources())->getCallback()();
            unlink($file);
            return array_map(is_resource(...), $streams);
            PHP));
    }

    public function testCallbackKeepsResourcesThatMustStayOpenSoLocksAreNotReleased(): void {
        self::assertSame([true, false, false], $this->runIsolated(<<<'PHP'
            $lockFile = tempnam(sys_get_temp_dir(), 'close-streams-lock-');
            $lock = fopen($lockFile, 'c');
            flock($lock, LOCK_EX);
            $other = tmpfile();
            (new StreamResources([$lock]))->getCallback()();

            // A second, independent handle can only lock the file when the first lock was released
            $probe = fopen($lockFile, 'c');
            $lockWasReleased = flock($probe, LOCK_EX | LOCK_NB);
            $result = [is_resource($lock), is_resource($other), $lockWasReleased];
            fclose($probe);
            fclose($lock);
            unlink($lockFile);
            return $result;
            PHP));
    }

    public function testCallbackClosesProcessPipes(): void {
        self::assertFalse($this->runIsolated(<<<'PHP'
            $pipe = popen(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('exit(0);'), 'r');
            (new StreamResources())->getCallback()();
            return is_resource($pipe);
            PHP));
    }

    public function testCallbackIgnoresStreamContextsAndCanRunRepeatedly(): void {
        self::assertTrue($this->runIsolated(<<<'PHP'
            $context = stream_context_create();
            $callback = (new StreamResources())->getCallback();
            $callback();
            $callback();
            return is_resource($context);
            PHP));
    }

    public static function streamMetadata(): iterable {
        yield 'directory with a URI' => ['dir', 'plainfile', false, null];
        yield 'HTTP stream' => ['STDIO', 'http', false, null];
        yield 'HTTPS stream' => ['tcp_socket/ssl', 'http', false, null];
        yield 'other wrapper' => ['STDIO', 'custom', false, null];
        yield 'other stream type' => ['custom', 'plainfile', false, null];
    }

    #[DataProvider('streamMetadata')]
    public function testCallbackHandlesStreamMetadata(string $type, string $wrapper, bool $remainsOpen, ?string $error): void {
        $metadata = ['uri' => 'test://resource', 'stream_type' => $type, 'wrapper_type' => $wrapper];
        $body = '$GLOBALS["metadata"] = ' . var_export($metadata, true) . ';'
            . '$resource = ' . ($type === 'dir' ? 'opendir(sys_get_temp_dir())' : 'tmpfile()') . ';'
            . <<<'PHP'
            if($resource === false) {
                throw new \RuntimeException('Could not open the test resource.');
            }
            try {
                (new StreamResources())->getCallback()();
                $error = null;
            }
            catch (\Jspeedz\MultiProcessor\Exception\UnsupportedException $exception) {
                $error = $exception->getMessage();
            }
            $remainsOpen = is_resource($resource);
            if($remainsOpen) {
                if($GLOBALS['metadata']['stream_type'] === 'dir') {
                    closedir($resource);
                }
                else {
                    fclose($resource);
                }
            }
            return [$remainsOpen, $error];
            PHP;

        self::assertSame([$remainsOpen, $error], $this->runIsolated($body, <<<'PHP'
            function stream_get_meta_data($resource): array {
                if(in_array($resource, [STDIN, STDOUT, STDERR], true)) {
                    return \stream_get_meta_data($resource);
                }
                return $GLOBALS['metadata'];
            }
            PHP));
    }

    public function testCallbackIgnoresAlreadyClosedResources(): void {
        self::assertTrue($this->runIsolated(<<<'PHP'
            $GLOBALS['closedResource'] = tmpfile();
            fclose($GLOBALS['closedResource']);
            (new StreamResources())->getCallback()();
            return true;
            PHP, <<<'PHP'
            function get_resources(): array {
                return [$GLOBALS['closedResource']];
            }
            PHP));
    }

    public function testCallbackRejectsUnsupportedResourceTypes(): void {
        self::assertSame('Unknown resource type (process)', $this->runIsolated(<<<'PHP'
            $process = proc_open(
                [PHP_BINARY, '-r', 'exit(0);'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
            if(!is_resource($process)) {
                throw new \RuntimeException('Could not open the test process.');
            }
            try {
                (new StreamResources())->getCallback()();
                return null;
            }
            catch (\Jspeedz\MultiProcessor\Exception\UnsupportedException $exception) {
                return $exception->getMessage();
            }
            finally {
                proc_close($process);
            }
            PHP));
    }

    public static function excludedStreams(): iterable {
        yield 'standard input' => ['php://stdin', 'r'];
        yield 'standard output' => ['php://stdout', 'w'];
        yield 'standard error' => ['php://stderr', 'w'];
        yield 'input' => ['php://input', 'r'];
        yield 'output' => ['php://output', 'w'];
        yield 'memory' => ['php://memory', 'r+'];
        yield 'temporary' => ['php://temp', 'r+'];
    }

    #[DataProvider('excludedStreams')]
    public function testCallbackLeavesExcludedStreamUrisOpen(string $uri, string $mode): void {
        self::assertTrue($this->runIsolated('$stream = fopen(' . var_export($uri, true) . ', ' . var_export($mode, true) . ');' . <<<'PHP'
            $callback = (new StreamResources())->getCallback();
            $callback();
            $callback();
            return is_resource($stream) && is_resource(STDIN) && is_resource(STDOUT) && is_resource(STDERR);
            PHP));
    }

    /**
     * Keep resource discovery and closing away from PHPUnit's own open streams.
     */
    private function runIsolated(string $body, string $functions = ''): mixed {
        $resultFile = tempnam(sys_get_temp_dir(), 'close-streams-');
        self::assertNotFalse($resultFile);
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = 'namespace { require ' . var_export($autoload, true) . '; }'
            . 'namespace Jspeedz\\MultiProcessor\\Callback\\Close {' . $functions . '}'
            . 'namespace {'
            . 'use Jspeedz\\MultiProcessor\\Callback\\Close\\StreamResources;';
        $coverage = CodeCoverage::instance();
        $coverageFile = null;
        $granularity = Granularity::Line;
        if($coverage->isActive()) {
            $coverageFile = tempnam(sys_get_temp_dir(), 'close-streams-coverage-');
            self::assertNotFalse($coverageFile);
            if($coverage->collectsPathCoverage()) {
                $granularity = Granularity::LineBranchAndPath;
            }
            else if($coverage->collectsBranchCoverage()) {
                $granularity = Granularity::LineAndBranch;
            }
            $code .= 'require ' . var_export(dirname(__DIR__, 2) . '/fixtures/subprocess-coverage.php', true) . ';';
        }
        $code .= 'try { $result = (function() {' . $body . '})();'
            . 'file_put_contents(' . var_export($resultFile, true) . ', json_encode(["result" => $result], JSON_THROW_ON_ERROR));'
            . '} catch (Throwable $exception) {'
            . 'file_put_contents(' . var_export($resultFile, true) . ', json_encode(["error" => (string) $exception], JSON_THROW_ON_ERROR)); exit(1); }}';

        try {
            $process = proc_open(
                [PHP_BINARY, '-r', $code, $coverageFile ?? '', $granularity->name,
                    dirname(__DIR__, 3) . '/src/Callback/Close/StreamResources.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            $json = file_get_contents($resultFile);
            self::assertNotFalse($json);
            self::assertNotSame('', $json, $output);
            $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $status, $report['error'] ?? $output);
            if($coverageFile !== null) {
                $serialized = file_get_contents($coverageFile);
                self::assertNotFalse($serialized);
                self::assertNotSame('', $serialized, $output);
                $data = unserialize($serialized, ['allowed_classes' => [RawCodeCoverageData::class]]);
                self::assertInstanceOf(RawCodeCoverageData::class, $data);
                $coverage->codeCoverage()->append($data);
            }
            return $report['result'];
        }
        finally {
            unlink($resultFile);
            if($coverageFile !== null) {
                unlink($coverageFile);
            }
        }
    }
}
