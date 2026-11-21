<?php
namespace Jspeedz\MultiProcessor\Progress;

/**
 * Console progress bar of the processed chunks. Note that the time estimate is very rough.
 * It'll only be accurate when every handled item takes exactly the same time to process.
 *
 * @internal
 */
final class ProgressBar {
    /**
     * @param non-negative-int $total Number of chunks to process
     * @param int|null $timeStarted Unix timestamp the run started, defaults to now
     */
    public function __construct(
        private readonly int $total,
        private ?int $timeStarted = null,
    ) {
        $this->timeStarted ??= time();
    }

    /**
     * Show the header and the initial (empty) progress.
     */
    public function start(): void {
        echo 'Chunks                    Progress                           ' . str_repeat(' ', $this->counterWidth()) . 'Elapsed  / Remaining';
        echo "\r" . $this->render(0);
    }

    /**
     * @param non-negative-int $processed
     */
    public function update(int $processed): void {
        echo "\r" . $this->render($processed);
    }

    public function finish(): void {
        echo PHP_EOL;
    }

    /**
     * @param non-negative-int $processed
     */
    public function render(int $processed): string {
        if($processed === 0) {
            return PHP_EOL . sprintf('%1$\' ' . $this->counterWidth() . 's', '0/' . $this->total) . '    ' .
                ' [' . str_repeat(' ', 41) . '] ' . sprintf('%1$\' 6.2f%%', 0) .
                '     00:00:00 / ??:??:??';
        }

        $elapsedTime = time() - (int) $this->timeStarted;
        $averageTimePerChunk = $elapsedTime / $processed;
        $timeToGo = (int) ceil($averageTimePerChunk * ($this->total - $processed));
        $percentage = $processed * 100 / $this->total;

        return "\r" . sprintf('%1$\' ' . $this->counterWidth() . 's', $processed . '/' . $this->total) . '    ' .
            ' [' . str_repeat('=', (int) round($percentage / 2.5)) . '>' . str_repeat(' ', 40 - (int) round($percentage / 2.5)) . '] ' . sprintf('%1$\' 6.2f%%', $percentage) .
            '     ' . $this->formatDuration($elapsedTime) . ' / ' . $this->formatDuration($timeToGo);
    }

    /**
     * Width of the "processed/total" counter.
     */
    private function counterWidth(): int {
        return strlen((string) $this->total) * 2 + 1;
    }

    private function formatDuration(int $seconds): string {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
