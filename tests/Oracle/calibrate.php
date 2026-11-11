<?php

/*
 * The positive control: runs the position matrix against copies of src/
 * with each known bug of mutations.php put back. Every mutation must make
 * some cells fail; the counts go to tests/Oracle/MANIFEST.json.
 *
 *   php tests/Oracle/calibrate.php [mutation]
 *
 * Exits with 1 when a mutation makes no cell fail: the matrix would not
 * catch that bug.
 */

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Cell.php';
require_once __DIR__.'/PositionMatrix.php';
require_once __DIR__.'/Temp.php';

use Composer\InstalledVersions;
use Cordon\Tests\Oracle\PositionMatrix;
use Cordon\Tests\Oracle\Temp;

/**
 * The number of cells that fail with $mutation applied to a copy of src/.
 *
 * @param  array{file: string, search: string, replace: string, why: string}  $mutation
 */
function failing_cells(array $mutation): int
{
    $root = dirname(__DIR__, 2);
    $copy = Temp::directory('cordon-calibration-');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($files as $file) {
        $target = $copy.substr($file->getPathname(), strlen($root.'/src'));
        if ($file->isDir()) {
            mkdir($target, 0777, true);
        } else {
            copy($file->getPathname(), $target);
        }
    }

    $path = $copy.'/'.$mutation['file'];
    $code = (string) file_get_contents($path);

    if (substr_count($code, $mutation['search']) !== 1) {
        throw new RuntimeException(sprintf('The mutation does not match %s exactly once: update tests/Oracle/mutations.php.', $mutation['file']));
    }

    file_put_contents($path, str_replace($mutation['search'], $mutation['replace'], $code));

    $output = [];
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/check-matrix.php').' --src='.escapeshellarg($copy), $output);

    return preg_match('/(\d+) failed/', $output[0] ?? '', $match) === 1 ? (int) $match[1] : 0;
}

/** @var array<string, array{file: string, search: string, replace: string, why: string}> $mutations */
$mutations = require __DIR__.'/mutations.php';
$only = $argv[1] ?? null;
$results = [];

foreach ($mutations as $name => $mutation) {
    if ($only === null || $only === $name) {
        $results[$name] = failing_cells($mutation);
        printf("%-24s %4d failing cells  (%s)\n", $name, $results[$name], $mutation['why']);
    }
}

if ($only === null) {
    file_put_contents(__DIR__.'/MANIFEST.json', json_encode([
        'generator' => sha1((string) file_get_contents(__DIR__.'/PositionMatrix.php')),
        'cells' => count(PositionMatrix::cells()),
        'php' => PHP_VERSION,
        'php_parser' => InstalledVersions::getPrettyVersion('nikic/php-parser'),
        'mutations' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

exit(in_array(0, $results, true) ? 1 : 0);
