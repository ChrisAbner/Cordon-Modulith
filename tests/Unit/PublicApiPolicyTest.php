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
