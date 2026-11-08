<?php

use Cordon\Rules\Severity;

it('reads a severity and its boolean aliases', function (mixed $value, Severity $expected) {
    expect(Severity::fromConfig('cycles', $value))->toBe($expected);
})->with([
    'error' => ['error', Severity::Error],
    'warn' => ['warn', Severity::Warn],
    'off' => ['off', Severity::Off],
    'true' => [true, Severity::Error],
    'false' => [false, Severity::Off],
]);

it('rejects any other value', function (mixed $value, string $shown) {
    expect(fn () => Severity::fromConfig('cycles', $value))->toThrow(
        InvalidArgumentException::class,
        sprintf('Rule [cycles] has an invalid value [%s]. Use error, warn or off (or true/false).', $shown),
    );
})->with([
    'a near miss' => ['warning', 'warning'],
    'an integer' => [0, '0'],
    'a quoted boolean' => ['true', 'true'],
    'an array' => [['warn'], 'array'],
]);
