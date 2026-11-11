<?php

declare(strict_types=1);

namespace Cordon\PHPStan;

use Cordon\Analysis\Reference;
use Cordon\Analysis\ReferenceCollector;
use Cordon\Analysis\Violation;
use Cordon\Rules\NamedInterfaces;
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
            $target = $this->context->modules()->forClass($reference->target);

            if ($target === null || $target->name === $from->name) {
                continue;
            }

            // A nested module exists only inside its parent: from outside, the dependency is on the parent.
            $to = $this->context->modules()->visible($target, $from);

            if ($to->isNestedIn($from) || $from->isNestedIn($to) || ($reference->kind === Reference::LISTENER && ! $countListeners)) {
                continue;
            }

            // Listed only through named interfaces (Catalog::Pricing): a public class outside them
            // is reported once per file and class, like cordon:verify. An internal class is internal_access's.
            if ($from->declares($to->name)) {
                $message = NamedInterfaces::outside($from, $to, $reference->target);
                $key = '#'.strtolower($reference->target);

                if ($message !== null && ! isset($violations[$key]) && $this->context->policy()->isPublic($reference->target, $target, $this->context->declaration($reference->target), $from)) {
                    $violations[$key] = new Violation(CordonUndeclaredDependencyRule::ID, $message, $path, $reference->line, $from->name, $to->name, $reference->target);
                }

                continue;
            }

            // One violation per file and module, like cordon:verify, so the baseline keys match.
            if (isset($violations[$to->name])) {
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
            ->tip($violation->target === $violation->targetModule
                ? sprintf(
                    'Add [%s] to the depends_on of [%s] in config/cordon.php if the dependency is intended. Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/rules#undeclared-dependency',
                    $violation->targetModule,
                    $violation->sourceModule,
                )
                : sprintf(
                    'Add the class to an interface of [%s] that [%s] declares, or declare the interface that has it in depends_on, in config/cordon.php. Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/rules#undeclared-dependency',
                    $violation->targetModule,
                    $violation->sourceModule,
                ))
            ->build(), $kept);
    }
}
