<?php

declare(strict_types=1);

namespace Cordon\Documentation;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Static extraction of event dispatches and listeners; the code is never loaded.
 */
final class EventExtractor
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * @return list<EventUsage>
     */
    public function extract(string $file): array
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return [];
        }

        try {
            $ast = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return [];
        }

        // Names must be resolved in a first pass: the collector inspects child
        // nodes (new X inside event(...)) before a single pass would reach them.
        $ast = (new NodeTraverser(new NameResolver))->traverse($ast);

        $collector = new EventCollector($file);
        (new NodeTraverser($collector))->traverse($ast);

        return $collector->usages();
    }
}
