<?php

use Cordon\Analysis\Violation;
use Cordon\Baseline\Baseline;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Tests\Oracle\Temp;

mutates(Baseline::class);

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
    $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-baseline-'.uniqid().'.json');

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
    $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-baseline-'.uniqid().'.json');

    Baseline::fromViolations([violation('/app/X.php')], '/app')->save($file);
    $data = json_decode((string) file_get_contents($file), true);
    unlink($file);

    expect($data['version'])->toBe(2)
        ->and(Baseline::load(fixture_path('baselines/v1-internal-access.json'))->warnings())->toBe([]);
});

it('warns that cycle entries of a version 1 baseline no longer match', function () {
    expect(Baseline::load(fixture_path('baselines/v1-cycles.json'))->warnings())->toBe([
        'The baseline has 1 cycles entry from Cordon 0.1, which reported one violation per cycle. Cycles are now reported per dependency, so that entry no longer matches. Regenerate the baseline with cordon:verify --generate-baseline (or vendor/bin/cordon verify --generate-baseline).',
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
    $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-baseline-'.uniqid().'.json');
    $baseline = Baseline::load(fixture_path('baselines/v1-cycles.json'));

    $stillThere = violation('/app/app/Modules/Billing/Invoice.php', 10, 'App\\Modules\\Catalog\\Internal\\Price');

    $baseline->prune([$stillThere], '/app')->save($file);
    $saved = json_decode((string) file_get_contents($file), true);
    unlink($file);

    expect($saved['version'])->toBe(2)
        ->and(array_column($saved['violations'], 'rule'))->toBe(['internal_access']);
});

it('never suppresses the violations of a strict module', function () {
    $modules = new ModuleMap([
        Module::make('A', 'A', '/app/Modules/A')->withConfig(['strict' => true]),
        Module::make('B', 'B', '/app/Modules/B'),
        Module::make('C', 'C', '/app/Modules/C'),
    ]);
    $strict = violation('/app/Modules/A/X.php');
    $other = new Violation('internal_access', 'message', '/app/Modules/C/X.php', 1, 'C', 'B', 'B\\Internal');
    $cycleIntoStrict = new Violation('cycles', 'message', null, null, 'B', 'A', 'B -> A');
    $cycleElsewhere = new Violation('cycles', 'message', null, null, 'B', 'C', 'B -> C');
    $baseline = Baseline::fromViolations([$strict, $other, $cycleIntoStrict, $cycleElsewhere], '/app');

    [$remaining, $suppressed] = $baseline->filter([$strict, $other, $cycleIntoStrict, $cycleElsewhere], '/app', $modules);

    expect($remaining)->toBe([$strict, $cycleIntoStrict])
        ->and($suppressed)->toBe(2)
        ->and($baseline->filter([$strict], '/app')[0])->toBe([]);
});

/**
 * A baseline file with the given contents, loaded.
 */
function baseline_from(string $json): Baseline
{
    $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-baseline-'.bin2hex(random_bytes(4)).'.json');
    file_put_contents($file, $json);

    return Baseline::load($file);
}

it('fails clearly on a baseline it cannot read or write', function () {
    expect(fn () => Baseline::load('/nonexistent/cordon-baseline.json'))->toThrow(RuntimeException::class, 'Unable to read the Cordon baseline [/nonexistent/cordon-baseline.json].')
        ->and(fn () => Baseline::empty()->save('/nonexistent/dir/cordon-baseline.json'))->toThrow(RuntimeException::class, 'Unable to write the Cordon baseline [/nonexistent/dir/cordon-baseline.json].');
});

it('reads the version, whatever its type, and treats a missing or broken one as version 1', function (mixed $version, int $warnings) {
    $json = ['violations' => [['rule' => 'cycles', 'file' => null, 'target' => 'A -> B', 'count' => 1]]];

    if ($version !== null) {
        $json['version'] = $version;
    }

    expect(baseline_from((string) json_encode($json))->warnings())->toHaveCount($warnings);
})->with([
    'version 2' => [2, 0],
    'version "2"' => ['2', 0],
    'no version' => [null, 1],
    'a version that is not a number' => ['two', 1],
    'version 1' => [1, 1],
]);

it('skips entries without a rule and reads counts of at least one', function () {
    $baseline = baseline_from((string) json_encode(['version' => 2, 'violations' => [
        'not an entry',
        ['file' => 'app/X.php', 'target' => 'B\\Internal'],
        ['rule' => 'internal_access', 'file' => 'Modules/A/X.php', 'target' => 'B\\Internal', 'count' => 0],
        ['rule' => 'internal_access', 'file' => 'Modules/A/Y.php', 'target' => 'B\\Internal', 'count' => '3'],
        ['rule' => 'internal_access', 'file' => 'Modules/A/Z.php', 'target' => 'B\\Internal'],
    ]]));
    $at = fn (string $file) => array_fill(0, 3, violation('/app/Modules/A/'.$file));

    expect($baseline->filter([...$at('X.php'), ...$at('Y.php'), ...$at('Z.php')], '/app')[1])->toBe(1 + 3 + 1)
        // The entry without a rule left nothing behind that could go stale.
        ->and($baseline->staleDetails([...$at('X.php'), ...$at('Y.php'), ...$at('Z.php')], '/app'))->toBe([])
        ->and(array_column($baseline->staleDetails([], '/app'), 'file'))->toBe(['Modules/A/X.php', 'Modules/A/Y.php', 'Modules/A/Z.php']);
});

it('saves the entries sorted, as a list, with readable paths', function () {
    $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-baseline-'.bin2hex(random_bytes(4)).'.json');
    Baseline::fromViolations([violation('/app/Modules/A/Z.php'), violation('/app/Modules/A/X.php')], '/app')->save($file);
    $saved = (string) file_get_contents($file);

    expect($saved)->toEndWith("}\n")
        ->toContain('"file": "Modules/A/X.php"')
        ->and(array_column(json_decode($saved, true)['violations'], 'file'))->toBe(['Modules/A/X.php', 'Modules/A/Z.php'])
        ->and(json_decode($saved, true)['version'])->toBe(2)
        ->and(array_is_list(json_decode($saved, true)['violations']))->toBeTrue();
});

it('matches a loaded cycle entry, which has no file, and keeps its fields', function () {
    $cycle = new Violation('cycles', 'message', null, null, 'A', 'B', 'A -> B');
    $baseline = baseline_from((string) json_encode(['version' => 2, 'violations' => [['rule' => 'cycles', 'file' => null, 'target' => 'A -> B', 'count' => 1]]]));

    expect($baseline->filter([$cycle], '/app'))->toBe([[], 1])
        ->and($baseline->staleDetails([], '/app'))->toBe([['rule' => 'cycles', 'file' => null, 'target' => 'A -> B', 'expected' => 1, 'found' => 0]]);
});

it('keeps a cycle out of the baseline when its strict module is either end, and ignores unknown modules', function () {
    $modules = new ModuleMap([Module::make('A', 'A', '/app/Modules/A')->withConfig(['strict' => true]), Module::make('B', 'B', '/app/Modules/B')]);
    $fromStrict = new Violation('cycles', 'message', null, null, 'A', 'B', 'A -> B');
    $unknown = new Violation('cycles', 'message', null, null, 'Gone', 'B', 'Gone -> B');

    expect(Baseline::isStrict($fromStrict, $modules))->toBeTrue()
        ->and(Baseline::isStrict($unknown, $modules))->toBeFalse();
});

it('details each stale entry with the occurrences recorded and found', function () {
    $baseline = Baseline::fromViolations([violation('/app/Modules/A/X.php'), violation('/app/Modules/A/X.php', 20), violation('/app/Modules/A/Y.php')], '/app');

    expect($baseline->staleDetails([violation('/app/Modules/A/X.php')], '/app'))->toBe([
        ['rule' => 'internal_access', 'file' => 'Modules/A/X.php', 'target' => 'B\\Internal', 'expected' => 2, 'found' => 1],
        ['rule' => 'internal_access', 'file' => 'Modules/A/Y.php', 'target' => 'B\\Internal', 'expected' => 1, 'found' => 0],
    ])
        ->and($baseline->staleEntries([violation('/app/Modules/A/X.php')], '/app'))->toBe(2);
});

it('prunes an entry down to the occurrences found, and drops it at zero', function () {
    $baseline = Baseline::fromViolations([violation('/app/Modules/A/X.php'), violation('/app/Modules/A/X.php', 20), violation('/app/Modules/A/X.php', 30), violation('/app/Modules/A/Y.php')], '/app');
    $pruned = $baseline->prune([violation('/app/Modules/A/X.php'), violation('/app/Modules/A/X.php', 20)], '/app');

    expect($pruned->staleEntries([violation('/app/Modules/A/X.php'), violation('/app/Modules/A/X.php', 20)], '/app'))->toBe(0)
        ->and($pruned->filter([violation('/app/Modules/A/X.php'), violation('/app/Modules/A/X.php'), violation('/app/Modules/A/X.php'), violation('/app/Modules/A/Y.php')], '/app')[1])->toBe(2);
});
