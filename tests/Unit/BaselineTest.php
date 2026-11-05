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

it('counts the entries that record more occurrences than the code has', function () {
    $baseline = Baseline::fromViolations([
        violation('/app/Fixed.php'),
        violation('/app/Twice.php'),
        violation('/app/Twice.php'),
        violation('/app/Kept.php'),
    ], '/app');

    expect($baseline->staleEntries([violation('/app/Twice.php'), violation('/app/Kept.php')], '/app'))->toBe(2)
        ->and($baseline->staleEntries([violation('/app/Fixed.php'), violation('/app/Twice.php'), violation('/app/Twice.php'), violation('/app/Kept.php')], '/app'))->toBe(0);
});

it('prunes fixed occurrences without adding new violations', function () {
    $baseline = Baseline::fromViolations([
        violation('/app/Fixed.php'),
        violation('/app/Twice.php'),
        violation('/app/Twice.php'),
        violation('/app/Kept.php'),
    ], '/app');

    $current = [violation('/app/Twice.php'), violation('/app/Kept.php'), violation('/app/New.php')];
    $pruned = $baseline->prune($current, '/app');

    [$remaining, $suppressed] = $pruned->filter($current, '/app');

    expect($pruned->count())->toBe(2)
        ->and($suppressed)->toBe(2)
        ->and($remaining)->toEqual([violation('/app/New.php')])
        ->and($pruned->staleEntries($current, '/app'))->toBe(0);
});

it('prunes the cycle entries of a version 1 baseline', function () {
    $file = sys_get_temp_dir().'/cordon-baseline-'.uniqid().'.json';
    $baseline = Baseline::load(fixture_path('baselines/v1-cycles.json'));

    $stillThere = violation('/app/app/Modules/Billing/Invoice.php', 10, 'App\\Modules\\Catalog\\Internal\\Price');

    $baseline->prune([$stillThere], '/app')->save($file);
    $saved = json_decode((string) file_get_contents($file), true);
    unlink($file);

    expect($saved['version'])->toBe(2)
        ->and(array_column($saved['violations'], 'rule'))->toBe(['internal_access']);
});
