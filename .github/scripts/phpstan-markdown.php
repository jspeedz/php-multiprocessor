<?php
declare(strict_types=1);

/**
 * Converts a PHPStan JSON report (--error-format=json) into a Markdown summary,
 * or into GitHub Actions workflow commands so errors show up as annotations.
 *
 * Usage: php phpstan-markdown.php <report.json> [<source root to strip from file names>] [--annotations]
 */

$arguments = array_values(array_filter(array_slice($argv, 1), static fn(string $argument): bool => $argument !== '--annotations'));
$annotations = in_array('--annotations', $argv, true);

if($arguments === []) {
    fwrite(STDERR, "Usage: php {$argv[0]} <report.json> [<source root>] [--annotations]\n");
    exit(1);
}

$root = rtrim($arguments[1] ?? getcwd(), '/') . '/';

$contents = is_readable($arguments[0]) ? file_get_contents($arguments[0]) : false;
$report = $contents === false ? null : json_decode($contents, true);

if(!is_array($report) || !isset($report['totals'])) {
    // PHPStan crashed before it could write a report (fatal error, out of memory, invalid config, ...)
    if($annotations) {
        echo "::error title=PHPStan::PHPStan did not produce a report, check the job log\n";
        exit(0);
    }

    echo "## PHPStan\n\n🔴 PHPStan did not produce a report, check the job log for details.\n";
    exit(0);
}

function relativePath(string $file, string $root): string {
    return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
}

function escapeData(string $value): string {
    return strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
}

function escapeProperty(string $value): string {
    return strtr(escapeData($value), [':' => '%3A', ',' => '%2C']);
}

function escapeCell(string $value): string {
    // HTML-escape first (including &, so entities in messages show literally), then the table and line breaks
    return strtr(htmlspecialchars(trim($value)), ['|' => '&#124;', "\r\n" => '<br>', "\n" => '<br>']);
}

/** @var array<string, list<array{message: string, line: int|null, identifier: string|null, tip: string|null}>> $files */
$files = [];
foreach($report['files'] ?? [] as $file => $result) {
    $name = relativePath((string) $file, $root);
    foreach($result['messages'] ?? [] as $message) {
        $files[$name][] = [
            'message' => (string) ($message['message'] ?? ''),
            'line' => isset($message['line']) ? (int) $message['line'] : null,
            'identifier' => isset($message['identifier']) ? (string) $message['identifier'] : null,
            'tip' => isset($message['tip']) ? (string) $message['tip'] : null,
        ];
    }
}
ksort($files);

/** @var list<string> $generalErrors */
$generalErrors = array_map('strval', $report['errors'] ?? []);

if($annotations) {
    foreach($files as $name => $messages) {
        foreach($messages as $message) {
            $properties = ['file=' . escapeProperty($name)];
            if($message['line'] !== null) {
                $properties[] = 'line=' . $message['line'];
            }
            $properties[] = 'title=' . escapeProperty('PHPStan' . ($message['identifier'] !== null ? ": {$message['identifier']}" : ''));

            $text = $message['message'] . ($message['tip'] !== null ? "\n💡 {$message['tip']}" : '');
            echo '::error ', implode(',', $properties), '::', escapeData($text), "\n";
        }
    }
    foreach($generalErrors as $error) {
        echo '::error title=PHPStan::', escapeData($error), "\n";
    }

    exit(0);
}

$fileErrors = array_sum(array_map('count', $files));
$total = $fileErrors + count($generalErrors);

$output = [];
$output[] = '## PHPStan';
$output[] = '';

if($total === 0) {
    $output[] = '🟢 No errors found.';
    echo implode("\n", $output), "\n";
    exit(0);
}

$output[] = sprintf(
    '🔴 Found **%d** %s in **%d** %s%s.',
    $total,
    $total === 1 ? 'error' : 'errors',
    count($files),
    count($files) === 1 ? 'file' : 'files',
    $generalErrors !== [] ? sprintf(' (%d not tied to a file)', count($generalErrors)) : '',
);

foreach($files as $name => $messages) {
    usort($messages, static fn(array $a, array $b): int => ($a['line'] ?? 0) <=> ($b['line'] ?? 0));

    $output[] = '';
    $output[] = '<details>';
    $output[] = sprintf('<summary><code>%s</code> (%d)</summary>', htmlspecialchars($name), count($messages));
    $output[] = '';
    $output[] = '| Line | Error |';
    $output[] = '|--:|---|';
    foreach($messages as $message) {
        $text = escapeCell($message['message']);
        if($message['identifier'] !== null) {
            $text .= ' <sub><code>' . escapeCell($message['identifier']) . '</code></sub>';
        }
        if($message['tip'] !== null) {
            $text .= '<br>💡 ' . escapeCell($message['tip']);
        }
        $output[] = sprintf('| %s | %s |', $message['line'] ?? '–', $text);
    }
    $output[] = '';
    $output[] = '</details>';
}

if($generalErrors !== []) {
    $output[] = '';
    $output[] = '**Errors not tied to a file**';
    $output[] = '';
    foreach($generalErrors as $error) {
        $output[] = '- ' . escapeCell($error);
    }
}

echo implode("\n", $output), "\n";
