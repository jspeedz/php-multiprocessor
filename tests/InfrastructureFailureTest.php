<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Exception\ProcessException;
use Jspeedz\MultiProcessor\Tests\Support\ProcessScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InfrastructureFailureTest extends TestCase {
    public static function failures(): iterable {
        yield 'first fork fails' => ['fork', 1, 'once'];
        yield 'fork fails while a real child is running' => ['fork', 2, 'once'];
        yield 'ONCE callback throws' => ['callback', 1, 'once'];
        yield 'first ALWAYS callback throws' => ['callback', 1, 'always'];
        yield 'ALWAYS callback throws while a real child is running' => ['callback', 2, 'always'];
    }

    #[DataProvider('failures')]
    public function testSchedulingExceptionsPropagateAndDoNotSpawnLaterChunks(string $kind, int $at, string $trigger): void {
        [$status, $events, $output] = ProcessScenario::run('infrastructure-failure.php', compact('kind', 'at', 'trigger'));
        self::assertSame(0, $status, $output);
        self::assertNotContains('unexpected-return', array_column($events, 'event'));
        $select = static fn(string $name): array => array_values(array_filter($events, static fn(array $event): bool => $event['event'] === $name));
        self::assertCount(1, $select('exception'));
        $exception = $select('exception')[0];
        self::assertSame($kind === 'fork' ? ProcessException::class : RuntimeException::class, $exception['class']);
        self::assertSame($kind === 'fork' ? 'Could not fork' : 'Intentional close-resource callback failure.', $exception['message']);
        $spawned = $select('spawned');
        self::assertCount($at - 1, $spawned, 'There must be no fork after the scheduling exception.');
        self::assertSame(array_column($spawned, 'childPID'), array_values($exception['tracked']));
        self::assertSame(array_column($spawned, 'childPID'), array_column($select('caller-reaped'), 'childPID'));
        self::assertSame(array_fill(0, $at - 1, 0), array_column($select('caller-reaped'), 'exitCode'));
        self::assertSame($at === 1 ? [] : range(0, 4), array_column($select('processed'), 'item'));
        self::assertCount($at - 1, $select('finish'));
        self::assertCount($kind === 'callback' ? $at : 0, $select('callback'));
        self::assertCount($kind === 'fork' ? 1 : 0, $select('fork-failed'));
        $caught = array_search('exception', array_column($events, 'event'), true);
        foreach($events as $index => $event) {
            if(in_array($event['event'], ['processed', 'caller-reaped'], true)) {
                self::assertGreaterThan($caught, $index, 'Existing work is released and reaped explicitly by the caller after catching the exception.');
            }
        }
    }
}
