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
 *
 * @internal
 */
final class PhpParserExtractor implements DependencyExtractor
{
    private Parser $parser;

    /**
     * @param  bool  $docblocks  Also read the class names in docblock types (the `docblocks` config key).
     */
    public function __construct(private readonly bool $docblocks = false, ?Parser $parser = null)
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

        $collector = new ReferenceCollector($file, $this->docblocks);

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        return new FileAnalysis($file, $collector->declarations(), $collector->references(), ignores: IgnoreDirectives::parse($code));
    }
}
