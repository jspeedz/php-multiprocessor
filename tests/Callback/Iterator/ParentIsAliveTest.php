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
        self::assertInstanceOf(Closure::class, (new ParentIsAlive())->getCallback(posix_getpid()));
    }

    public function testCallbackReturnsTrueWhileTheParentIsAlive(): void {
        $callback = (new ParentIsAlive())->getCallback(posix_getpid());

        for($invocation = 1; $invocation <= 25; $invocation++) {
            self::assertTrue($callback());
        }
    }

    public function testCallbackExitsSuccessfullyOnItsFirstInvocationWhenTheParentIsGone(): void {
        self::assertSame([0, 'before'], $this->runIsolated(<<<'PHP'
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
            exit(1);
            PHP));
    }

    public function testCallbackChecksOnTheFirstInvocationAndEveryTenInvocationsAfterwards(): void {
        self::assertSame([0, 'check:1;check:11;check:21;'], $this->runIsolated(<<<'PHP'
            function posix_getpgid(int $pid): int|false {
                echo 'check:' . $GLOBALS['invocation'] . ';';
                return $GLOBALS['invocation'] < 21 ? 123 : false;
            }

            $callback = (new ParentIsAlive())->getCallback(456);
            for($invocation = 1; $invocation <= 21; $invocation++) {
                if($callback() !== true) {
                    throw new \RuntimeException('Expected the callback to return true.');
                }
            }
            echo 'after';
            exit(1);
            PHP));
    }

    public function testCallbacksKeepIndependentCountersAndParentPids(): void {
        self::assertSame([0, '123;456;456;123;'], $this->runIsolated(<<<'PHP'
            function posix_getpgid(int $pid): int|false {
                echo $pid . ';';
                return 789;
            }

            $factory = new ParentIsAlive();
            $first = $factory->getCallback(123);
            $second = $factory->getCallback(456);
            $first();
            for($invocation = 1; $invocation <= 11; $invocation++) {
                $second();
            }
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
