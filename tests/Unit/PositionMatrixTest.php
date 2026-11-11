<?php

use Cordon\Tests\Oracle\MatrixChecker;
use Cordon\Tests\Oracle\PositionMatrix;
use Cordon\Tests\Oracle\Temp;

it('finds exactly the expected targets at every position, in every form and container', function (string $position) {
    $cells = array_values(array_filter(PositionMatrix::cells(), fn ($cell) => $cell->position === $position));
    ['failures' => $failures, 'skipped' => $skipped] = MatrixChecker::check($cells, Temp::directory('cordon-matrix-'));

    if ($skipped !== [] && count($skipped) === count($cells)) {
        $this->markTestSkipped($skipped[0]);
    }

    expect($failures)->toBe([]);
})->with(array_keys(PositionMatrix::POSITIONS));

it('generates the same cells on every run', function () {
    $first = Temp::directory('cordon-matrix-');
    $second = Temp::directory('cordon-matrix-');
    PositionMatrix::write($first);
    PositionMatrix::write($second);

    expect(file_get_contents($first.'/MANIFEST.json'))->toBe(file_get_contents($second.'/MANIFEST.json'))
        ->and(count(PositionMatrix::cells()))->toBeGreaterThan(1000);
});
