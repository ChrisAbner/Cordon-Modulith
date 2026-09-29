<?php

use Cordon\Laravel\ResolverFactory;
use Cordon\Laravel\StandaloneConfig;
use Illuminate\Container\Container;

it('merges the project config over the package defaults', function () {
    $config = StandaloneConfig::load(fixture_path('namespace-app'));

    expect($config->get('cordon.modules'))->toBe(['Shared' => ['open' => true]])
        ->and($config->get('cordon.public_namespaces'))->toBe(['Contracts', 'Events', 'Data', 'Enums', 'Exceptions'])
        ->and($config->get('cordon.resolvers.namespace.path'))->toBe('app/Modules');
});

it('ignores config files that need the framework', function () {
    $base = sys_get_temp_dir().'/cordon-standalone-'.uniqid();
    mkdir($base.'/config', 0777, true);
    file_put_contents($base.'/config/modules.php', '<?php return ["paths" => ["modules" => not_a_laravel_helper("Modules")]];');
    file_put_contents($base.'/config/app-modules.php', '<?php return ["modules_directory" => "packages"];');

    $config = StandaloneConfig::load($base);

    array_map('unlink', glob($base.'/config/*.php'));
    rmdir($base.'/config');
    rmdir($base);

    expect($config->has('modules'))->toBeFalse()
        ->and($config->get('app-modules.modules_directory'))->toBe('packages')
        ->and($config->get('cordon.resolver'))->toBe('auto');
});

it('resolves path helpers in project config against the project root', function () {
    $base = fixture_path('nwidart-lowercase');
    $config = StandaloneConfig::load($base);

    expect($config->get('modules.paths.modules'))->toBe($base.DIRECTORY_SEPARATOR.'modules')
        ->and($config->get('modules.paths.assets'))->toBe($base.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'modules')
        ->and($config->warnings())->toBe([]);

    $modules = ResolverFactory::make($config, $base)->resolve();

    expect(ResolverFactory::driver($config, $base))->toBe('nwidart')
        ->and($modules->names())->toBe(['Admin', 'Billing']);
});

it('does not leak its temporary container into the host process', function () {
    $before = Container::getInstance();

    StandaloneConfig::load(fixture_path('nwidart-lowercase'));

    expect(Container::getInstance())->toBe($before);
});

it('warns when a project config file cannot be evaluated', function () {
    $config = StandaloneConfig::load(fixture_path('nwidart-unloadable'));

    expect($config->has('modules'))->toBeFalse()
        ->and($config->warnings())->toHaveCount(1)
        ->and($config->warnings()[0])->toContain('config/modules.php')
        ->and($config->warnings()[0])->toContain('CommandsList')
        ->and($config->warnings()[0])->toContain('config/cordon.php');
});

it('does not warn about config files that do not exist', function () {
    expect(StandaloneConfig::load(fixture_path('namespace-app'))->warnings())->toBe([]);
});
