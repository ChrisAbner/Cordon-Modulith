<?php

use Cordon\Analysis\ModuleFilter;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Tests\Oracle\Temp;

mutates(ModuleFilter::class);

// A notice in the filter, such as reading a property of a module that is not there, fails the test.
beforeEach(function () {
    set_error_handler(static function (int $level, string $message, string $path, int $line): never {
        throw new ErrorException($message, 0, $level, $path, $line);
    });
});

afterEach(fn () => restore_error_handler());

function filter_violation(string $rule, ?string $source, ?string $file = null, ?string $target = null): Violation
{
    return new Violation($rule, $rule.' in '.($source ?? 'none'), $file, $file === null ? null : 3, $source, null, $target);
}

/**
 * A result over the modules Billing, Catalog and Shared (at /app/Modules/<name>).
 *
 * @param  array<string, mixed>  $fields  Named arguments of Result
 */
function filter_result(array $violations, array $fields = []): Result
{
    $modules = new ModuleMap(array_map(
        static fn (string $name): Module => Module::make($name, 'App\\Modules\\'.$name, '/app/Modules/'.$name),
        ['Billing', 'Catalog', 'Shared'],
    ));

    return new Result($modules, $violations, ...[...['filesAnalysed' => 10, 'crossModuleReferences' => 4], ...$fields]);
}

/**
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function filter_messages(array $violations): array
{
    return array_map(fn (Violation $violation) => $violation->message, $violations);
}

it('keeps the violations a module causes, and the cycles it takes part in', function () {
    $own = filter_violation('internal_access', 'Billing');
    $other = filter_violation('internal_access', 'Catalog');
    $cycle = filter_violation('cycles', null, null, 'Catalog -> Billing -> Catalog');
    $result = filter_result([$other, $own, $cycle], ['warnings' => [$other, $cycle, $own]]);

    $filtered = ModuleFilter::apply($result, ['Billing']);

    expect($filtered->violations)->toBe([$own, $cycle])
        ->and($filtered->warnings)->toBe([$cycle, $own]);
});

it('matches a module in a cycle by its whole name', function () {
    $cycle = filter_violation('cycles', 'Billing', null, 'Catalogue -> Shared -> Catalogue');

    expect(ModuleFilter::involves($cycle, 'Catalog'))->toBeFalse()
        ->and(ModuleFilter::involves($cycle, 'Catalogue'))->toBeTrue()
        ->and(ModuleFilter::involves($cycle, 'Shared'))->toBeTrue()
        ->and(ModuleFilter::involves($cycle, 'Billing'))->toBeFalse();
});

it('falls back to the source module for a cycle without a path and for any other rule', function () {
    expect(ModuleFilter::involves(filter_violation('cycles', 'Billing'), 'Billing'))->toBeTrue()
        ->and(ModuleFilter::involves(filter_violation('cycles', 'Billing'), 'Catalog'))->toBeFalse()
        ->and(ModuleFilter::involves(filter_violation('internal_access', 'Billing', null, 'Catalog -> Billing'), 'Catalog'))->toBeFalse()
        ->and(ModuleFilter::involves(filter_violation('internal_access', 'Billing', null, 'Catalog -> Billing'), 'Billing'))->toBeTrue();
});

it('involves any of several modules, and none of an empty list', function () {
    $violation = filter_violation('internal_access', 'Catalog');

    expect(ModuleFilter::involvesAny($violation, ['Billing', 'Catalog']))->toBeTrue()
        ->and(ModuleFilter::involvesAny($violation, ['Billing', 'Shared']))->toBeFalse()
        ->and(ModuleFilter::involvesAny($violation, []))->toBeFalse();
});

it('keeps everything else of the result as it was', function () {
    $result = filter_result([], [
        'parseErrors' => ['/app/Broken.php' => 'syntax error'],
        'suppressed' => 2,
        'basePath' => '/app',
        'ignoreErrors' => ['needs a reason'],
    ]);

    $filtered = ModuleFilter::apply($result, ['Billing']);

    expect($filtered->modules)->toBe($result->modules)
        ->and($filtered->filesAnalysed)->toBe(10)
        ->and($filtered->crossModuleReferences)->toBe(4)
        ->and($filtered->parseErrors)->toBe(['/app/Broken.php' => 'syntax error'])
        ->and($filtered->suppressed)->toBe(2)
        ->and($filtered->basePath)->toBe('/app')
        ->and($filtered->ignoreErrors)->toBe(['needs a reason']);
});

it('records the modules that were checked, narrowing an earlier filter', function () {
    expect(ModuleFilter::apply(filter_result([]), ['Shared', 'Billing'])->checkedModules)->toBe(['Shared', 'Billing'])
        ->and(ModuleFilter::apply(filter_result([], ['checkedModules' => ['Catalog', 'Billing']]), ['Billing', 'Shared'])->checkedModules)->toBe(['Billing'])
        ->and(ModuleFilter::apply(filter_result([], ['checkedModules' => ['Catalog']]), ['Billing'])->checkedModules)->toBe([]);
});

it('narrows what was ignored to the violations and the files of the modules kept', function () {
    $billing = filter_violation('internal_access', 'Billing');
    $catalog = filter_violation('internal_access', 'Catalog');
    $result = filter_result([], [
        'ignored' => 2,
        'ignoredViolations' => [$catalog, $billing],
        'ignoreWarnings' => ['Billing warning', 'Catalog warning', 'Outside warning', 'No file warning'],
        'ignoreWarningFiles' => ['/app/Modules/Billing/A.php', '/app/Modules/Catalog/B.php', '/app/Other/C.php'],
        'ignoreErrors' => ['an error'],
    ]);

    $filtered = ModuleFilter::apply($result, ['Billing']);

    expect($filtered->ignored)->toBe(1)
        ->and($filtered->ignoredViolations)->toBe([$billing])
        ->and($filtered->ignoreWarnings)->toBe(['Billing warning'])
        ->and($filtered->ignoreWarningFiles)->toBe(['/app/Modules/Billing/A.php'])
        ->and($filtered->ignoreErrors)->toBe(['an error']);
});

it('keeps the order and the alignment of the ignore warnings and their files', function () {
    $result = filter_result([], [
        'ignoreWarnings' => ['first', 'skipped', 'third'],
        'ignoreWarningFiles' => ['/app/Modules/Billing/A.php', '/app/Modules/Catalog/B.php', '/app/Modules/Billing/C.php'],
    ]);

    $filtered = ModuleFilter::apply($result, ['Billing']);

    expect($filtered->ignoreWarnings)->toBe(['first', 'third'])
        ->and($filtered->ignoreWarningFiles)->toBe(['/app/Modules/Billing/A.php', '/app/Modules/Billing/C.php']);
});

it('keeps the violations under the given paths, and none beside them', function () {
    $inside = filter_violation('internal_access', 'Billing', '/app/Modules/Billing/Models/Invoice.php');
    $exact = filter_violation('internal_access', 'Billing', '/app/Modules/Billing/Models');
    $sibling = filter_violation('internal_access', 'Billing', '/app/Modules/Billing/ModelsOld/Invoice.php');
    $elsewhere = filter_violation('internal_access', 'Catalog', '/app/Modules/Catalog/Models/Product.php');
    $result = filter_result([$elsewhere, $inside, $sibling, $exact], ['warnings' => [$sibling, $inside]]);

    $filtered = ModuleFilter::applyPaths($result, ['/app/Modules/Billing/Models']);

    expect($filtered->violations)->toBe([$inside, $exact])
        ->and($filtered->warnings)->toBe([$inside]);
});

it('keeps the violations under any of several paths', function () {
    $billing = filter_violation('internal_access', 'Billing', '/app/Modules/Billing/A.php');
    $catalog = filter_violation('internal_access', 'Catalog', '/app/Modules/Catalog/B.php');
    $shared = filter_violation('internal_access', 'Shared', '/app/Modules/Shared/C.php');

    $filtered = ModuleFilter::applyPaths(filter_result([$billing, $catalog, $shared]), ['/app/Modules/Billing', '/app/Modules/Shared']);

    expect($filtered->violations)->toBe([$billing, $shared]);
});

it('keeps a violation without a file when it involves a module the paths touch', function () {
    $cycle = filter_violation('cycles', null, null, 'Catalog -> Billing -> Catalog');
    $unrelated = filter_violation('cycles', null, null, 'Catalog -> Shared -> Catalog');
    $result = filter_result([$unrelated, $cycle], ['warnings' => [$cycle, $unrelated]]);

    $filtered = ModuleFilter::applyPaths($result, ['/app/Modules/Billing/Models/Invoice.php']);

    expect($filtered->violations)->toBe([$cycle])
        ->and($filtered->warnings)->toBe([$cycle]);
});

it('touches a module when a path is the module, inside it, or above it', function (string $path, array $touched) {
    $violations = array_map(
        fn (string $name) => filter_violation('cycles', null, null, $name.' -> Elsewhere'),
        ['Billing', 'Catalog', 'Shared'],
    );

    $kept = ModuleFilter::applyPaths(filter_result($violations), [$path]);

    expect(array_map(fn (Violation $violation) => explode(' ', $violation->target)[0], $kept->violations))->toBe($touched)
        ->and($kept->checkedModules)->toBe($touched);
})->with([
    'the module itself' => ['/app/Modules/Billing', ['Billing']],
    'the module with a trailing slash' => ['/app/Modules/Billing/', ['Billing']],
    'a file inside the module' => ['/app/Modules/Billing/Models/Invoice.php', ['Billing']],
    'the directory of all modules' => ['/app/Modules', ['Billing', 'Catalog', 'Shared']],
    'the whole project' => ['/app', ['Billing', 'Catalog', 'Shared']],
    'a sibling with the same prefix' => ['/app/Modules/BillingOld', []],
    'another project' => ['/other', []],
]);

it('resolves symbolic links before comparing paths', function () {
    $root = Temp::directory('cordon-filter-');
    mkdir($root.'/real/Billing', 0777, true);
    mkdir($root.'/real/Catalog', 0777, true);
    touch($root.'/real/Billing/Invoice.php');
    symlink($root.'/real', $root.'/link');
    $real = realpath($root.'/real');

    $modules = new ModuleMap([
        Module::make('Billing', 'App\\Billing', $root.'/link/Billing'),
        Module::make('Catalog', 'App\\Catalog', $root.'/link/Catalog'),
    ]);
    $inBilling = filter_violation('internal_access', 'Billing', $root.'/link/Billing/Invoice.php');
    $cycle = filter_violation('cycles', null, null, 'Catalog -> Billing -> Catalog');
    $other = filter_violation('cycles', null, null, 'Catalog -> Elsewhere -> Catalog');
    $result = new Result($modules, [$inBilling, $cycle, $other], 3, 1, ignoreWarnings: ['warning'], ignoreWarningFiles: [$root.'/link/Billing/Invoice.php']);

    $filtered = ModuleFilter::applyPaths($result, [$real.'/Billing']);

    expect($filtered->violations)->toBe([$inBilling, $cycle])
        ->and($filtered->checkedModules)->toBe(['Billing'])
        ->and($filtered->ignoreWarnings)->toBe(['warning']);
});

it('keeps the ignores of the violations and the files under the paths', function () {
    $inside = filter_violation('internal_access', 'Billing', '/app/Modules/Billing/A.php');
    $outside = filter_violation('internal_access', 'Catalog', '/app/Modules/Catalog/B.php');
    $result = filter_result([], [
        'ignored' => 2,
        'ignoredViolations' => [$outside, $inside],
        'ignoreWarnings' => ['inside', 'outside', 'no file'],
        'ignoreWarningFiles' => ['/app/Modules/Billing/A.php', '/app/Modules/Catalog/B.php'],
        'ignoreErrors' => ['an error'],
    ]);

    $filtered = ModuleFilter::applyPaths($result, ['/app/Modules/Billing']);

    expect($filtered->ignored)->toBe(1)
        ->and($filtered->ignoredViolations)->toBe([$inside])
        ->and($filtered->ignoreWarnings)->toBe(['inside'])
        ->and($filtered->ignoreWarningFiles)->toBe(['/app/Modules/Billing/A.php'])
        ->and($filtered->ignoreErrors)->toBe(['an error']);
});

it('keeps the rest of the result and narrows the checked modules when filtering by paths', function () {
    $result = filter_result([], [
        'parseErrors' => ['/app/Broken.php' => 'syntax error'],
        'suppressed' => 2,
        'basePath' => '/app',
        'checkedModules' => ['Catalog', 'Billing'],
    ]);

    $filtered = ModuleFilter::applyPaths($result, ['/app/Modules']);

    expect($filtered->filesAnalysed)->toBe(10)
        ->and($filtered->crossModuleReferences)->toBe(4)
        ->and($filtered->parseErrors)->toBe(['/app/Broken.php' => 'syntax error'])
        ->and($filtered->suppressed)->toBe(2)
        ->and($filtered->basePath)->toBe('/app')
        ->and($filtered->checkedModules)->toBe(['Catalog', 'Billing']);
});

it('accepts a path with a trailing slash', function () {
    $inside = filter_violation('internal_access', 'Billing', '/app/Modules/Billing/Models/Invoice.php');
    $elsewhere = filter_violation('internal_access', 'Catalog', '/app/Modules/Catalog/Models/Product.php');

    expect(ModuleFilter::applyPaths(filter_result([$inside, $elsewhere]), ['/app/Modules/Billing/'])->violations)->toBe([$inside]);
});

it('lists a module once however many of the paths touch it', function () {
    $filtered = ModuleFilter::applyPaths(filter_result([]), ['/app/Modules/Billing/A.php', '/app/Modules/Billing/B.php', '/app/Modules/Shared']);

    expect($filtered->checkedModules)->toBe(['Billing', 'Shared']);
});

it('narrows the modules an earlier filter checked to those the paths touch', function () {
    $filtered = ModuleFilter::applyPaths(filter_result([], ['checkedModules' => ['Catalog', 'Billing']]), ['/app/Modules/Billing']);

    expect($filtered->checkedModules)->toBe(['Billing']);
});
