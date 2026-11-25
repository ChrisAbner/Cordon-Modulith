<?php

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * What users may rely on. Everything else in src/ is @internal and may change in any release.
 */
const PUBLIC_API = [
    'Cordon\\Analysis\\AnalysisContext',
    'Cordon\\Analysis\\ClassDeclaration',
    'Cordon\\Analysis\\DeclaredVisibility',
    'Cordon\\Analysis\\Edge',
    'Cordon\\Analysis\\PublicApiPolicy',
    'Cordon\\Analysis\\Reference',
    'Cordon\\Analysis\\SymbolTable',
    'Cordon\\Analysis\\Violation',
    'Cordon\\Attributes\\Internal',
    'Cordon\\Attributes\\PublicApi',
    'Cordon\\Contracts\\Rule',
    'Cordon\\Module\\Module',
    'Cordon\\Module\\ModuleMap',
    'Cordon\\Rules\\Severity',
    'Cordon\\Testing\\Cordon',
];

/**
 * The @api or @internal tags in the docblock of every named class, interface, enum and trait in src/.
 *
 * @return array<string, list<string>>
 */
function api_tags(): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $tags = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $ast = (new NodeTraverser(new NameResolver))->traverse($parser->parse((string) file_get_contents($file->getPathname())) ?? []);

        foreach ((new NodeFinder)->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
            if (! isset($class->namespacedName)) {
                continue;
            }

            preg_match_all('/@(api|internal)\b/', (string) $class->getDocComment()?->getText(), $matches);
            $tags[$class->namespacedName->toString()] = $matches[1];
        }
    }

    ksort($tags);

    return $tags;
}

it('marks every class in src as @api or @internal', function () {
    $untagged = array_keys(array_filter(api_tags(), fn (array $tags) => count($tags) !== 1));

    expect($untagged)->toBe([]);
});

it('exposes exactly the declared public API', function () {
    $api = array_keys(array_filter(api_tags(), fn (array $tags) => $tags === ['api']));

    expect($api)->toBe(PUBLIC_API);
});
