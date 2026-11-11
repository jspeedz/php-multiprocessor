<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum;
use Jspeedz\MultiProcessor\Tests\Support\ProcessScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParentDeathPolicyTest extends TestCase {
    public static function settings(): iterable {
        foreach([false, true] as $check) {
            foreach([1, 3] as $concurrency) {
                foreach(StopOnFatalMethodEnum::cases() as $method) {
                    yield 'check=' . (int) $check . ", children=$concurrency, {$method->value}" => [$check, $concurrency, $method->value];
                }
            }
        }
    }

    #[DataProvider('settings')]
    public function testRealParentDeathStopsOrCompletesAssignedChunks(bool $check, int $concurrency, string $method): void {
        [$status, $events, $output] = ProcessScenario::run('parent-death-policy.php', [
            'parentCheck' => $check, 'concurrency' => $concurrency, 'method' => $method,
        ]);
        self::assertSame(0, $status, $output);
        self::assertNotContains('unexpected-return', array_column($events, 'event'));
        $select = static fn(string $name): array => array_values(array_filter($events, static fn(array $event): bool => $event['event'] === $name));
        self::assertCount(1, $select('manager-reaped'));
        self::assertSame(SIGKILL, $select('manager-reaped')[0]['signal']);
        self::assertCount($concurrency, $select('start'), 'Only the assigned chunks may start; queued work has no living manager.');
        self::assertCount($concurrency, $select('child-shutdown'));
        self::assertCount($check ? 0 : $concurrency, $select('finish'));
        self::assertCount($check ? 0 : $concurrency, $select('exit'));
        self::assertSame('workers-exited', $events[array_key_last($events)]['event']);
        $processed = array_column($select('processed'), 'item');
        sort($processed);
        self::assertSame($check ? array_map(static fn(int $chunk): int => $chunk * 20, range(0, $concurrency - 1)) : range(0, $concurrency * 20 - 1), $processed);
        foreach($select('start') as $start) {
            $child = array_values(array_filter($events, static fn(array $event): bool => $event['pid'] === $start['pid']));
            self::assertSame($check
                ? ['start', 'processed', 'held', 'parent-loss-observed', 'child-shutdown']
                : ['start', 'processed', 'held', 'parent-loss-observed', ...array_fill(0, 19, 'processed'), 'finish', 'exit', 'child-shutdown'],
                array_column($child, 'event'));
        }
        $deathIndex = array_search('manager-reaped', array_column($events, 'event'), true);
        foreach($events as $index => $event) {
            if(in_array($event['event'], ['parent-loss-observed', 'child-shutdown', 'finish', 'exit'], true)) {
                self::assertGreaterThan($deathIndex, $index, 'The continuation decision must occur after actual parent death.');
            }
        }
    }
}
