<?php

use Cordon\Analysis\FileAnalysis;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Contracts\DependencyExtractor;
use Cordon\Laravel\Architecture;
use Cordon\Tests\Oracle\Temp;
use Illuminate\Support\Facades\Artisan;

it('returns the Mermaid flowchart that cordon:graph prints', function () {
    Artisan::call('cordon:graph');

    expect(Architecture::graph())->toBe(Artisan::output())
        ->toStartWith('flowchart LR')
        ->toContain('linkStyle 0 stroke:#d33');
});

it('limits the graph to a module and its neighbours', function () {
    Artisan::call('cordon:graph', ['--module' => 'Shared']);

    expect(Architecture::graph('Shared'))->toBe(Artisan::output());
});

it('returns the files that cordon:docs writes', function () {
    $output = Temp::removeAtExit(sys_get_temp_dir().'/cordon-architecture-'.uniqid());
    Artisan::call('cordon:docs', ['--output' => $output]);

    $files = Architecture::docs();

    expect(array_keys($files))->toBe(['README.md', 'modules/Billing.md', 'modules/Catalog.md', 'modules/Shared.md', 'events.md']);

    foreach ($files as $name => $contents) {
        expect($contents)->toBe(file_get_contents($output.'/'.$name));
    }

    foreach ([...glob($output.'/modules/*.md'), ...glob($output.'/*.md')] as $file) {
        unlink($file);
    }

    rmdir($output.'/modules');
    rmdir($output);
});

it('documents the violations of a module without applying the baseline', function () {
    expect(Architecture::docs()['modules/Billing.md'])->toContain('**internal_access**');
});

it('rejects a module that does not exist', function () {
    expect(fn () => Architecture::graph('Nowhere'))->toThrow(InvalidArgumentException::class, 'Unknown module [Nowhere]');
});

it('documents the modules as cordon:docs does when code outside the modules uses a module internal', function () {
    $host = Temp::removeAtExit(Temp::directory('cordon-architecture-host-'));
    file_put_contents($host.'/Controller.php', "<?php\n\nnamespace Host;\n\nuse App\\Modules\\Catalog\\Models\\Product;\n\nfinal class Controller\n{\n    public function __construct(public Product \$product) {}\n}\n");
    config()->set('cordon.host.paths', [$host]);
    config()->set('cordon.rules.host_access', 'error');

    $output = Temp::removeAtExit(sys_get_temp_dir().'/cordon-architecture-'.uniqid());
    Artisan::call('cordon:docs', ['--output' => $output]);

    $files = Architecture::docs();

    // The host code is not part of the documentation: cordon:docs never analysed it.
    expect($files['README.md'])->not->toContain('host_access')
        ->and($files['modules/Catalog.md'])->not->toContain('host_access');

    foreach ($files as $name => $contents) {
        expect($contents)->toBe(file_get_contents($output.'/'.$name));
    }

    foreach ([...glob($output.'/modules/*.md'), ...glob($output.'/*.md')] as $file) {
        unlink($file);
    }

    rmdir($output.'/modules');
    rmdir($output);
});

/**
 * Binds an extractor that records the files it is asked for, and returns its calls.
 *
 * @return object{calls: list<string>}
 */
function spy_on_extractions(): object
{
    $spy = new class implements DependencyExtractor
    {
        /** @var list<string> */
        public array $calls = [];

        public function extract(string $file): FileAnalysis
        {
            $this->calls[] = $file;

            return (new PhpParserExtractor)->extract($file);
        }
    };

    app()->bind(DependencyExtractor::class, fn () => $spy);

    return $spy;
}

it('does not extract the unchanged files again when cache is set', function () {
    config()->set('cordon.cache', Temp::directory('cordon-cache-').'/cordon.json');
    $spy = spy_on_extractions();

    $first = Architecture::docs();
    $extracted = count($spy->calls);
    $second = Architecture::docs();

    expect($extracted)->toBeGreaterThan(0)
        ->and(count($spy->calls))->toBe($extracted)
        ->and($second)->toBe($first);
});

it('documents the same with and without cache', function () {
    $without = Architecture::docs();

    config()->set('cordon.cache', Temp::directory('cordon-cache-').'/cordon.json');
    $cold = Architecture::docs();
    $warm = Architecture::docs();

    expect($cold)->toBe($without)
        ->and($warm)->toBe($without);
});

it('shares the cache with cordon:verify, host files included', function () {
    $host = Temp::removeAtExit(Temp::directory('cordon-architecture-host-'));
    file_put_contents($host.'/Controller.php', "<?php\n\nnamespace Host;\n\nfinal class Controller {}\n");
    config()->set('cordon.host.paths', [$host]);
    config()->set('cordon.cache', Temp::directory('cordon-cache-').'/cordon.json');
    $spy = spy_on_extractions();

    Artisan::call('cordon:verify');
    $extracted = count($spy->calls);
    Architecture::docs();
    Artisan::call('cordon:verify');

    // The docs run neither parsed again what verify cached nor dropped the host file from the cache.
    expect($extracted)->toBeGreaterThan(0)
        ->and(count($spy->calls))->toBe($extracted);
});

it('caches the file analyses of cordon:docs', function () {
    config()->set('cordon.cache', Temp::directory('cordon-cache-').'/cordon.json');
    $spy = spy_on_extractions();
    $output = Temp::removeAtExit(sys_get_temp_dir().'/cordon-architecture-'.uniqid());

    Artisan::call('cordon:docs', ['--output' => $output]);
    $extracted = count($spy->calls);
    Artisan::call('cordon:docs', ['--output' => $output]);

    expect($extracted)->toBeGreaterThan(0)
        ->and(count($spy->calls))->toBe($extracted);

    foreach ([...glob($output.'/modules/*.md'), ...glob($output.'/*.md')] as $file) {
        unlink($file);
    }

    rmdir($output.'/modules');
    rmdir($output);
});
