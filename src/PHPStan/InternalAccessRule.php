<?php

declare(strict_types=1);

namespace Cordon\PHPStan;

use Cordon\Analysis\ReferenceCollector;
use Cordon\Analysis\Violation;
use Cordon\Rules\InternalAccessRule as CordonInternalAccessRule;
use Cordon\Rules\Severity;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports internal_access in PHPStan (and therefore in editors), per file.
 *
 * Uses the same reference collector, public API policy and baseline as
 * cordon:verify, on the AST PHPStan already parsed. Target classes are looked
 * up with PHPStan's static reflection, so #[Internal] and #[PublicApi] are
 * honoured without loading code.
 *
 * @implements Rule<FileNode>
 *
 * @internal
 */
final class InternalAccessRule implements Rule
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

        $errors = [];

        // Reported once, on the first analysed file, so a broken config is not silent.
        foreach ($this->context->pullConfigurationErrors() as $error) {
            $errors[] = RuleErrorBuilder::message($error)
                ->identifier('cordon.configuration')
                ->line(1)
                ->tip('Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/phpstan#configuration-errors')
                ->build();
        }

        // PHPStan has no warnings, so the rule reports only in error.
        if ($this->context->severity(CordonInternalAccessRule::ID) !== Severity::Error) {
            return $errors;
        }

        // PHPStan already parsed the file and resolved its names: collect the
        // references from its AST instead of parsing the file again.
        $file = $scope->getFile();
        $collector = new ReferenceCollector($file);
        (new NodeTraverser($collector))->traverse($node->getNodes());

        $from = $this->context->moduleOf($file);

        if ($from === null) {
            return $errors;
        }

        // The baseline keys use the project path, which editor mode hides behind a temporary file.
        $path = $this->context->realPath($file);
        $violations = [];
        $relations = [];

        foreach ($collector->references() as $reference) {
            if ($reference->relation) {
                $relations[$reference->target] = true;
            }
        }

        foreach ($collector->references() as $reference) {
            $target = $reference->target;
            $to = $this->context->modules()->forClass($target);

            // One violation per target and file, like cordon:verify, so the baseline keys match.
            if ($to === null || $to->name === $from->name || isset($violations[$target])) {
                continue;
            }

            if ($this->context->policy()->isPublic($target, $to, $this->context->declaration($target))) {
                continue;
            }

            $violations[$target] = new Violation(CordonInternalAccessRule::ID, '', $path, $reference->line, $from->name, $to->name, $target);
        }

        $kept = $this->context->withoutIgnored($file, array_values($violations));
        [$kept] = $this->context->baseline()->filter($kept, $this->context->basePath(), $this->context->modules());

        foreach ($kept as $violation) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Module [%s] uses %s, which is internal to module [%s].%s',
                $violation->sourceModule,
                $violation->target,
                $violation->targetModule,
                isset($relations[(string) $violation->target]) ? ' '.CordonInternalAccessRule::RELATION_HINT : '',
            ))
                ->identifier('cordon.internalAccess')
                ->line((int) $violation->line)
                ->tip('Depend on its public API instead: a class in a public namespace such as Contracts, or one marked #[PublicApi]. Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/rules#internal-access')
                ->build();
        }

        return $errors;
    }
}
