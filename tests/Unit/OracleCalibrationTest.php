<?php

use Cordon\Tests\Oracle\PositionMatrix;

it('fails the matrix with each known bug put back', function (string $mutation) {
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Oracle/calibrate.php').' '.escapeshellarg($mutation), $output, $code);

    expect($code)->toBe(0)
        ->and(implode("\n", $output))->toMatch('/'.preg_quote($mutation, '/').'\s+[1-9]\d* failing cells/');
})->with(array_keys(require dirname(__DIR__).'/Oracle/mutations.php'))->group('oracle');

it('has a calibration for the current matrix', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/Oracle/MANIFEST.json'), true);

    expect($manifest['cells'] ?? null)->toBe(count(PositionMatrix::cells()), 'The matrix changed: run php tests/Oracle/calibrate.php')
        ->and($manifest['generator'] ?? null)->toBe(sha1((string) file_get_contents(dirname(__DIR__).'/Oracle/PositionMatrix.php')), 'The matrix changed: run php tests/Oracle/calibrate.php')
        ->and(array_keys($manifest['mutations'] ?? []))->toBe(array_keys(require dirname(__DIR__).'/Oracle/mutations.php'))
        ->and(array_filter($manifest['mutations'], fn (int $failing) => $failing === 0))->toBe([]);
});

it('agrees with reflection on the declaration positions', function () {
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Oracle/reflection.php'), $output, $code);

    expect($code)->toBe(0, implode("\n", $output))
        ->and($output[0] ?? '')->toMatch('/[1-9]\d* cells checked .*, 0 failed/');
})->group('oracle');
