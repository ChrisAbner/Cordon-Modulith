<?php

/**
 * The temporary directories whose name starts with $prefix.
 *
 * @return list<string>
 */
function temp_directories(string $prefix): array
{
    return glob(sys_get_temp_dir().'/'.$prefix.'*', GLOB_ONLYDIR) ?: [];
}

it('leaves no temporary directories behind', function (string $command, string $prefix) {
    $before = temp_directories($prefix);
    exec($command, $output, $code);

    expect($code)->toBe(0, implode("\n", $output))
        ->and(array_values(array_diff(temp_directories($prefix), $before)))->toBe([]);
})->with([
    'the matrix' => [escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Oracle/check-matrix.php'), 'cordon-matrix-'],
    'the calibration' => [escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Oracle/calibrate.php').' skip-attributes', 'cordon-calibration-'],
    'a metamorphic copy' => [escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('require "'.dirname(__DIR__, 2).'/vendor/autoload.php"; Cordon\Tests\Oracle\Transformations::copy("'.dirname(__DIR__).'/Fixtures/namespace-app");'), 'cordon-metamorphic-'],
]);
