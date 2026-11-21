<?php
namespace Jspeedz\MultiProcessor\Tests\Support;

use PHPUnit\Framework\TestCase;

/** Runs disposable process groups, with a deadline and cleanup even after failed assertions. */
final class ProcessScenario {
    public static function record(string $path, string $event, array $details = []): void {
        $line = json_encode(['event' => $event, 'pid' => getmypid(), ...$details], JSON_THROW_ON_ERROR) . "\n";
        TestCase::assertSame(strlen($line), file_put_contents($path, $line, FILE_APPEND | LOCK_EX));
    }

    public static function events(string $path): array {
        $stream = fopen($path, 'r');
        flock($stream, LOCK_SH);
        $contents = stream_get_contents($stream);
        flock($stream, LOCK_UN);
        fclose($stream);
        return $contents === '' ? [] : array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", rtrim($contents, "\n")),
        );
    }

    public static function run(string $fixture, array $config, ?callable $observe = null): array {
        $log = tempnam(sys_get_temp_dir(), 'multiprocessor-policy-');
        TestCase::assertNotFalse($log);
        $process = null;
        $pipes = [];
        $pid = null;
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'pcov.enabled=0', '-d', 'xdebug.mode=off',
                    dirname(__DIR__) . '/fixtures/' . $fixture,
                    json_encode(['log' => $log, ...$config], JSON_THROW_ON_ERROR)],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            TestCase::assertIsResource($process);
            $pid = proc_get_status($process)['pid'];
            stream_set_blocking($pipes[1], false);
            $output = '';
            $deadline = hrtime(true) + 60_000_000_000;
            while(!feof($pipes[1])) {
                TestCase::assertLessThan($deadline, hrtime(true), 'Scenario timed out. Output: ' . $output);
                $read = [$pipes[1]];
                $write = $except = [];
                if(stream_select($read, $write, $except, 0, 10000) > 0) {
                    $output .= stream_get_contents($pipes[1]);
                }
                if($observe !== null) {
                    $observe($log, $output);
                }
            }
            fclose($pipes[1]);
            $events = self::events($log);
            // The fixture creates its own session. Its unreaped leader reserves
            // this PID, so a cleanup signal cannot target a reused process group.
            if(in_array('session', array_column($events, 'event'), true)) {
                posix_kill(-$pid, SIGKILL);
            }
            $status = proc_close($process);
            $process = null;
            TestCase::assertNotContains('timeout', array_column($events, 'event'), $output);
            return [$status, $events, $output];
        }
        finally {
            if(is_resource($process)) {
                $events = self::events($log);
                if($pid !== null && in_array('session', array_column($events, 'event'), true)) {
                    posix_kill(-$pid, SIGKILL);
                }
                proc_terminate($process, SIGKILL);
                if(isset($pipes[1]) && is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                proc_close($process);
            }
            foreach([$log, $log . '.release', $log . '.parent-dead'] as $path) {
                if(is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
