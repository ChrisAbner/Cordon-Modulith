<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Baseline\Baseline;
use Cordon\Contracts\ModuleResolver;
use Cordon\Laravel\Verifier;
use Cordon\Module\ModuleMap;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;
use Cordon\Tests\Oracle\Temp;
use Illuminate\Config\Repository;

/**
 * A resolver for tests/Fixtures/namespace-app that counts how often it resolves.
 */
function counting_resolver(): ModuleResolver
{
    return new class implements ModuleResolver
    {
        public int $calls = 0;

        public function resolve(): ModuleMap
        {
            $this->calls++;

            return (new NamespaceResolver(fixture_path('namespace-app/app/Modules'), 'App\\Modules'))->resolve();
        }
    };
}

/**
 * @param  array<string, mixed>  $cordon
 */
function namespace_app_verifier(ModuleResolver $resolver, array $cordon = []): Verifier
{
    $analyzer = new Analyzer(
        new PhpParserExtractor,
        new FileCollector(['vendor', 'node_modules', 'tests', 'Tests']),
        new PublicApiPolicy,
        RuleSet::fromConfig(),
    );

    return new Verifier($resolver, $analyzer, new Repository(['cordon' => $cordon]), fixture_path('namespace-app'));
}

it('resolves the modules once', function () {
    $resolver = counting_resolver();
    $verifier = namespace_app_verifier($resolver, ['modules' => ['Shared' => ['open' => true]]]);

    $verifier->modules();
    $verifier->configurationErrors();

    expect($resolver->calls)->toBe(1);
});

it('reads the baseline file once', function () {
    $file = Temp::removeAtExit(tempnam(sys_get_temp_dir(), 'cordon-baseline-'));
    $verifier = namespace_app_verifier(counting_resolver(), ['baseline' => $file, 'modules' => ['Shared' => ['open' => true]]]);
    $result = $verifier->analyze();
    Baseline::fromViolations($result->violations, $verifier->basePath())->save($file);

    $verifier->baselineWarnings();
    unlink($file);

    $verified = $verifier->applyBaseline($result);

    expect($result->violations)->not->toBe([])
        ->and($verified->violations)->toBe([])
        ->and($verified->suppressed)->toBe(count($result->violations));
});
