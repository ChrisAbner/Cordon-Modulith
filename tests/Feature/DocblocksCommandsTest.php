<?php

use Cordon\Tests\Oracle\Temp;
use Illuminate\Support\Facades\Artisan;

/**
 * Point the commands at tests/Fixtures/docblocks-app: Billing uses Catalog only in docblocks and Orders uses Billing only in one.
 *
 * @param  array<string, mixed>  $modules
 */
function use_docblocks_app(bool $docblocks, array $modules = ['Billing' => ['depends_on' => ['Orders']]]): void
{
    config()->set('cordon.resolvers.namespace.path', fixture_path('docblocks-app/app/Modules'));
    config()->set('cordon.modules', $modules);
    config()->set('cordon.host.paths', []);
    config()->set('cordon.baseline', fixture_path('docblocks-app/no-baseline.json'));
    config()->set('cordon.listeners.cycles', true);
    config()->set('cordon.docblocks', $docblocks);
}

/**
 * "rule source -> target" of each violation in the JSON report, sorted.
 *
 * @return list<string>
 */
function docblock_report(): array
{
    Artisan::call('cordon:verify', ['--format' => 'json']);
    $found = array_map(fn (array $v) => $v['rule'].' '.$v['source_module'].' -> '.$v['target_module'], json_decode(Artisan::output(), true)['violations']);
    sort($found);

    return $found;
}

it('ignores docblocks unless the docblocks config key is on', function () {
    use_docblocks_app(false);

    expect(Artisan::call('cordon:verify', ['--format' => 'json']))->toBe(0)
        ->and(docblock_report())->toBe([]);
});

it('reports the dependencies that only exist in docblocks when docblocks is on', function () {
    use_docblocks_app(true);

    expect(Artisan::call('cordon:verify', ['--format' => 'json']))->toBe(1)
        ->and(docblock_report())->toBe([
            'cycles Billing -> Orders',
            'cycles Orders -> Billing',
            'internal_access Billing -> Catalog',
            'undeclared_dependency Billing -> Catalog',
        ]);
});

it('says in cordon:why that a reference comes from a docblock', function () {
    use_docblocks_app(true);

    expect(Artisan::call('cordon:why', ['from' => 'Billing', 'to' => 'Catalog', '--format' => 'json']))->toBe(0);
    $references = json_decode(Artisan::output(), true)['references'];

    expect(array_column($references, 'kind'))->toBe(['docblock', 'docblock', 'docblock'])
        ->and(array_column($references, 'class'))->toBe([
            'App\\Modules\\Catalog\\Models\\Stock',
            'App\\Modules\\Catalog\\Contracts\\PriceList',
            'App\\Modules\\Catalog\\Contracts\\Inventory',
        ]);
});

it('does not reuse a cache written with the other value of docblocks', function () {
    $cache = Temp::directory('cordon-cache-').'/cordon.json';
    config()->set('cordon.cache', $cache);

    use_docblocks_app(false);
    expect(docblock_report())->toBe([]);

    use_docblocks_app(true);
    $cold = docblock_report();
    $written = (string) file_get_contents($cache);

    expect($cold)->toHaveCount(4);

    // A warm cache gives the same report and is not rewritten.
    expect(docblock_report())->toBe($cold)
        ->and((string) file_get_contents($cache))->toBe($written);

    use_docblocks_app(false);
    expect(docblock_report())->toBe([]);
});

it('gives the same report cold, warm and without the cache when docblocks is on', function () {
    $cache = Temp::directory('cordon-cache-').'/cordon.json';
    use_docblocks_app(true);

    config()->set('cordon.cache', null);
    Artisan::call('cordon:verify', ['--format' => 'json']);
    $without = Artisan::output();

    config()->set('cordon.cache', $cache);
    Artisan::call('cordon:verify', ['--format' => 'json']);
    $cold = Artisan::output();
    Artisan::call('cordon:verify', ['--format' => 'json']);

    expect($cold)->toBe($without)
        ->and(Artisan::output())->toBe($without);
});

it('stops before analysing when docblocks is not true or false', function () {
    use_docblocks_app(true);
    config()->set('cordon.docblocks', 'yes');

    $this->artisan('cordon:verify')
        ->expectsOutputToContain('docblocks in config/cordon.php must be true or false.')
        ->assertExitCode(2);
});
