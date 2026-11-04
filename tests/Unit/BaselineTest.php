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

it('writes version 2 baselines', function () {
    $file = sys_get_temp_dir().'/cordon-baseline-'.uniqid().'.json';

    Baseline::fromViolations([violation('/app/X.php')], '/app')->save($file);
    $data = json_decode((string) file_get_contents($file), true);
    unlink($file);

    expect($data['version'])->toBe(2)
        ->and(Baseline::load(fixture_path('baselines/v1-internal-access.json'))->warnings())->toBe([]);
});

it('warns that cycle entries of a version 1 baseline no longer match', function () {
    expect(Baseline::load(fixture_path('baselines/v1-cycles.json'))->warnings())->toBe([
        'The baseline has 1 cycles entry from Cordon 0.1, which reported one violation per cycle. Cycles are now reported per dependency, so that entry no longer matches. Regenerate the baseline with php artisan cordon:verify --generate-baseline.',
    ]);
});
