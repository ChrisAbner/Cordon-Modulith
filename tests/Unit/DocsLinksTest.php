<?php

const RULES_PAGE = 'https://chrisabner.github.io/Cordon-Modulith/guide/rules#';

/**
 * The anchors VitePress gives the headings of a page: lower case, with
 * underscores and spaces as dashes.
 *
 * @return list<string>
 */
function heading_anchors(string $page): array
{
    preg_match_all('/^#{2,3} (.+)$/m', (string) file_get_contents($page), $matches);

    return array_map(fn (string $heading) => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($heading)), '-'), $matches[1]);
}

it('links every Cordon error in PHPStan to its rule in the documentation', function (string $rule, string $anchor) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/PHPStan/'.$rule.'.php');

    expect($source)->toContain('Learn more: '.RULES_PAGE.$anchor)
        ->and(heading_anchors(dirname(__DIR__, 2).'/docs-site/guide/rules.md'))->toContain($anchor);
})->with([
    'internal access' => ['InternalAccessRule', 'internal-access'],
    'undeclared dependency' => ['UndeclaredDependencyRule', 'undeclared-dependency'],
    'cycles' => ['CycleRule', 'cycles'],
]);

it('links the errors about the setup to the PHPStan guide', function (string $class, string $anchor) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/PHPStan/'.$class.'.php');

    expect($source)->toContain('Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/phpstan#'.$anchor)
        ->and(heading_anchors(dirname(__DIR__, 2).'/docs-site/guide/phpstan.md'))->toContain($anchor);
})->with([
    'incomplete graph' => ['CycleRule', 'cycles'],
    'configuration' => ['InternalAccessRule', 'configuration-errors'],
]);
