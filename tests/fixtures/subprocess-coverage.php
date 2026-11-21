<?php

use SebastianBergmann\CodeCoverage\Driver\Granularity;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

// Loaded only when the invoking PHPUnit test is collecting coverage.
$coverageFilter = new Filter();
// $argv[3] is a source file, or a source directory to cover all classes in
if(is_dir($argv[3])) {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[3], FilesystemIterator::SKIP_DOTS)) as $sourceFile) {
        if($sourceFile instanceof SplFileInfo && $sourceFile->getExtension() === 'php') {
            $coverageFilter->includeFile($sourceFile->getPathname());
        }
    }
}
else {
    $coverageFilter->includeFile($argv[3]);
}
$coverageGranularity = constant(Granularity::class . '::' . $argv[2]);
$coverageDriver = (new Selector())->select($coverageFilter, $coverageGranularity);
$coverageFile = $argv[1];
$coveragePid = posix_getpid();

// exit() must still save coverage. Forked children must not overwrite it.
register_shutdown_function(static function() use ($coverageDriver, $coverageFile, $coveragePid): void {
    if(posix_getpid() === $coveragePid) {
        file_put_contents($coverageFile, serialize($coverageDriver->stop()));
    }
});

$coverageDriver->start();
