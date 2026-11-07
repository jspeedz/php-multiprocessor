<?php
declare(strict_types=1);

/**
 * Converts a PHPUnit JUnit XML report (--log-junit) into a Markdown summary of the test results.
 *
 * Usage: php junit-markdown.php <junit.xml> [<source root to strip from file names>]
 */

// Keep the summary well below GitHub's 65536 character limit for PR comments
const MAX_LISTED_TESTS = 25;
const MAX_MESSAGE_LENGTH = 2000;

if($argc < 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} <junit.xml> [<source root>]\n");
    exit(1);
}

$root = rtrim($argv[2] ?? getcwd(), '/') . '/';

$xml = is_readable($argv[1]) ? simplexml_load_file($argv[1]) : false;
$suite = $xml === false ? null : ($xml->testsuite[0] ?? null);

if($suite === null) {
    // PHPUnit crashed before it could write a report (fatal error, segfault, invalid config, ...)
    echo "## PHPUnit\n\n🔴 PHPUnit did not produce a test report, check the job log for details.\n";
    exit(0);
}

function relativePath(string $file, string $root): string {
    return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
}

/**
 * Escape a value for inline use in Markdown, also inside inline HTML (which GitHub still parses Markdown in).
 * Markdown punctuation becomes numeric entities, so test names like `[x](https://…)` or `*a*` show literally.
 */
function escapeInline(string $value): string {
    $value = htmlspecialchars($value);

    return preg_replace_callback(
        '/[\\\\`*_{}\[\]()!|~]/',
        static fn(array $match): string => '&#' . ord($match[0]) . ';',
        $value,
    ) ?? $value;
}

function duration(float $seconds): string {
    if($seconds < 60) {
        return number_format($seconds, 2) . 's';
    }

    return sprintf('%dm %02ds', intdiv((int) $seconds, 60), (int) $seconds % 60);
}

/**
 * @param list<array{test: string, location: string, type: string, message: string}> $tests
 *
 * @return list<string>
 */
function testList(string $title, array $tests, bool $open): array {
    if($tests === []) {
        return [];
    }

    $output = [];
    $output[] = '';
    $output[] = $open ? '<details open>' : '<details>';
    $output[] = sprintf('<summary>%s (%d)</summary>', $title, count($tests));
    foreach(array_slice($tests, 0, MAX_LISTED_TESTS) as $test) {
        $output[] = '';
        $output[] = sprintf(
            '**%s**<br><sub><code>%s</code>%s</sub>',
            escapeInline($test['test']),
            escapeInline($test['location']),
            $test['type'] !== '' ? ' · <code>' . escapeInline($test['type']) . '</code>' : '',
        );

        $message = trim($test['message']);
        if($message !== '') {
            if(mb_strlen($message) > MAX_MESSAGE_LENGTH) {
                $message = mb_substr($message, 0, MAX_MESSAGE_LENGTH) . "\n… (truncated)";
            }
            $output[] = '';
            $output[] = '<pre>' . htmlspecialchars($message) . '</pre>';
        }
    }
    if(count($tests) > MAX_LISTED_TESTS) {
        $output[] = '';
        $output[] = sprintf('… and %d more, see the job log.', count($tests) - MAX_LISTED_TESTS);
    }
    $output[] = '';
    $output[] = '</details>';

    return $output;
}

$total = (int) $suite['tests'];
$failures = (int) $suite['failures'];
$errors = (int) $suite['errors'];
$skipped = (int) $suite['skipped'];
$passed = $total - $failures - $errors - $skipped;

$failed = [];
$skippedTests = [];
foreach($xml->xpath('//testcase') ?: [] as $testcase) {
    $problem = $testcase->failure[0] ?? $testcase->error[0] ?? null;
    $skip = $testcase->skipped[0] ?? null;
    if($problem === null && $skip === null) {
        continue;
    }

    $class = (string) $testcase['class'];
    $test = [
        'test' => ($class !== '' ? $class . '::' : '') . $testcase['name'],
        'location' => relativePath((string) $testcase['file'], $root) . ':' . $testcase['line'],
        'type' => (string) (($problem ?? $skip)['type'] ?? ''),
        'message' => str_replace($root, '', (string) ($problem ?? $skip)),
    ];

    if($problem !== null) {
        // The first line of the message repeats the test name, which is already shown
        $lines = explode("\n", $test['message'], 2);
        if(str_contains($lines[0], (string) $testcase['name'])) {
            $test['message'] = $lines[1] ?? '';
        }

        $failed[] = $test;
    }
    else {
        $skippedTests[] = $test;
    }
}

$output = [];
$output[] = '## PHPUnit';
$output[] = '';
$output[] = $failures + $errors === 0
    ? sprintf('🟢 All %d tests passed%s.', $passed, $skipped > 0 ? " ({$skipped} skipped)" : '')
    : sprintf('🔴 %d of %d tests failed.', $failures + $errors, $total);
$output[] = '';
$output[] = '| Tests | ✅ Passed | ❌ Failed | 💥 Errors | ⏭️ Skipped | Assertions | Duration |';
$output[] = '|--:|--:|--:|--:|--:|--:|--:|';
$output[] = sprintf(
    '| %d | %d | %d | %d | %d | %d | %s |',
    $total,
    $passed,
    $failures,
    $errors,
    $skipped,
    (int) $suite['assertions'],
    duration((float) $suite['time']),
);

array_push($output, ...testList('Failed tests', $failed, true));
array_push($output, ...testList('Skipped tests', $skippedTests, false));

echo implode("\n", $output), "\n";
