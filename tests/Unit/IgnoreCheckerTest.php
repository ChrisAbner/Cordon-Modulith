<?php

use Cordon\Analysis\FileAnalysis;
use Cordon\Analysis\IgnoreChecker;
use Cordon\Analysis\IgnoreDirective;
use Cordon\Analysis\Violation;

mutates(IgnoreChecker::class);

/**
 * @param  list<string>  $rules
 * @param  list<int>|null  $lines  The lines it covers; its own line and the next one by default.
 */
function checker_directive(int $line, array $rules, ?string $reason = 'because', ?array $lines = null): IgnoreDirective
{
    return new IgnoreDirective($line, $rules, $reason, $lines ?? [$line, $line + 1]);
}

/**
 * @param  list<IgnoreDirective>  $directives
 */
function checker_file(string $file, array $directives): FileAnalysis
{
    return new FileAnalysis($file, ignores: $directives);
}

function checker_violation(string $rule, ?string $file, ?int $line): Violation
{
    return new Violation($rule, 'a message', $file, $line, 'Billing', 'Catalog', 'Catalog\\Internal');
}

const CHECKER_RULES = ['internal_access', 'undeclared_dependency', 'host_access'];

it('takes out the violations a directive covers and keeps the others', function () {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(5, ['internal_access'])])], CHECKER_RULES, '/app');
    $covered = checker_violation('internal_access', '/app/A.php', 6);
    $ownLine = checker_violation('internal_access', '/app/A.php', 5);
    $otherLine = checker_violation('internal_access', '/app/A.php', 7);
    $otherRule = checker_violation('undeclared_dependency', '/app/A.php', 6);
    $otherFile = checker_violation('internal_access', '/app/B.php', 6);
    $noFile = checker_violation('internal_access', null, null);

    $kept = $checker->filter([$covered, $otherLine, $ownLine, $otherRule, $otherFile, $noFile]);

    expect($kept)->toBe([$otherLine, $otherRule, $otherFile, $noFile])
        ->and($checker->ignored())->toBe([$covered, $ownLine]);
});

it('finds the directive among several in the file, and across calls', function () {
    $checker = new IgnoreChecker([
        checker_file('/app/A.php', [checker_directive(2, ['internal_access']), checker_directive(10, ['undeclared_dependency'])]),
        checker_file('/app/B.php', [checker_directive(4, ['internal_access'])]),
    ], CHECKER_RULES, '/app');
    $second = checker_violation('undeclared_dependency', '/app/A.php', 11);
    $other = checker_violation('internal_access', '/app/B.php', 5);

    expect($checker->filter([$second]))->toBe([])
        ->and($checker->filter([$other]))->toBe([])
        ->and($checker->ignored())->toBe([$second, $other]);
});

it('does not use a directive for a line it does not cover', function () {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(5, ['internal_access'], 'because', [5, 8])])], CHECKER_RULES, '/app');
    $between = checker_violation('internal_access', '/app/A.php', 6);

    expect($checker->filter([$between]))->toBe([$between]);
});

it('reports a directive that matched nothing, once per rule, in file and line order', function () {
    $checker = new IgnoreChecker([
        checker_file('/app/B.php', [checker_directive(3, ['internal_access'])]),
        checker_file('/app/A.php', [checker_directive(20, ['internal_access']), checker_directive(9, ['internal_access', 'undeclared_dependency'])]),
    ], CHECKER_RULES, '/app');

    $checker->filter([checker_violation('internal_access', '/app/A.php', 10)]);

    expect($checker->unused())->toBe([
        ['file' => '/app/A.php', 'message' => 'Cordon ignore directive in A.php:9 (undeclared_dependency) no longer matches a violation on its line or the statement after it; remove it.'],
        ['file' => '/app/A.php', 'message' => 'Cordon ignore directive in A.php:20 (internal_access) no longer matches a violation on its line or the statement after it; remove it.'],
        ['file' => '/app/B.php', 'message' => 'Cordon ignore directive in B.php:3 (internal_access) no longer matches a violation on its line or the statement after it; remove it.'],
    ]);
});

it('does not mix up the rule and the line of a used directive with those of another', function () {
    $checker = new IgnoreChecker([
        checker_file('/app/A.php', [checker_directive(1, ['internal_access']), checker_directive(11, ['internal_access'])]),
        checker_file('/app/A1.php', [checker_directive(1, ['internal_access'])]),
    ], CHECKER_RULES, '/app');

    $checker->filter([checker_violation('internal_access', '/app/A.php', 2)]);

    expect(array_column($checker->unused(), 'message'))->toBe([
        'Cordon ignore directive in A.php:11 (internal_access) no longer matches a violation on its line or the statement after it; remove it.',
        'Cordon ignore directive in A1.php:1 (internal_access) no longer matches a violation on its line or the statement after it; remove it.',
    ]);
});

it('says every directive is unused before anything is filtered', function () {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(3, ['internal_access'])])], CHECKER_RULES, '/app');

    expect($checker->unused())->toHaveCount(1)
        ->and($checker->ignored())->toBe([]);
});

it('rejects a directive without a reason, with the rules it names', function () {
    $checker = new IgnoreChecker([checker_file('/app/Modules/A.php', [checker_directive(7, ['internal_access', 'host_access'], null)])], CHECKER_RULES, '/app');

    expect($checker->errors())->toBe([
        'Cordon ignore directive in Modules/A.php:7 needs a reason in parentheses: // @cordon-ignore internal_access, host_access (why this is accepted).',
    ])->and($checker->filter([checker_violation('internal_access', '/app/Modules/A.php', 8)]))->toHaveCount(1)
        ->and($checker->unused())->toBe([]);
});

it('rejects a directive that names no rule', function () {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(7, [])])], CHECKER_RULES, '/app');

    expect($checker->errors())->toBe(['Cordon ignore directive in A.php:7 names no rule: // @cordon-ignore <rule> (<reason>).']);
});

it('rejects the rules that report no line to ignore', function (string $rule) {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(7, [$rule])])], [...CHECKER_RULES, $rule], '/app');

    expect($checker->errors())->toBe(["Cordon ignore directive in A.php:7 names [{$rule}], which reports no line to ignore: record it in the baseline instead."])
        ->and($checker->unused())->toBe([]);
})->with(['cycles', 'unused_dependency']);

it('rejects an unknown rule and suggests the closest one', function () {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(7, ['internal_acess', 'zzz'])])], CHECKER_RULES, '/app');

    expect($checker->errors())->toBe([
        'Cordon ignore directive in A.php:7 names unknown rule [internal_acess]. Did you mean [internal_access]?',
        'Cordon ignore directive in A.php:7 names unknown rule [zzz].',
    ]);
});

it('accepts a rule the config knows even when no built-in rule has that name', function () {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(7, ['my_rule'])])], ['my_rule'], '/app');

    expect($checker->errors())->toBe([]);
});

it('keeps the valid rules of a directive out of use when it is rejected for another reason', function () {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(7, ['internal_access', 'zzz'])])], CHECKER_RULES, '/app');

    expect($checker->filter([checker_violation('internal_access', '/app/A.php', 8)]))->toHaveCount(1)
        ->and($checker->unused())->toBe([])
        ->and($checker->errors())->toHaveCount(1);
});

it('lists the errors in file and line order, whatever order the files came in', function () {
    $checker = new IgnoreChecker([
        checker_file('/app/B.php', [checker_directive(2, [], 'because')]),
        checker_file('/app/A.php', [checker_directive(30, [], 'because'), checker_directive(4, ['internal_access'], null)]),
    ], CHECKER_RULES, '/app');

    expect($checker->errors())->toBe([
        'Cordon ignore directive in A.php:4 needs a reason in parentheses: // @cordon-ignore internal_access (why this is accepted).',
        'Cordon ignore directive in A.php:30 names no rule: // @cordon-ignore <rule> (<reason>).',
        'Cordon ignore directive in B.php:2 names no rule: // @cordon-ignore <rule> (<reason>).',
    ]);
});

it('reports a directive with several problems once for each', function () {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(7, [], null)])], CHECKER_RULES, '/app');

    expect($checker->errors())->toBe([
        'Cordon ignore directive in A.php:7 needs a reason in parentheses: // @cordon-ignore  (why this is accepted).',
        'Cordon ignore directive in A.php:7 names no rule: // @cordon-ignore <rule> (<reason>).',
    ]);
});

it('shows the full path when there is no base path', function () {
    $checker = new IgnoreChecker([checker_file('/app/A.php', [checker_directive(7, [])])], CHECKER_RULES, null);

    expect($checker->errors())->toBe(['Cordon ignore directive in /app/A.php:7 names no rule: // @cordon-ignore <rule> (<reason>).']);
});

it('sorts the errors and the unused directives by line within each file', function () {
    $descending = [checker_directive(40, [], 'because'), checker_directive(30, [], 'because'), checker_directive(20, [], 'because'), checker_directive(10, [], 'because'), checker_directive(5, [], 'because')];
    $ascending = [checker_directive(5, [], 'because'), checker_directive(10, [], 'because'), checker_directive(20, [], 'because'), checker_directive(30, [], 'because'), checker_directive(40, [], 'because')];
    $unusedDescending = [checker_directive(40, ['internal_access']), checker_directive(30, ['internal_access']), checker_directive(20, ['internal_access']), checker_directive(10, ['internal_access']), checker_directive(5, ['internal_access'])];
    $unusedAscending = [checker_directive(5, ['host_access']), checker_directive(10, ['host_access']), checker_directive(20, ['host_access']), checker_directive(30, ['host_access']), checker_directive(40, ['host_access'])];

    $errors = new IgnoreChecker([checker_file('/app/B.php', $descending), checker_file('/app/A.php', $ascending), checker_file('/app/C.php', $descending)], CHECKER_RULES, '/app');
    $unused = new IgnoreChecker([checker_file('/app/B.php', $unusedDescending), checker_file('/app/A.php', $unusedAscending)], CHECKER_RULES, '/app');

    $expectedErrors = [];

    foreach (['A', 'B', 'C'] as $file) {
        foreach ([5, 10, 20, 30, 40] as $line) {
            $expectedErrors[] = "Cordon ignore directive in {$file}.php:{$line} names no rule: // @cordon-ignore <rule> (<reason>).";
        }
    }

    $expectedUnused = [];

    foreach ([['A', 'host_access'], ['B', 'internal_access']] as [$file, $rule]) {
        foreach ([5, 10, 20, 30, 40] as $line) {
            $expectedUnused[] = "Cordon ignore directive in {$file}.php:{$line} ({$rule}) no longer matches a violation on its line or the statement after it; remove it.";
        }
    }

    expect($errors->errors())->toBe($expectedErrors)
        ->and(array_column($unused->unused(), 'message'))->toBe($expectedUnused);
});
