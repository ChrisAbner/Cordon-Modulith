<?php

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\Result;
use Cordon\Analysis\SymbolTable;
use Cordon\Analysis\Violation;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\ExternalDependencyRule;
use Cordon\Rules\RuleSet;
use Cordon\Support\PackageMap;
use Cordon\Tests\Oracle\Temp;

/**
 * tests/Fixtures/packages-app (modules Billing, Catalog, Reports) analysed with the given packages settings.
 *
 * @param  array<string, mixed>  $modules  The per-module settings.
 * @param  array<array-key, mixed>  $packages  The top-level `packages` setting.
 * @param  array<array-key, mixed>  $rules
 */
function analyse_packages_app(array $modules = [], array $packages = [], array $rules = [], ?string $root = null, bool $docblocks = false): Result
{
    $root ??= fixture_path('packages-app');
    $map = (new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve()->configure($modules);
    $built = RuleSet::fromConfig($rules, null, [], [], $packages, $root);
    $analyzer = new Analyzer(new PhpParserExtractor(docblocks: $docblocks), new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));

    return $analyzer->analyze($map, $root);
}

/**
 * @return list<string> "file: message" of each external_dependency violation, relative to the fixture.
 */
function external_findings(Result $result, ?string $root = null): array
{
    $root ??= fixture_path('packages-app');
    $found = array_map(
        fn (Violation $violation) => substr((string) $violation->file, strlen($root) + 1).':'.$violation->line.' '.$violation->message,
        violations_for($result, 'external_dependency'),
    );

    sort($found);

    return $found;
}

/**
 * A project in a temporary directory with the given vendor/composer/installed.json and package files.
 *
 * @param  array<string, mixed>|list<array<string, mixed>>  $installed
 * @param  list<string>  $files  Files to create (empty) under vendor/, such as "stripe/stripe-php/lib/Foo.php".
 */
function project_with_packages(array $installed, array $files = [], ?string $composer = null): string
{
    $root = Temp::directory('cordon-packages-');
    mkdir($root.'/vendor/composer', 0777, true);
    file_put_contents($root.'/vendor/composer/installed.json', json_encode($installed));

    foreach ($files as $file) {
        $path = $root.'/vendor/'.$file;
        is_dir(dirname($path)) || mkdir(dirname($path), 0777, true);
        file_put_contents($path, '<?php');
    }

    if ($composer !== null) {
        file_put_contents($root.'/composer.json', $composer);
    }

    return $root;
}

/**
 * @param  array<string, mixed>  $autoload
 * @return array<string, mixed>
 */
function installed_package(string $name, array $autoload, string $type = 'library'): array
{
    return ['name' => $name, 'version' => '1.0.0', 'type' => $type, 'install-path' => '../'.$name, 'autoload' => $autoload];
}

it('reports nothing without packages', function () {
    expect(external_findings(analyse_packages_app()))->toBe([])
        ->and(external_findings(analyse_packages_app([], ['shared' => ['stripe/stripe-php']])))->toBe([]);
});

it('lets a module use the packages it lists and reports the others', function () {
    $result = analyse_packages_app(['Billing' => ['packages' => ['stripe/stripe-php']]]);

    expect(external_findings($result))->toBe([
        'app/Modules/Billing/Services/Checkout.php:15 Module [Billing] uses GuzzleHttp\\Client from [guzzlehttp/guzzle], which is not in its packages.',
        'app/Modules/Billing/Services/Checkout.php:17 Module [Billing] uses Illuminate\\Database\\Eloquent\\Model from [laravel/framework], which is not in its packages.',
        'app/Modules/Billing/Services/Checkout.php:19 Module [Billing] uses Illuminate\\Support\\Str from [illuminate/support], which is not in its packages.',
    ])
        ->and($result->violations[0]->rule)->toBe('external_dependency')
        ->and($result->violations[0]->sourceModule)->toBe('Billing')
        ->and($result->violations[0]->target)->toBe('guzzlehttp/guzzle')
        ->and($result->violations[0]->docsUrl())->toEndWith('/guide/rules#external-dependency');
});

it('is an error, like layers, and can be turned off or down', function () {
    $modules = ['Billing' => ['packages' => ['stripe/stripe-php']]];

    expect(violations_for(analyse_packages_app($modules), 'external_dependency'))->not->toBe([])
        ->and(violations_for(analyse_packages_app($modules, [], ['external_dependency' => 'off']), 'external_dependency'))->toBe([])
        ->and(violations_for(analyse_packages_app($modules, [], ['external_dependency' => 'warn']), 'external_dependency'))->toBe([])
        ->and(array_filter(analyse_packages_app($modules, [], ['external_dependency' => 'warn'])->warnings, fn (Violation $v) => $v->rule === 'external_dependency'))->not->toBe([]);
});

it('reports once per file and package, at the first use', function () {
    $result = analyse_packages_app(['Billing' => ['packages' => ['guzzlehttp/guzzle']]]);
    $refunds = array_values(array_filter(external_findings($result), fn (string $finding) => str_contains($finding, 'Refunds.php')));

    expect($refunds)->toBe(['app/Modules/Billing/Services/Refunds.php:11 Module [Billing] uses Stripe\\StripeClient from [stripe/stripe-php], which is not in its packages.']);
});

it('adds the shared packages, with wildcards, to every restricted module', function () {
    $modules = ['Billing' => ['packages' => ['stripe/stripe-php', 'guzzlehttp/guzzle']]];

    expect(external_findings(analyse_packages_app($modules, ['shared' => ['laravel/framework', 'illuminate/*']])))->toBe([])
        ->and(external_findings(analyse_packages_app($modules, ['shared' => ['laravel/framework']])))->toBe([
            'app/Modules/Billing/Services/Checkout.php:19 Module [Billing] uses Illuminate\\Support\\Str from [illuminate/support], which is not in its packages.',
        ])
        ->and(external_findings(analyse_packages_app($modules, ['shared' => ['*']])))->toBe([]);
});

it('treats an empty packages list as "none but the shared ones"', function () {
    expect(external_findings(analyse_packages_app(['Reports' => ['packages' => []]])))->toBe([
        'app/Modules/Reports/Report.php:10 Module [Reports] uses GuzzleHttp\\Client from [guzzlehttp/guzzle], which is not in its packages.',
        'app/Modules/Reports/Report.php:10 Module [Reports] uses Stripe\\StripeClient from [stripe/stripe-php], which is not in its packages.',
    ]);
});

it('leaves a module without packages unrestricted, whatever the others say', function () {
    $result = analyse_packages_app(['Billing' => ['packages' => []]]);

    expect(array_filter(external_findings($result), fn (string $finding) => str_contains($finding, 'Reports') || str_contains($finding, 'Catalog')))->toBe([]);
});

it('does not take PHP classes, project classes or classes of other modules for packages', function () {
    $messages = implode("\n", external_findings(analyse_packages_app(['Billing' => ['packages' => []]])));

    expect($messages)->not->toContain('DateTimeImmutable')
        ->not->toContain('PDO')
        ->not->toContain('App\\Support\\Money')
        ->not->toContain('Catalog\\Contracts\\Priced');
});

it('gives the package of the longest prefix, so illuminate/support is not laravel/framework', function () {
    $modules = ['Billing' => ['packages' => ['stripe/stripe-php', 'illuminate/support']]];

    // Illuminate\Support\Str lives in illuminate/support; Illuminate\Database\Eloquent\Model only in laravel/framework.
    expect(external_findings(analyse_packages_app($modules)))->toBe([
        'app/Modules/Billing/Services/Checkout.php:15 Module [Billing] uses GuzzleHttp\\Client from [guzzlehttp/guzzle], which is not in its packages.',
        'app/Modules/Billing/Services/Checkout.php:17 Module [Billing] uses Illuminate\\Database\\Eloquent\\Model from [laravel/framework], which is not in its packages.',
    ]);
});

it('does nothing when installed.json is missing: the configuration error says so', function () {
    $root = temp_project('packages-app');
    unlink($root.'/vendor/composer/installed.json');

    expect(external_findings(analyse_packages_app(['Billing' => ['packages' => []]], [], [], $root), $root))->toBe([]);
});

// Review Focus 1: a class of a package that is not installed is never attributed to another package.

it('gives no package to a class of a package that is not in installed.json', function () {
    $root = project_with_packages(
        [
            'packages' => [
                installed_package('acme/kit', ['psr-4' => ['Acme\\' => 'src/']]),
                installed_package('acme/kit-extras', ['psr-4' => ['Acme\\Extras\\' => 'src/']]),
            ],
        ],
        ['acme/kit/src/Client.php', 'acme/kit-extras/src/Extra.php'],
    );
    $map = PackageMap::forProject($root);

    expect($map->packageOf('Acme\\Client'))->toBe('acme/kit')
        ->and($map->packageOf('Acme\\Extras\\Extra'))->toBe('acme/kit-extras')
        // acme/kit declares Acme\, but has no such file: the class belongs to a package that is not installed.
        ->and($map->packageOf('Acme\\Dev\\Faker'))->toBeNull()
        ->and($map->packageOf('Acme\\Extras\\Missing'))->toBeNull();
});

it('gives no package to PHP classes, even when a package declares the root namespace', function () {
    $root = project_with_packages(
        [
            'packages' => [
                installed_package('old/lib', ['psr-0' => ['' => 'lib/']]),
                installed_package('old/root', ['psr-4' => ['' => 'src/']]),
            ],
        ],
        ['old/lib/lib/Legacy.php', 'old/root/src/Rooted.php'],
    );
    $map = PackageMap::forProject($root);

    expect($map->packageOf('DateTimeImmutable'))->toBeNull()
        ->and($map->packageOf('PDO'))->toBeNull()
        ->and($map->packageOf('Redis'))->toBeNull()
        ->and($map->packageOf('Legacy'))->toBe('old/lib')
        ->and($map->packageOf('Rooted'))->toBe('old/root');
});

it('gives no package to a project class under a namespace that a package also uses', function () {
    $root = project_with_packages(
        ['packages' => [installed_package('stripe/stripe-php', ['psr-4' => ['Stripe\\' => 'lib/']])]],
        ['stripe/stripe-php/lib/StripeClient.php'],
    );
    $map = PackageMap::forProject($root);

    // Stripe\Mock\Gateway could be a class of the project that happens to use the vendor's namespace.
    expect($map->packageOf('Stripe\\StripeClient'))->toBe('stripe/stripe-php')
        ->and($map->packageOf('Stripe\\Mock\\Gateway'))->toBeNull()
        ->and($map->packageOf('App\\Billing\\Gateway'))->toBeNull();
});

it('does not know a require-dev package that was not installed, and knows an installed one by its name', function () {
    $root = project_with_packages(
        ['packages' => [
            installed_package('composer/installers', ['psr-4' => ['Composer\\Installers\\' => 'src/Composer/Installers/']], 'composer-plugin'),
            installed_package('pestphp/pest', ['psr-4' => ['Pest\\' => 'src/']]),
        ], 'dev' => false, 'dev-package-names' => []],
        ['composer/installers/src/Composer/Installers/Plugin.php', 'pestphp/pest/src/Expectation.php'],
    );
    $map = PackageMap::forProject($root);

    expect($map->packageOf('Composer\\Installers\\Plugin'))->toBe('composer/installers')
        // Composer's own classes are in no installed package.
        ->and($map->packageOf('Composer\\Autoload\\ClassLoader'))->toBeNull()
        ->and($map->packageOf('Composer\\Plugin\\PluginInterface'))->toBeNull()
        ->and($map->packageOf('Pest\\Expectation'))->toBe('pestphp/pest')
        ->and($map->packageOf('PHPUnit\\Framework\\TestCase'))->toBeNull();
});

it('trusts the prefix when the package directory is not on disk, but never an empty one', function () {
    $root = project_with_packages(['packages' => [
        installed_package('acme/kit', ['psr-4' => ['Acme\\' => 'src/']]),
        installed_package('acme/kit-extras', ['psr-4' => ['Acme\\Extras\\' => 'src/']]),
        installed_package('old/root', ['psr-4' => ['' => 'src/']]),
    ]]);
    $map = PackageMap::forProject($root);

    expect($map->packageOf('Acme\\Client'))->toBe('acme/kit')
        ->and($map->packageOf('Acme\\Extras\\Extra'))->toBe('acme/kit-extras')
        ->and($map->packageOf('DateTimeImmutable'))->toBeNull();
});

it('reads psr-0, classmap and the version 1 format of installed.json', function () {
    $root = project_with_packages(
        [
            installed_package('twig/twig', ['psr-0' => ['Twig_' => 'lib/']]),
            installed_package('acme/mapped', ['classmap' => ['src/']]),
        ],
        ['twig/twig/lib/Twig/Environment.php'],
    );
    mkdir($root.'/vendor/acme/mapped/src/Deep', 0777, true);
    file_put_contents($root.'/vendor/acme/mapped/src/Deep/Thing.php', "<?php\nnamespace Mapped\\Space;\n\nfinal class Thing {}\ninterface Contract {}\nenum Mode {}\n");
    $map = PackageMap::forProject($root);

    expect($map->packageOf('Twig_Environment'))->toBe('twig/twig')
        ->and($map->packageOf('Twig_Missing'))->toBeNull()
        ->and($map->packageOf('Mapped\\Space\\Thing'))->toBe('acme/mapped')
        ->and($map->packageOf('Mapped\\Space\\Contract'))->toBe('acme/mapped')
        ->and($map->packageOf('Mapped\\Space\\Mode'))->toBe('acme/mapped')
        // A classmap names classes, not prefixes: a class it does not list is not the package's.
        ->and($map->packageOf('Mapped\\Space\\Other'))->toBeNull()
        ->and($map->packageOf('Mapped\\Space'))->toBeNull();
});

it('compares class names without regard to case, like PHP', function () {
    $root = project_with_packages(
        ['packages' => [installed_package('stripe/stripe-php', ['psr-4' => ['Stripe\\' => 'lib/']])]],
        ['stripe/stripe-php/lib/StripeClient.php'],
    );

    expect(PackageMap::forProject($root)->packageOf('stripe\\StripeClient'))->toBe('stripe/stripe-php');
});

it('reads the vendor directory from composer.json', function () {
    $root = Temp::directory('cordon-packages-');
    mkdir($root.'/libs/composer', 0777, true);
    file_put_contents($root.'/libs/composer/installed.json', json_encode(['packages' => [installed_package('a/b', ['psr-4' => ['A\\' => 'src/']])]]));
    file_put_contents($root.'/composer.json', json_encode(['config' => ['vendor-dir' => 'libs']]));

    expect(PackageMap::forProject($root)->problem())->toBeNull()
        ->and(PackageMap::forProject($root)->names())->toBe(['a/b']);
});

it('says why it cannot be used', function () {
    $root = Temp::directory('cordon-packages-');

    expect(PackageMap::forProject($root)->problem())->toContain('vendor/composer/installed.json')->toContain('composer install')
        ->and(PackageMap::forProject($root)->packageOf('Stripe\\StripeClient'))->toBeNull();

    mkdir($root.'/vendor/composer', 0777, true);
    file_put_contents($root.'/vendor/composer/installed.json', '{not json');

    expect(PackageMap::forProject($root)->problem())->toContain('not valid JSON');

    file_put_contents($root.'/vendor/composer/installed.json', '"text"');

    expect(PackageMap::forProject($root)->problem())->toContain('not a Composer installed.json');
});

it('matches names and patterns without regard to case, and through replace', function () {
    $root = project_with_packages(['packages' => [
        ['name' => 'laravel/framework', 'autoload' => [], 'replace' => ['illuminate/support' => 'self.version']],
    ]]);
    $map = PackageMap::forProject($root);

    expect($map->covers('laravel/framework', ['Laravel/Framework']))->toBeTrue()
        ->and($map->covers('laravel/framework', ['laravel/*']))->toBeTrue()
        ->and($map->covers('laravel/framework', ['illuminate/support']))->toBeTrue()
        ->and($map->covers('laravel/framework', ['illuminate/*']))->toBeTrue()
        ->and($map->covers('laravel/framework', ['laravel/framework-extra', 'symfony/*']))->toBeFalse()
        ->and($map->has('illuminate/support'))->toBeTrue()
        ->and($map->has('illuminate/nothing'))->toBeFalse();
});

// Every kind of reference, listeners and docblocks included.

function external_context(string $kind, string $target, ?array $packages = ['stripe/stripe-php'], ?string $parentPackages = null): AnalysisContext
{
    $billing = new Module('Billing', 'App\\Billing', '/p/Billing', packages: $packages);
    $nested = new Module('Billing.Invoices', 'App\\Billing\\Invoices', '/p/Billing/Invoices', parent: 'Billing');

    return new AnalysisContext(
        new ModuleMap([$billing, $nested]),
        SymbolTable::fromAnalyses([]),
        new PublicApiPolicy,
        [],
        externalReferences: [new Reference('/p/Billing/Invoices/Listener.php', null, $target, 9, $kind)],
    );
}

function external_rule(): ExternalDependencyRule
{
    $root = fixture_path('packages-app');

    return new ExternalDependencyRule(PackageMap::forProject($root), []);
}

it('checks references of every kind the same way', function (string $kind) {
    $violations = iterator_to_array(external_rule()->check(external_context($kind, 'GuzzleHttp\\Client')), false);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('Module [Billing.Invoices] uses GuzzleHttp\\Client from [guzzlehttp/guzzle], which is not in its packages.');
})->with([Reference::USE, Reference::LISTENER, Reference::DOCBLOCK]);

it('lets a nested module use the packages of the module it is in, unless it lists its own', function () {
    $rule = external_rule();
    $inherited = iterator_to_array($rule->check(external_context(Reference::USE, 'Stripe\\StripeClient')), false);

    expect($inherited)->toBe([]);

    $own = new Module('Billing.Invoices', 'App\\Billing\\Invoices', '/p/Billing/Invoices', parent: 'Billing', packages: ['guzzlehttp/guzzle']);
    $context = new AnalysisContext(
        new ModuleMap([new Module('Billing', 'App\\Billing', '/p/Billing', packages: ['stripe/stripe-php']), $own]),
        SymbolTable::fromAnalyses([]),
        new PublicApiPolicy,
        [],
        externalReferences: [new Reference('/p/Billing/Invoices/A.php', null, 'Stripe\\StripeClient', 3), new Reference('/p/Billing/Invoices/A.php', null, 'GuzzleHttp\\Client', 4)],
    );

    $violations = iterator_to_array($rule->check($context), false);

    expect($violations)->toHaveCount(1)->and($violations[0]->target)->toBe('stripe/stripe-php');
});

it('reads docblock references only when docblocks are on', function () {
    $root = temp_project('packages-app');
    file_put_contents($root.'/app/Modules/Billing/Services/Documented.php', <<<'PHP'
        <?php

        namespace App\Modules\Billing\Services;

        final class Documented
        {
            /** @param \GuzzleHttp\Client $http */
            public function send($http): void {}
        }
        PHP);
    $only = fn (Result $result) => array_values(array_filter(external_findings($result, $root), fn (string $finding) => str_contains($finding, 'Documented.php')));
    $modules = ['Billing' => ['packages' => ['stripe/stripe-php']]];

    expect($only(analyse_packages_app($modules, [], [], $root)))->toBe([])
        ->and($only(analyse_packages_app($modules, [], [], $root, docblocks: true)))->toBe([
            'app/Modules/Billing/Services/Documented.php:7 Module [Billing] uses GuzzleHttp\\Client from [guzzlehttp/guzzle], which is not in its packages.',
        ]);
});

it('honours @cordon-ignore for external_dependency', function () {
    $root = temp_project('packages-app');
    $file = $root.'/app/Modules/Reports/Report.php';
    file_put_contents($file, str_replace('    public function build', "    // @cordon-ignore external_dependency (the legacy report still calls the API)\n    public function build", (string) file_get_contents($file)));
    $modules = ['Reports' => ['packages' => ['laravel/framework']]];

    expect(external_findings(analyse_packages_app($modules, [], [], $root), $root))->toBe([])
        ->and(analyse_packages_app($modules, [], [], $root)->ignored)->toBe(2);
});

it('leaves the code outside the modules unchecked', function () {
    $root = temp_project('packages-app');
    mkdir($root.'/app/Http');
    file_put_contents($root.'/app/Http/Controller.php', "<?php\n\nnamespace App\\Http;\n\nuse GuzzleHttp\\Client;\n\nfinal class Controller\n{\n    public function __construct(private Client \$http) {}\n}\n");
    $map = (new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve()->configure(['Billing' => ['packages' => ['stripe/stripe-php']]]);
    $rules = RuleSet::fromConfig([], null, [], [], [], $root);
    $result = (new Analyzer(new PhpParserExtractor, new FileCollector, new PublicApiPolicy, $rules, RuleSet::severities([], $rules)))->analyze($map, $root, [$root.'/app']);

    expect(external_findings($result, $root))->not->toContain('app/Http/Controller.php:5 Module [host] uses GuzzleHttp\\Client from [guzzlehttp/guzzle], which is not in its packages.')
        ->and(array_filter(external_findings($result, $root), fn (string $finding) => str_contains($finding, 'Http/Controller')))->toBe([]);
});

it('runs after the built-in rules and is not a warning-by-default rule', function () {
    $ids = array_map(fn ($rule) => $rule->id(), RuleSet::fromConfig());

    expect(array_slice($ids, -2))->toBe(['public_api_leak', 'external_dependency']);

    [, $severities] = RuleSet::build();

    expect($severities['external_dependency']->value)->toBe('error');
});
