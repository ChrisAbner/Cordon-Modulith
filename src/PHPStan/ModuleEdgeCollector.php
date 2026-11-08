<?php

declare(strict_types=1);

namespace Cordon\PHPStan;

use Cordon\Analysis\ReferenceCollector;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;

/**
 * Collects, per analysed file of a module, the modules it references, for the
 * cycles rule, which needs the whole graph. Only arrays and scalars: PHPStan
 * passes them between processes and keeps them in its result cache.
 *
 * @implements Collector<FileNode, array{module: string, edges: list<array{0: string, 1: int, 2: string}>}>
 *
 * @internal
 */
final class ModuleEdgeCollector implements Collector
{
    public function __construct(private readonly CordonContext $context) {}

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return array{module: string, edges: list<array{0: string, 1: int, 2: string}>}|null
     *                                                                                      The file's module and, per target module and kind, the first reference: [module, line, kind].
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $this->context->trackDependencies($scope);

        $collector = new ReferenceCollector($scope->getFile());
        (new NodeTraverser($collector))->traverse($node->getNodes());

        $from = $this->context->moduleOf($scope->getFile());

        if ($from === null) {
            return null;
        }

        $edges = [];

        foreach ($collector->references() as $reference) {
            $to = $this->context->modules()->forClass($reference->target);

            if ($to !== null && $to->name !== $from->name && ! isset($edges[$to->name.'|'.$reference->kind])) {
                $edges[$to->name.'|'.$reference->kind] = [$to->name, $reference->line, $reference->kind];
            }
        }

        return ['module' => $from->name, 'edges' => array_values($edges)];
    }
}
