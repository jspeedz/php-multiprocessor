<?php
namespace Jspeedz\MultiProcessor\Tests\Callback\Iterator;

use Closure;
use Jspeedz\MultiProcessor\Callback\Iterator\ParentIsAlive;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Granularity;

#[CoversClass(ParentIsAlive::class)]
final class ParentIsAliveTest extends TestCase {
    public function testGetCallbackReturnsAClosure(): void {
        self::assertInstanceOf(Closure::class, (new ParentIsAlive())->getCallback(posix_getppid()));
    }

    public function testCallbackReturnsTrueWhileTheParentIsAlive(): void {
        $callback = (new ParentIsAlive())->getCallback(posix_getppid());

        for($invocation = 1; $invocation <= 25; $invocation++) {
            self::assertTrue($callback());
        }
    }

    public function testCallbackExitsNonZeroOnItsFirstInvocationWhenTheParentIsGone(): void {
        self::assertSame([1, 'before'], $this->runIsolated(<<<'PHP'
            $pid = pcntl_fork();
            if($pid === -1) {
                throw new \RuntimeException('Could not fork a process.');
            }
            if($pid === 0) {
                exit(0);
            }
            if(pcntl_waitpid($pid, $status) !== $pid) {
                throw new \RuntimeException('Could not reap the process.');
            }
            $callback = (new ParentIsAlive())->getCallback($pid);
            echo 'before';
            $callback();
            echo 'after';
            exit(0);
            PHP));
    }

    public function testCallbackExitsOnceTheRealParentDiedAndTheChildWasReparented(): void {
        // A real orphan: the child checks while its parent lives, then the parent exits and the child is re-parented.
        // The child's output reaches us because it keeps the stdout pipe open until it exits itself.
        self::assertSame([0, 'alive;before;'], $this->runIsolated(<<<'PHP'
            $parent = posix_getpid();
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if($sockets === false) {
                throw new \RuntimeException('Could not create a socket pair.');
            }
            $pid = pcntl_fork();
            if($pid === -1) {
                throw new \RuntimeException('Could not fork a process.');
            }
            if($pid > 0) {
                // Parent: stay alive until the child checked once, then die
                fread($sockets[0], 1);
                exit(0);
            }

            $callback = (new ParentIsAlive())->getCallback($parent);
            if($callback()) {
                // First invocation checks, while the parent is blocked on the socket
                echo 'alive;';
            }
            fwrite($sockets[1], '.');
            for($tries = 0; posix_getppid() === $parent && $tries < 500; $tries++) {
                usleep(10_000);
            }
            if(posix_getppid() === $parent) {
                echo 'parent did not exit;';
                exit(0);
            }
            for($invocation = 2; $invocation <= 10; $invocation++) {
                // Invocations in between don't check
                $callback();
            }
            echo 'before;';
            $callback();
            echo 'after';
            exit(0);
            PHP));
    }

    public function testCallbackExitsWhenTheParentPidWasReusedByAnUnrelatedProcess(): void {
        // The parent died and its PID now belongs to a live process that isn't our parent
        self::assertSame([1, 'before'], $this->runIsolated(<<<'PHP'
            $unrelated = proc_open(['sleep', '5'], [], $pipes);
            $pid = proc_get_status($unrelated)['pid'];
            $callback = (new ParentIsAlive())->getCallback($pid);
            register_shutdown_function(static function() use ($unrelated): void {
                proc_terminate($unrelated);
                proc_close($unrelated);
            });
            echo 'before';
            $callback();
            echo 'after';
            exit(0);
            PHP));
    }

    public function testCallbackChecksOnTheFirstInvocationAndEveryTenInvocationsAfterwards(): void {
        self::assertSame([1, 'check:1;check:11;check:21;'], $this->runIsolated(<<<'PHP'
            function posix_getppid(): int {
                echo 'check:' . $GLOBALS['invocation'] . ';';
                return $GLOBALS['invocation'] < 21 ? 456 : 1;
            }

            $callback = (new ParentIsAlive())->getCallback(456);
            for($invocation = 1; $invocation <= 21; $invocation++) {
                if($callback() !== true) {
                    throw new \RuntimeException('Expected the callback to return true.');
                }
            }
            echo 'after';
            exit(0);
            PHP));
    }

    public function testCallbacksKeepIndependentCountersAndParentPids(): void {
        self::assertSame([0, 'first;second;second;first;'], $this->runIsolated(<<<'PHP'
            $GLOBALS['parent'] = 123;
            function posix_getppid(): int {
                echo $GLOBALS['parent'] === 123 ? 'first;' : 'second;';
                return $GLOBALS['parent'];
            }

            $factory = new ParentIsAlive();
            $first = $factory->getCallback(123);
            $second = $factory->getCallback(456);
            $first();
            $GLOBALS['parent'] = 456;
            for($invocation = 1; $invocation <= 11; $invocation++) {
                $second();
            }
            $GLOBALS['parent'] = 123;
            for($invocation = 2; $invocation <= 11; $invocation++) {
                $first();
            }
            PHP));
    }

    /**
     * Isolate exit() and namespaced POSIX substitutes from the PHPUnit process.
     *
     * @return array{int, string}
     */
    private function runIsolated(string $body): array {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = 'namespace Jspeedz\\MultiProcessor\\Callback\\Iterator;'
            . 'require ' . var_export($autoload, true) . ';';
        $coverage = CodeCoverage::instance();
        $coverageFile = null;
        $granularity = Granularity::Line;
        if($coverage->isActive()) {
            $coverageFile = tempnam(sys_get_temp_dir(), 'parent-is-alive-coverage-');
            self::assertNotFalse($coverageFile);
            if($coverage->collectsPathCoverage()) {
                $granularity = Granularity::LineBranchAndPath;
            }
            else if($coverage->collectsBranchCoverage()) {
                $granularity = Granularity::LineAndBranch;
            }
            $code .= 'require ' . var_export(dirname(__DIR__, 2) . '/fixtures/subprocess-coverage.php', true) . ';';
        }

        try {
            $process = proc_open(
                [PHP_BINARY, '-r', $code . $body, $coverageFile ?? '', $granularity->name,
                    dirname(__DIR__, 3) . '/src/Callback/Iterator/ParentIsAlive.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            self::assertNotFalse($output);

            if($coverageFile !== null) {
                $serialized = file_get_contents($coverageFile);
                self::assertNotFalse($serialized);
                self::assertNotSame('', $serialized, $output);
                $data = unserialize($serialized, ['allowed_classes' => [RawCodeCoverageData::class]]);
                self::assertInstanceOf(RawCodeCoverageData::class, $data);
                $coverage->codeCoverage()->append($data);
            }

            return [$status, $output];
        }
        finally {
            if($coverageFile !== null) {
                unlink($coverageFile);
            }
        }
    }
}
