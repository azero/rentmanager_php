<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = [$root . '/src', $root . '/examples', $root . '/tests'];
$files = [$root . '/autoload.php'];

foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

$files = array_values(array_unique($files));
sort($files);
$failed = false;

foreach ($files as $file) {
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
    echo implode(PHP_EOL, $output) . PHP_EOL;
    $failed = $failed || $exitCode !== 0;
}

if ($failed) {
    exit(1);
}

echo 'All PHP files passed syntax checks.' . PHP_EOL;
