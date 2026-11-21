<?php
namespace Jspeedz\MultiProcessor\Tests\Processor;

use Jspeedz\MultiProcessor\Iterator\ArrayIterator;
use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Granularity;

#[CoversClass(ProcessorAbstract::class)]
final class ProcessorAbstractTest extends TestCase {
    private function processor(): ProcessorAbstract {
        return new class extends ProcessorAbstract {
            public function process(): void {}
        };
    }

    public function testDataIsInitiallyNull(): void {
        self::assertNull($this->processor()->getData());
    }

    public function testDefaultLifecycleHooksPreserveData(): void {
        $processor = $this->processor();
        $processor->initialize();
        $processor->finish();
        self::assertNull($processor->getData());

        $data = new ArrayIterator([1, 2]);
        $processor->setData($data);
        $processor->initialize();
        self::assertSame($data, $processor->getData());
        $processor->finish();
        self::assertSame($data, $processor->getData());
        self::assertSame([1, 2], iterator_to_array($data));
    }

    public function testExitTerminatesTheProcessSuccessfully(): void {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';';
        $coverage = CodeCoverage::instance();
        $coverageFile = null;
        $granularity = Granularity::Line;
        if($coverage->isActive()) {
            $coverageFile = tempnam(sys_get_temp_dir(), 'processor-exit-coverage-');
            self::assertNotFalse($coverageFile);
            if($coverage->collectsPathCoverage()) {
                $granularity = Granularity::LineBranchAndPath;
            }
            else if($coverage->collectsBranchCoverage()) {
                $granularity = Granularity::LineAndBranch;
            }
            $code .= 'require ' . var_export(dirname(__DIR__) . '/fixtures/subprocess-coverage.php', true) . ';';
        }
        $code .= <<<'PHP'
            $processor = new class extends \Jspeedz\MultiProcessor\Processor\ProcessorAbstract {
                public function process(): void {}
            };
            echo 'before';
            $processor->exit();
            echo 'after';
            exit(1);
            PHP;

        try {
            $process = proc_open(
                [PHP_BINARY, '-r', $code, $coverageFile ?? '', $granularity->name,
                    $root . '/src/Processor/ProcessorAbstract.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            self::assertSame(0, $status, (string) $output);
            self::assertSame('before', $output, 'exit() must not return to the caller.');

            if($coverageFile !== null) {
                $serialized = file_get_contents($coverageFile);
                self::assertNotFalse($serialized);
                self::assertNotSame('', $serialized);
                $data = unserialize($serialized, ['allowed_classes' => [RawCodeCoverageData::class]]);
                self::assertInstanceOf(RawCodeCoverageData::class, $data);
                $coverage->codeCoverage()->append($data);
            }
        }
        finally {
            if($coverageFile !== null) {
                unlink($coverageFile);
            }
        }
    }

    public function testArraysAreWrappedAndReindexed(): void {
        $processor = $this->processor();
        $processor->setData([4 => 'a', 9 => 'b']);

        self::assertInstanceOf(ArrayIterator::class, $processor->getData());
        self::assertSame(['a', 'b'], iterator_to_array($processor->getData()));
    }

    public function testExistingIterableIsPreserved(): void {
        $processor = $this->processor();
        $data = new \ArrayIterator(['a' => 1]);
        $processor->setData($data);

        self::assertSame($data, $processor->getData());
        self::assertSame(['a' => 1], iterator_to_array($processor->getData()));
    }

    public function testParentCheckWithForeignTraversableDoesNotFail(): void {
        $processor = $this->processor();
        $processor->setParentAliveCheckCallback(function(): bool { return true; });
        $data = new \ArrayIterator(['a' => 1]);
        $processor->setData($data);

        self::assertSame($data, $processor->getData());
        self::assertSame(['a' => 1], iterator_to_array($processor->getData()));
    }

    public static function callbackOrders(): iterable {
        yield 'callback before data' => [true];
        yield 'callback after data' => [false];
    }

    #[DataProvider('callbackOrders')]
    public function testParentCheckIsInstalledRegardlessOfSetterOrder(bool $callbackFirst): void {
        $processor = $this->processor();
        $calls = 0;
        $callback = function() use (&$calls): bool { $calls++; return true; };
        if($callbackFirst) {
            $processor->setParentAliveCheckCallback($callback);
        }
        $processor->setData([1, 2, 3]);
        if(!$callbackFirst) {
            $processor->setParentAliveCheckCallback($callback);
        }

        self::assertSame([1, 2, 3], iterator_to_array($processor->getData()));
        self::assertSame(3, $calls);

        $processor->setData([4]);
        self::assertSame([4], iterator_to_array($processor->getData()));
        self::assertSame(4, $calls, 'Replacing data must preserve the parent check.');
    }

    public function testReplacingParentCheckUpdatesTheExistingIterator(): void {
        $processor = $this->processor();
        $oldCalls = $newCalls = 0;
        $processor->setParentAliveCheckCallback(function() use (&$oldCalls): void { $oldCalls++; });
        $data = new ArrayIterator([1, 2]);
        $processor->setData($data);
        $processor->setParentAliveCheckCallback(function() use (&$newCalls): void { $newCalls++; });

        self::assertSame($data, $processor->getData());
        self::assertSame([1, 2], iterator_to_array($data));
        self::assertSame(0, $oldCalls);
        self::assertSame(2, $newCalls);
    }
}
