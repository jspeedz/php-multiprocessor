<?php

/**
 * Child memory is isolated after fork: record completed work on disk instead.
 * Open the file for each write so resource-close callbacks cannot close its handle.
 */
final class CompletionLog {
    public function __construct(private string $path) {}

    public function record(string $item): void {
        $line = json_encode($item, JSON_THROW_ON_ERROR) . "\n";
        if(file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('Could not record completed work.');
        }
    }

    /** @param list<string> $expected */
    public function verify(array $expected): void {
        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        if($lines === false) {
            throw new RuntimeException('Could not read completed work.');
        }
        $completed = array_map(
            static fn(string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
        // Order varies between children. Compare every value, retaining duplicates.
        sort($expected, SORT_STRING);
        sort($completed, SORT_STRING);
        if($expected !== $completed) {
            throw new RuntimeException(sprintf(
                'Completion verification failed: expected %d items, recorded %d; missing, duplicate, or unexpected work.',
                count($expected), count($completed),
            ));
        }
    }
}
