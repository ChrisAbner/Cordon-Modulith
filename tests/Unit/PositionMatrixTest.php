<?php

use Cordon\Tests\Oracle\MatrixChecker;
use Cordon\Tests\Oracle\PositionMatrix;

it('finds exactly the expected targets at every position, in every form and container', function (string $position) {
    $cells = array_values(array_filter(PositionMatrix::cells(), fn ($cell) => $cell->position === $position));
    ['failures' => $failures, 'skipped' => $skipped] = MatrixChecker::check($cells, sys_get_temp_dir().'/cordon-matrix-test-'.getmypid());

    if ($skipped !== [] && count($skipped) === count($cells)) {
        $this->markTestSkipped($skipped[0]);
    }

    expect($failures)->toBe([]);
})->with(array_keys(PositionMatrix::POSITIONS));

it('generates the same cells on every run', function () {
    $first = sys_get_temp_dir().'/cordon-matrix-a-'.getmypid();
    $second = sys_get_temp_dir().'/cordon-matrix-b-'.getmypid();
    PositionMatrix::write($first);
    PositionMatrix::write($second);

    expect(file_get_contents($first.'/MANIFEST.json'))->toBe(file_get_contents($second.'/MANIFEST.json'))
        ->and(count(PositionMatrix::cells()))->toBeGreaterThan(1000);
});
