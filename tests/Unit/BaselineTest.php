<?php

use Cordon\Analysis\Violation;
use Cordon\Baseline\Baseline;

function violation(string $file, int $line = 10, string $target = 'B\\Internal'): Violation
{
    return new Violation('internal_access', 'message', $file, $line, 'A', 'B', $target);
}

it('suppresses known violations and keeps new ones', function () {
    $known = violation('/app/Modules/A/X.php');
    $new = violation('/app/Modules/A/Z.php');

    [$remaining, $suppressed] = Baseline::fromViolations([$known], '/app')->filter([$known, $new], '/app');

    expect($suppressed)->toBe(1)
        ->and($remaining)->toBe([$new]);
});

it('survives a save and load round trip and ignores line changes', function () {
    $file = sys_get_temp_dir().'/cordon-baseline-'.uniqid().'.json';

    Baseline::fromViolations([violation('/app/Modules/A/X.php', 10)], '/app')->save($file);
    [$remaining, $suppressed] = Baseline::load($file)->filter([violation('/app/Modules/A/X.php', 99)], '/app');
    unlink($file);

    expect($remaining)->toBeEmpty()
        ->and($suppressed)->toBe(1);
});

it('only suppresses as many occurrences as were recorded', function () {
    $baseline = Baseline::fromViolations([violation('/app/X.php')], '/app');

    [$remaining, $suppressed] = $baseline->filter([violation('/app/X.php'), violation('/app/X.php')], '/app');

    expect($remaining)->toHaveCount(1)
        ->and($suppressed)->toBe(1);
});
