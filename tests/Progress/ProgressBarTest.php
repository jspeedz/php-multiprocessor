<?php
namespace Jspeedz\MultiProcessor\Tests\Progress;

use Jspeedz\MultiProcessor\Progress\ProgressBar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProgressBar::class)]
final class ProgressBarTest extends TestCase {
    public function testStartShowsHeaderAndEmptyProgress(): void {
        (new ProgressBar(12))->start();
        $this->expectOutputString('Chunks                    Progress                           '
            . '     Elapsed  / Remaining' . "\r" . PHP_EOL . ' 0/12    '
            . ' [' . str_repeat(' ', 41) . ']   0.00%     00:00:00 / ??:??:??');
    }

    public function testUpdateAndFinish(): void {
        $progressBar = new ProgressBar(4, time());
        $progressBar->update(4);
        $progressBar->finish();
        $this->expectOutputRegex('/^\r\r4\/4     \[={40}> {0}\] 100\.00%     00:00:0\d \/ 00:00:00' . PHP_EOL . '$/');
    }

    #[TestWith([2, 2, '50.00%', 3661, '01:01:01 / 01:01:01'])]
    #[TestWith([4, 0, '100.00%', 36610, '10:10:10 / 00:00:00'])]
    #[TestWith([1, 3, '25.00%', 360000, '100:00:00 / 300:00:00'])]
    public function testProgressStatistics(int $completed, int $remaining, string $percentage, int $elapsed, string $times): void {
        $timeStarted = time() - $elapsed;
        $statistics = (new ProgressBar($completed + $remaining, $timeStarted))->render($completed);
        self::assertStringStartsWith("\r" . $completed . '/4    ' . ' [' . str_repeat('=', $completed * 10) . '>' . str_repeat(' ', $remaining * 10) . ']', $statistics);
        self::assertStringContainsString($percentage, $statistics);

        // The clock may tick while rendering
        $possibleTimes = [];
        for($seconds = $elapsed; $seconds <= time() - $timeStarted; $seconds++) {
            $possibleTimes[] = $this->duration($seconds) . ' / ' . $this->duration((int) ceil($seconds / $completed * $remaining));
        }
        self::assertContains(substr($statistics, -strlen($times)), $possibleTimes);
        self::assertSame($times, $possibleTimes[0]);
    }

    private function duration(int $seconds): string {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
