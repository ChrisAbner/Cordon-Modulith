<?php

/*
 * Checks that the lowest nikic/php-parser composer.json allows still analyses
 * modern PHP. The test suite cannot: its dev dependencies require a newer
 * php-parser. CI runs this after `composer update --prefer-lowest` without
 * require-dev (the parser-floor job).
 *
 *   php tests/parser-floor.php
 */

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Composer\InstalledVersions;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\Reference;

$expected = ['HookBody', 'HookParam', 'HookType', 'PromotedHookBody', 'PromotedHookType'];
$analysis = (new PhpParserExtractor)->extract(__DIR__.'/Fixtures/edge-cases/PropertyHooks.php');

$found = array_values(array_unique(array_map(
    static fn (Reference $reference): string => substr($reference->target, strlen('Edge\\Target\\')),
    array_filter($analysis->references, static fn (Reference $reference): bool => str_starts_with($reference->target, 'Edge\\Target\\')),
)));
sort($found);

$version = InstalledVersions::getPrettyVersion('nikic/php-parser');

if ($analysis->error !== null || $found !== $expected) {
    fwrite(STDERR, sprintf(
        "php-parser %s does not analyse property hooks: %s\n",
        $version,
        $analysis->error ?? 'found '.(implode(', ', $found) ?: 'nothing').', expected '.implode(', ', $expected),
    ));

    exit(1);
}

echo "php-parser {$version} analyses property hooks.\n";
