<?php

/**
 * @param  list<string>  $arguments
 * @return array<string, mixed>
 */
function json_run(array $arguments, string $project): array
{
    $run = run_cordon([...$arguments, '--format=json'], $project);

    return ['code' => $run['code'], 'err' => $run['err'], 'json' => json_decode($run['out'], true)];
}

it('verifies the packages of each module through the standalone CLI', function () {
    $run = json_run(['verify'], temp_project('packages-app'));
    $found = array_map(fn (array $v) => implode(' ', [$v['rule'], $v['severity'], $v['source_module'], $v['target'], $v['file'].':'.$v['line']]), $run['json']['violations']);

    sort($found);

    expect($run['code'])->toBe(1)
        ->and($found)->toBe([
            'external_dependency error Billing guzzlehttp/guzzle app/Modules/Billing/Services/Checkout.php:15',
            'external_dependency error Catalog stripe/stripe-php app/Modules/Catalog/Models/Product.php:10',
        ])
        ->and($run['json']['violations'][0]['docs_url'])->toEndWith('/guide/rules#external-dependency');
});

it('keeps a project that sets no packages exactly as it was', function () {
    $project = temp_project('packages-app');
    file_put_contents($project.'/config/cordon.php', "<?php\n\nreturn [];\n");
    unlink($project.'/vendor/composer/installed.json');

    $run = json_run(['verify'], $project);

    expect($run['code'])->toBe(0)->and($run['json']['violations'])->toBe([])->and($run['err'])->toBe('');
});

it('fails the configuration check when packages are listed and installed.json is missing', function () {
    $project = temp_project('packages-app');
    unlink($project.'/vendor/composer/installed.json');

    $run = run_cordon(['verify'], $project);

    expect($run['code'])->toBe(2)
        ->and($run['err'])->toContain('vendor/composer/installed.json does not exist')->toContain('Run composer install');
});

it('warns about a listed package that is not installed, without failing', function () {
    $project = temp_project('packages-app');
    file_put_contents($project.'/config/cordon.php', "<?php\n\nreturn ['modules' => ['Billing' => ['packages' => ['stripe/stripe-php', 'stripe/strip-php']], 'Catalog' => ['packages' => ['guzzlehttp/guzzle', 'stripe/stripe-php']]]];\n");

    $run = run_cordon(['verify'], $project);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('Module [Billing] lists package [stripe/strip-php], which is not installed. Did you mean [stripe/stripe-php]?');
});

it('lists the uses of a package by a module, and whether packages allows them', function () {
    $project = temp_project('packages-app');

    $allowed = json_run(['why', 'Billing', 'stripe/stripe-php'], $project);
    $refused = json_run(['why', 'Billing', 'guzzlehttp/guzzle'], $project);
    $open = json_run(['why', 'Reports', 'stripe/stripe-php'], $project);
    $shared = json_run(['why', 'Billing', 'laravel/framework'], $project);

    expect($allowed['code'])->toBe(0)
        ->and($allowed['json'])->toMatchArray(['from' => 'Billing', 'to' => 'stripe/stripe-php', 'allowed' => true, 'reported' => false, 'reason' => 'Billing lists stripe/stripe-php in packages.'])
        ->and(array_map(fn (array $r) => $r['file'].':'.$r['line'].' '.$r['class'].' '.$r['kind'], $allowed['json']['references']))->toBe([
            'app/Modules/Billing/Services/Checkout.php:15 Stripe\\StripeClient use',
            'app/Modules/Billing/Services/Refunds.php:11 Stripe\\StripeClient use',
            'app/Modules/Billing/Services/Refunds.php:12 Stripe\\StripeClient use',
        ])
        ->and($refused['json'])->toMatchArray(['allowed' => false, 'reported' => true, 'reason' => 'Billing does not list guzzlehttp/guzzle in packages: cordon:verify reports it as external_dependency.'])
        ->and($open['json'])->toMatchArray(['allowed' => null, 'reported' => false, 'reason' => 'Reports does not list packages, so it may use any package.'])
        ->and($shared['json'])->toMatchArray(['allowed' => true, 'reported' => false, 'reason' => 'laravel/framework is in packages.shared, so every module may use it.']);
});

it('prints the uses of a package as text and says when there are none', function () {
    $project = temp_project('packages-app');
    $text = run_cordon(['why', 'Billing', 'guzzlehttp/guzzle'], $project);
    $none = run_cordon(['why', 'Reports', 'illuminate/support'], $project);

    expect($text['code'])->toBe(0)
        ->and($text['out'])->toContain('Billing -> guzzlehttp/guzzle: 1 reference')
        ->toContain('app/Modules/Billing/Services/Checkout.php:15  GuzzleHttp\\Client (use)')
        ->toContain('Billing does not list guzzlehttp/guzzle in packages')
        ->and($none['out'])->toContain('Reports does not use illuminate/support.');
});

it('says why cordon:verify does not report a package a module does not list', function () {
    $baselined = temp_project('packages-app');
    expect(run_cordon(['verify', '--generate-baseline'], $baselined)['code'])->toBe(0);

    $off = temp_project('packages-app');
    $config = (string) file_get_contents($off.'/config/cordon.php');
    file_put_contents($off.'/config/cordon.php', str_replace("return [\n", "return [\n    'rules' => ['external_dependency' => 'off'],\n", $config));

    expect(json_run(['why', 'Billing', 'guzzlehttp/guzzle'], $baselined)['json'])
        ->toMatchArray(['allowed' => false, 'reported' => false, 'reason' => 'Billing does not list guzzlehttp/guzzle in packages, but cordon:verify does not report it: the baseline records it.'])
        ->and(json_run(['why', 'Billing', 'guzzlehttp/guzzle'], $off)['json'])
        ->toMatchArray(['allowed' => false, 'reported' => false, 'reason' => 'Billing does not list guzzlehttp/guzzle in packages, but cordon:verify does not report it: external_dependency is off.']);
});

it('fails when asked about a package and installed.json is missing, even with no packages listed', function () {
    $project = temp_project('packages-app');
    file_put_contents($project.'/config/cordon.php', "<?php\n\nreturn [];\n");
    unlink($project.'/vendor/composer/installed.json');

    $run = run_cordon(['why', 'Billing', 'stripe/stripe-php'], $project);

    expect($run['code'])->toBe(2)
        ->and($run['err'])->toContain('vendor/composer/installed.json does not exist');
});

it('says when a package is unknown or the module is', function () {
    $project = temp_project('packages-app');
    $package = run_cordon(['why', 'Billing', 'stripe/strip-php'], $project);
    $module = run_cordon(['why', 'Biling', 'stripe/stripe-php'], $project);

    expect($package['code'])->toBe(2)
        ->and($package['err'])->toContain('Unknown package [stripe/strip-php]. Did you mean [stripe/stripe-php]?')
        ->and($module['code'])->toBe(2)
        ->and($module['err'])->toContain('Unknown module [Biling]. Did you mean [Billing]?');
});

it('keeps cordon:why between two modules as it was', function () {
    $run = json_run(['why', 'Billing', 'Catalog'], temp_project('packages-app'));

    expect($run['code'])->toBe(0)
        ->and($run['json'])->toMatchArray(['from' => 'Billing', 'to' => 'Catalog'])
        ->and($run['json']['references'][0]['class'])->toBe('App\\Modules\\Catalog\\Contracts\\Priced');
});

it('explains a class of a package: which package and who uses it', function () {
    $run = json_run(['explain', 'Stripe\\StripeClient'], temp_project('packages-app'));

    expect($run['code'])->toBe(0)
        ->and($run['json']['class'])->toBe('Stripe\\StripeClient')
        ->and($run['json']['package'])->toBe('stripe/stripe-php')
        ->and(array_map(fn (array $use) => $use['module'].' '.$use['file'].':'.$use['line'].' '.json_encode($use['violation']), $run['json']['used_by']))->toBe([
            'Billing app/Modules/Billing/Services/Checkout.php:15 false',
            'Billing app/Modules/Billing/Services/Refunds.php:11 false',
            'Billing app/Modules/Billing/Services/Refunds.php:12 false',
            'Catalog app/Modules/Catalog/Models/Product.php:10 true',
            'Reports app/Modules/Reports/Report.php:10 false',
        ]);

    $text = run_cordon(['explain', 'Stripe\\StripeClient'], temp_project('packages-app'));

    expect($text['out'])->toContain('Package:  stripe/stripe-php')->toContain('x Catalog')->toContain('- Billing');
});

it('still says a class outside every module belongs to none, when it is no package class', function () {
    $run = run_cordon(['explain', 'DateTimeImmutable'], temp_project('packages-app'));

    expect($run['code'])->toBe(2)->and($run['err'])->toContain('[DateTimeImmutable] does not belong to any module.');
});
