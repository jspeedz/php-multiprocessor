<?php
namespace Jspeedz\MultiProcessor\Tests\Logging;

use Jspeedz\MultiProcessor\Logging\LogOutputLock;
use Jspeedz\MultiProcessor\Logging\Monolog\CrossProcessLockHandler;
use Jspeedz\MultiProcessor\Tests\Support\ProcessScenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Runs a real MultiProcessor whose children log records larger than PIPE_BUF to one shared stdout pipe.
 */
#[CoversClass(LogOutputLock::class)]
#[CoversClass(CrossProcessLockHandler::class)]
final class LogOutputLockForkTest extends TestCase {
    private const int CHILDREN = 4;
    private const int RECORDS = 10;

    private string $lockDirectory;

    protected function setUp(): void {
        $this->lockDirectory = sys_get_temp_dir() . '/' . uniqid('multiprocessor-fork-lock-', true);
        self::assertTrue(mkdir($this->lockDirectory));
    }

    protected function tearDown(): void {
        foreach(glob($this->lockDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->lockDirectory);
    }

    /**
     * @return array{records: list<array{message: string, context: array<string, mixed>}>, broken: int}
     */
    private function runScenario(bool $lock): array {
        [$status, , $output] = ProcessScenario::run('log-output-lock.php', [
            'lock' => $lock,
            'lockDirectory' => $this->lockDirectory,
            'children' => self::CHILDREN,
            'records' => self::RECORDS,
            // Larger than the 64 KiB Linux pipe capacity, so unlocked writes are always split
            'size' => 100_000,
        ]);
        self::assertSame(0, $status, substr($output, 0, 2000));

        // Only message and context without the padding are kept, the output holds megabytes of it
        $records = [];
        $broken = 0;
        for($line = strtok($output, "\n"); $line !== false; $line = strtok("\n")) {
            $record = json_decode($line, true);
            if(is_array($record) && is_string($record['message'] ?? null) && is_array($record['context'] ?? null)) {
                unset($record['context']['blob']);
                $records[] = ['message' => $record['message'], 'context' => $record['context']];
            }
            else {
                $broken++;
            }
        }
        unset($output);

        return ['records' => $records, 'broken' => $broken];
    }

    public function testEveryRecordStaysIntactWhenTheLockIsEnabled(): void {
        ['records' => $records, 'broken' => $broken] = $this->runScenario(true);

        self::assertSame(0, $broken);
        $messages = array_column($records, 'message');
        self::assertCount(self::CHILDREN * self::RECORDS + 2, $messages);
        self::assertSame('parent before fork', $messages[0]);
        self::assertSame('parent after run', $messages[array_key_last($messages)]);
        for($child = 0; $child < self::CHILDREN; $child++) {
            self::assertContains("child $child record " . (self::RECORDS - 1), $messages);
        }
    }

    public function testChildrenShareTheLockFileOfTheParentWhichRemovesItWhenDone(): void {
        ['records' => $records] = $this->runScenario(true);

        $parent = $records[0]['context'];
        self::assertSame(
            sprintf('%s/multiprocessor-log-output-%d.lock', $this->lockDirectory, $parent['pid']),
            $parent['lockFile'],
        );
        foreach(array_slice($records, 1, -1) as $record) {
            self::assertSame($parent['lockFile'], $record['context']['lockFile'], $record['message']);
        }
        // Children exiting must not remove it, the parent removes it on exit
        self::assertTrue($records[array_key_last($records)]['context']['lockFileExists']);
        self::assertSame([], glob($this->lockDirectory . '/*'));
    }

    public function testRecordsInterleaveWithoutTheLock(): void {
        // Proves the scenario reproduces the problem the lock solves
        self::assertGreaterThan(0, $this->runScenario(false)['broken']);
        self::assertSame([], glob($this->lockDirectory . '/*'));
    }
}
