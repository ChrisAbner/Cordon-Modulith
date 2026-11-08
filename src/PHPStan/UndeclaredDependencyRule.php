<?php

declare(strict_types=1);

namespace Cordon\PHPStan;

use Cordon\Analysis\Reference;
use Cordon\Analysis\ReferenceCollector;
use Cordon\Analysis\Violation;
use Cordon\Rules\Severity;
use Cordon\Rules\UndeclaredDependencyRule as CordonUndeclaredDependencyRule;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports undeclared_dependency in PHPStan, per file: it only needs the
 * module map and the module's depends_on, not the whole dependency graph.
 *
 * @implements Rule<FileNode>
 *
 * @internal
 */
final class UndeclaredDependencyRule implements Rule
{
    public function __construct(private readonly CordonContext $context) {}

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $this->context->trackDependencies($scope);

        // PHPStan has no warnings, so the rule reports only in error.
        if ($this->context->severity(CordonUndeclaredDependencyRule::ID) !== Severity::Error) {
            return [];
        }

        $file = $scope->getFile();
        $collector = new ReferenceCollector($file);
        (new NodeTraverser($collector))->traverse($node->getNodes());

        $from = $this->context->moduleOf($file);

        if ($from === null || $from->dependsOn === null) {
            return [];
        }

        $path = $this->context->realPath($file);
        $countListeners = $this->context->countsListeners('depends_on');
        $violations = [];

        foreach ($collector->references() as $reference) {
            $to = $this->context->modules()->forClass($reference->target);

            // One violation per file and module, like cordon:verify, so the baseline keys match.
            if ($to === null
                || $to->name === $from->name
                || in_array($to->name, $from->dependsOn, true)
                || isset($violations[$to->name])
                || ($reference->kind === Reference::LISTENER && ! $countListeners)) {
                continue;
            }

            $violations[$to->name] = new Violation(
                CordonUndeclaredDependencyRule::ID,
                sprintf(
                    'Module [%s] depends on module [%s] (via %s), but [%s] is not listed in its depends_on.',
                    $from->name,
                    $to->name,
                    $reference->target,
                    $to->name,
                ),
                $path,
                $reference->line,
                $from->name,
                $to->name,
                $to->name,
            );
        }

        $kept = $this->context->withoutIgnored($file, array_values($violations));
        [$kept] = $this->context->baseline()->filter($kept, $this->context->basePath(), $this->context->modules());

        return array_map(static fn (Violation $violation): IdentifierRuleError => RuleErrorBuilder::message($violation->message)
            ->identifier('cordon.undeclaredDependency')
            ->line((int) $violation->line)
            ->tip(sprintf(
                'Add [%s] to the depends_on of [%s] in config/cordon.php if the dependency is intended. Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/rules#undeclared-dependency',
                $violation->targetModule,
                $violation->sourceModule,
            ))
            ->build(), $kept);
    }
}
