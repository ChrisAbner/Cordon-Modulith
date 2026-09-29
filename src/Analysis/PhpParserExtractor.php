<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Contracts\DependencyExtractor;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Static extractor based on nikic/php-parser: the analysed code is never loaded.
 */
final class PhpParserExtractor implements DependencyExtractor
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function extract(string $file): FileAnalysis
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return new FileAnalysis($file, error: 'File could not be read.');
        }

        try {
            $ast = $this->parser->parse($code) ?? [];
        } catch (Error $error) {
            return new FileAnalysis($file, error: $error->getMessage());
        }

        $collector = new ReferenceCollector($file);

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        return new FileAnalysis($file, $collector->declarations(), $collector->references());
    }
}
