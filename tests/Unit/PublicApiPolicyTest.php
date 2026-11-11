<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\ClassDeclaration;
use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Violation;
use Cordon\Module\Module;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;

mutates(PublicApiPolicy::class);

// A notice in the policy, such as reading the visibility of a declaration that is not there, fails the test.
beforeEach(function () {
    set_error_handler(static function (int $level, string $message, string $path, int $line): never {
        throw new ErrorException($message, 0, $level, $path, $line);
    });
});

afterEach(fn () => restore_error_handler());

function billing_module(array $publicApi = []): Module
{
    return new Module('Billing', 'Modules\Billing', '/tmp/Billing', null, $publicApi);
}

it('treats global public namespaces as public at any depth', function (string $fqcn, bool $public) {
    expect((new PublicApiPolicy)->isPublic($fqcn, billing_module()))->toBe($public);
})->with([
    'top-level Contracts' => ['Modules\Billing\Contracts\Charges', true],
    'root class named like the entry' => ['Modules\Billing\Enums', true],
    'nested Enums' => ['Modules\Billing\Invoices\Enums\Status', true],
    'nested Events deeper still' => ['Modules\Billing\Invoices\Events\Sub\InvoicePaid', true],
    'entry only as a name prefix' => ['Modules\Billing\EnumsHelper\Foo', false],
    'entry only as a name suffix' => ['Modules\Billing\Invoices\MyEnums\Status', false],
    'short class name is not a namespace' => ['Modules\Billing\Invoices\Enums', false],
    'plain nested class' => ['Modules\Billing\Invoices\Status', false],
    'plain top-level class' => ['Modules\Billing\Services\Charger', false],
]);

it('matches multi-segment entries as a contiguous run anywhere', function (string $fqcn, bool $public) {
    expect((new PublicApiPolicy(['Http\Resources']))->isPublic($fqcn, billing_module()))->toBe($public);
})->with([
    'at root' => ['Modules\Billing\Http\Resources\InvoiceResource', true],
    'nested' => ['Modules\Billing\Invoices\Http\Resources\InvoiceResource', true],
    'not contiguous' => ['Modules\Billing\Http\Foo\Resources\InvoiceResource', false],
    'wrong order' => ['Modules\Billing\Resources\Http\InvoiceResource', false],
]);

it('keeps the per-module public list anchored at the module root', function () {
    $policy = new PublicApiPolicy([]);
    $module = billing_module(['Services\BillingService', 'Api']);

    expect($policy->isPublic('Modules\Billing\Services\BillingService', $module))->toBeTrue()
        ->and($policy->isPublic('Modules\Billing\Api\V1\Thing', $module))->toBeTrue()
        ->and($policy->isPublic('Modules\Billing\Invoices\Api\Thing', $module))->toBeFalse()
        ->and($policy->isPublic('Modules\Billing\Invoices\Services\BillingService', $module))->toBeFalse();
});

it('matches a per-module public entry regardless of case and keeps it anchored at the module root', function () {
    $policy = new PublicApiPolicy([]);
    $module = billing_module(['services\billingservice']);

    expect($policy->isPublic('Modules\Billing\Services\BillingService', $module))->toBeTrue()
        ->and($policy->isPublic('Modules\Billing\Invoices\Services\BillingService', $module))->toBeFalse();
});

it('lets #[Internal] keep a nested public-namespace class internal', function () {
    $declaration = new ClassDeclaration('Modules\\Billing\\Invoices\\Enums\\Status', 'Status.php', 1, DeclaredVisibility::Internal);

    expect((new PublicApiPolicy)->isPublic('Modules\Billing\Invoices\Enums\Status', billing_module(), $declaration))->toBeFalse();
});

it('does not report nested public-namespace classes of another module', function () {
    $modules = (new NamespaceResolver(fixture_path('nested-public-namespaces/app/Modules'), 'App\Modules'))->resolve();
    $analyzer = new Analyzer(
        new PhpParserExtractor,
        new FileCollector(['vendor', 'node_modules', 'tests', 'Tests']),
        new PublicApiPolicy,
        RuleSet::fromConfig([]),
    );

    $targets = array_map(
        fn (Violation $v) => $v->target,
        violations_for($analyzer->analyze($modules, fixture_path('nested-public-namespaces')), 'internal_access'),
    );

    expect($targets)->toEqualCanonicalizing([
        'App\Modules\Ledger\Invoices\Models\Invoice',
        'App\Modules\Ledger\Invoices\MyEnums\Kind',
        'App\Modules\Ledger\Invoices\Enums\Hidden',
    ]);
});

it('reports a lowercase reference to another module but not to its public namespace', function () {
    $modules = (new NamespaceResolver(fixture_path('lowercase-references/app/Modules'), 'App\Modules'))->resolve();
    $analyzer = new Analyzer(
        new PhpParserExtractor,
        new FileCollector(['vendor', 'node_modules', 'tests', 'Tests']),
        new PublicApiPolicy,
        RuleSet::fromConfig([]),
    );

    $targets = array_map(
        fn (Violation $v) => $v->target,
        violations_for($analyzer->analyze($modules, fixture_path('lowercase-references')), 'internal_access'),
    );

    expect($targets)->toEqualCanonicalizing(['app\modules\catalog\Models\Product']);
});

it('exposes the default public namespaces', function (string $namespace) {
    expect((new PublicApiPolicy)->isPublic("Modules\\Billing\\{$namespace}\\Thing", billing_module()))->toBeTrue();
})->with(['Contracts', 'Events', 'Data', 'Enums', 'Exceptions']);

it('accepts entries and prefixes written with surrounding backslashes', function () {
    $module = billing_module(['\\Api\\']);

    expect((new PublicApiPolicy(['\\Http\\Resources\\']))->isPublic('Modules\Billing\Http\Resources\InvoiceResource', billing_module()))->toBeTrue()
        ->and((new PublicApiPolicy([]))->isPublic('Modules\Billing\Api\V1\Thing', $module))->toBeTrue()
        ->and((new PublicApiPolicy([]))->isPublic('Modules\Billing\Api', $module))->toBeTrue();
});

it('matches a per-module prefix by whole segments', function () {
    $policy = new PublicApiPolicy([]);
    $module = billing_module(['Api']);

    expect($policy->isPublic('Modules\Billing\ApiHelpers\Thing', $module))->toBeFalse()
        ->and($policy->isPublic('Modules\Billing\Apis', $module))->toBeFalse();
});

it('ignores empty entries', function (string $entry) {
    $module = billing_module([$entry]);
    $policy = new PublicApiPolicy([$entry]);

    expect($policy->isPublic('Modules\Billing\Thing', $module))->toBeFalse()
        ->and($policy->isPublic('Modules\Billing\Invoices\Thing', $module))->toBeFalse();
})->with(['empty' => '', 'a backslash' => '\\']);

it('says why a class is public or internal', function (string $fqcn, Module $module, ?DeclaredVisibility $visibility, string $reason) {
    $declaration = $visibility === null ? null : new ClassDeclaration($fqcn, 'Thing.php', 1, $visibility);

    expect((new PublicApiPolicy)->reason($fqcn, $module, $declaration))->toBe($reason);
})->with([
    'marked internal' => ['Modules\Billing\Contracts\Thing', new Module('Billing', 'Modules\Billing', '/tmp/Billing', open: true), DeclaredVisibility::Internal, 'it is marked #[Internal]'],
    'marked public' => ['Modules\Billing\Services\Thing', new Module('Billing', 'Modules\Billing', '/tmp/Billing'), DeclaredVisibility::PublicApi, 'it is marked #[PublicApi]'],
    'an open module' => ['Modules\Billing\Services\Thing', new Module('Billing', 'Modules\Billing', '/tmp/Billing', open: true), DeclaredVisibility::Unspecified, 'its module [Billing] is open'],
    'a public namespace' => ['Modules\Billing\Invoices\Contracts\Thing', new Module('Billing', 'Modules\Billing', '/tmp/Billing'), null, 'it is under the public namespace [Contracts]'],
    'a listed prefix' => ['Modules\Billing\Api\Thing', new Module('Billing', 'Modules\Billing', '/tmp/Billing', null, ['\\Api\\']), null, 'config/cordon.php lists [Api] in the public of module [Billing]'],
    'nothing' => ['Modules\Billing\Services\Thing', new Module('Billing', 'Modules\Billing', '/tmp/Billing'), null, 'it is not in a public namespace, not marked #[PublicApi], and its module is not open'],
]);

it('names the public namespace without the backslashes of the entry', function () {
    expect((new PublicApiPolicy(['\\Http\\Resources\\']))->reason('Modules\Billing\Http\Resources\Thing', billing_module()))->toBe('it is under the public namespace [Http\Resources]');
});

it('lets a marked class win over an open module and a public namespace', function () {
    $open = new Module('Billing', 'Modules\\Billing', '/tmp/Billing', open: true);
    $internal = new ClassDeclaration('Modules\\Billing\\Contracts\\Thing', 'Thing.php', 1, DeclaredVisibility::Internal);
    $public = new ClassDeclaration('Modules\\Billing\\Services\\Thing', 'Thing.php', 1, DeclaredVisibility::PublicApi);

    expect((new PublicApiPolicy)->isPublic('Modules\\Billing\\Contracts\\Thing', $open, $internal))->toBeFalse()
        ->and((new PublicApiPolicy([]))->isPublic('Modules\\Billing\\Services\\Thing', billing_module(), $public))->toBeTrue()
        ->and((new PublicApiPolicy)->isPublic('Modules\\Billing\\Services\\Thing', $open))->toBeTrue();
});
