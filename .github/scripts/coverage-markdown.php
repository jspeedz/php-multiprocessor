<?php

declare(strict_types=1);

/**
 * Converts a PHPUnit Clover XML coverage report into a Markdown summary.
 *
 * Usage: php coverage-markdown.php <clover.xml> [<source root to strip from file names>]
 */

if($argc < 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} <clover.xml> [<source root>]\n");
    exit(1);
}

$xml = simplexml_load_file($argv[1]);
if($xml === false) {
    fwrite(STDERR, "Could not read Clover report {$argv[1]}\n");
    exit(1);
}

$root = rtrim($argv[2] ?? getcwd(), '/') . '/';

function percentage(int $covered, int $total): string {
    if($total === 0) {
        return '–';
    }

    return number_format($covered / $total * 100, 2) . '%';
}

function badge(int $covered, int $total): string {
    if($total === 0) {
        return '⚪';
    }

    $ratio = $covered / $total;

    return match (true) {
        $ratio >= 0.9 => '🟢',
        $ratio >= 0.5 => '🟡',
        default => '🔴',
    };
}

$project = $xml->project->metrics;
$lines = [(int) $project['coveredstatements'], (int) $project['statements']];
$methods = [(int) $project['coveredmethods'], (int) $project['methods']];
$conditionals = [(int) $project['coveredconditionals'], (int) $project['conditionals']];

$output = [];
$output[] = '## Code coverage';
$output[] = '';
$output[] = '| | Covered | Total | Coverage |';
$output[] = '|---|--:|--:|--:|';
$output[] = sprintf('| %s Lines | %d | %d | %s |', badge(...$lines), $lines[0], $lines[1], percentage(...$lines));
$output[] =
    sprintf('| %s Methods | %d | %d | %s |', badge(...$methods), $methods[0], $methods[1], percentage(...$methods));
if($conditionals[1] > 0) {
    $output[] = sprintf(
        '| %s Branches | %d | %d | %s |',
        badge(...$conditionals),
        $conditionals[0],
        $conditionals[1],
        percentage(...$conditionals),
    );
}

$files = [];
foreach($xml->xpath('//file') ?: [] as $file) {
    $metrics = $file->metrics;
    $statements = (int) $metrics['statements'];
    if($statements === 0) {
        // Interfaces and other files without executable code
        continue;
    }

    $name = (string) $file['name'];
    if(str_starts_with($name, $root)) {
        $name = substr($name, strlen($root));
    }

    $files[$name] = [
        (int) $metrics['coveredstatements'],
        $statements,
        (int) $metrics['coveredmethods'],
        (int) $metrics['methods'],
    ];
}
ksort($files);

if($files !== []) {
    $output[] = '';
    $output[] = '<details>';
    $output[] = '<summary>Coverage per file</summary>';
    $output[] = '';
    $output[] = '| File | Lines | Methods |';
    $output[] = '|---|--:|--:|';
    foreach($files as $name => [$coveredLines, $totalLines, $coveredMethods, $totalMethods]) {
        $output[] = sprintf(
            '| %s `%s` | %s (%d/%d) | %s (%d/%d) |',
            badge($coveredLines, $totalLines),
            $name,
            percentage($coveredLines, $totalLines),
            $coveredLines,
            $totalLines,
            percentage($coveredMethods, $totalMethods),
            $coveredMethods,
            $totalMethods,
        );
    }
    $output[] = '';
    $output[] = '</details>';
}

echo implode("\n", $output), "\n";
