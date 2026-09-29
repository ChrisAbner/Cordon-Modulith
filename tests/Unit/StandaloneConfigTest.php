<?php

use Cordon\Laravel\StandaloneConfig;

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
