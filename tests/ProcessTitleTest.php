<?php
namespace Jspeedz\MultiProcessor\Tests;

use Jspeedz\MultiProcessor\Tests\Support\ProcessScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessTitleTest extends TestCase {
    /**
     * Five items in chunks of two give three children: chunk 1/3 [1, 2], 2/3 [3, 4] and 3/3 [5].
     * A title set before run() is the base of every title, otherwise the command line is.
     */
    public static function scenarios(): iterable {
        yield 'command line as base' => [null];
        yield 'custom title as base' => ['my-import-job'];
    }

    /**
     * Expect the parent to be listed by ps with ` (<parent PID>-main)` while the children run, and every child with
     * ` (<parent PID>-fork n/3)` for the chunk it processes. After run() the parent has its original title back.
     */
    #[DataProvider('scenarios')]
    public function testProcessesAreTitledByRole(?string $title): void {
        if(!function_exists('cli_set_process_title') || !is_executable('/bin/ps')) {
            self::markTestSkipped('Process titles need the CLI SAPI and ps.');
        }

        [$status, $events, $output] = ProcessScenario::run('process-titles.php', [
            'items' => [1, 2, 3, 4, 5],
            'title' => $title,
        ]);
        self::assertSame(0, $status, $output);

        $byEvent = [];
        foreach($events as $event) {
            $byEvent[$event['event']][] = $event;
        }
        $base = $byEvent['before'][0]['title'];
        if($title !== null) {
            self::assertSame($title, $base);
        }

        $children = $byEvent['child'];
        usort($children, static fn(array $a, array $b): int => $a['items'][0] <=> $b['items'][0]);
        self::assertSame([[1, 2], [3, 4], [5]], array_column($children, 'items'));

        $parentPID = $byEvent['before'][0]['pid'];
        $mainSuffix = ' (' . $parentPID . '-main)';
        $expectedBase = $children[0]['parentTitle'];
        self::assertStringEndsWith($mainSuffix, $expectedBase);
        $expectedBase = substr($expectedBase, 0, -strlen($mainSuffix));
        // Linux reads the exact command line from /proc, elsewhere it is rebuilt without the interpreter options.
        self::assertContains($expectedBase, $title !== null ? [$title] : [$base, $this->commandLineBase($base)]);
        foreach($children as $index => $child) {
            self::assertSame($expectedBase . ' (' . $parentPID . '-fork ' . ($index + 1) . '/3)', $child['title']);
            self::assertSame($expectedBase . $mainSuffix, $child['parentTitle']);
        }
        self::assertSame($expectedBase, $byEvent['after'][0]['title'], 'The title from before run() must be restored.');
    }

    /**
     * Without a title, ps lists the full command line (`/path/php -d ... fixture.php {json}`).
     * Rebuilt from what PHP exposes, it is `php fixture.php {json}`.
     */
    private function commandLineBase(string $listed): string {
        $script = dirname(__DIR__) . '/tests/fixtures/process-titles.php';
        $position = strpos($listed, ' ' . $script . ' ');
        self::assertNotFalse($position, $listed);
        return basename(PHP_BINARY) . substr($listed, $position);
    }
}
