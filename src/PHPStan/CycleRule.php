<?php

declare(strict_types=1);

namespace Cordon\PHPStan;

use Cordon\Analysis\Reference;
use Cordon\Analysis\Violation;
use Cordon\Rules\CycleFinder;
use Cordon\Rules\CycleRule as CordonCycleRule;
use Cordon\Rules\Severity;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports cycles in PHPStan from the edges ModuleEdgeCollector gathered in
 * every file. It needs the whole graph, so it only runs on a full analysis,
 * and says so when some modules were left out.
 *
 * @implements Rule<CollectedDataNode>
 *
 * @internal
 */
final class CycleRule implements Rule
{
    private const TIP = 'Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/rules#cycles';

    public function __construct(private readonly CordonContext $context) {}

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Analysing some files only (an editor, a pre-commit hook) never gives the whole graph.
        if ($node->isOnlyFilesAnalysis() || $this->context->severity(CordonCycleRule::ID) !== Severity::Error) {
            return [];
        }

        $this->context->trackDependencies($scope);
        $countListeners = $this->context->countsListeners('cycles');
        $graph = array_fill_keys($this->context->modules()->names(), []);
        $first = [];
        $analysed = [];
        $files = [];

        foreach ($node->get(ModuleEdgeCollector::class) as $file => $items) {
            foreach ($items as $data) {
                $from = $data['module'];
                $analysed[$from] = true;
                $files[] = $file;

                foreach ($data['edges'] as [$to, $line, $kind]) {
                    if ($kind === Reference::LISTENER && ! $countListeners) {
                        continue;
                    }

                    $graph[$from][$to] = true;

                    // Reported at the dependency's first reference, so the location is stable.
                    if (! isset($first[$from][$to]) || [$file, $line] < $first[$from][$to]) {
                        $first[$from][$to] = [$file, $line];
                    }
                }
            }
        }

        if ($files === []) {
            return [];
        }

        sort($files);
        $missing = [];

        foreach ($this->context->modules() as $module) {
            if (! isset($analysed[$module->name]) && $this->context->hasFiles($module, $scope)) {
                $missing[] = $module->name;
            }
        }

        if ($missing !== []) {
            return [RuleErrorBuilder::message(sprintf(
                'Cordon can only check cycles when PHPStan analyses every module; [%s] %s not analysed. Add %s to paths in your PHPStan config.',
                implode(', ', $missing),
                count($missing) === 1 ? 'was' : 'were',
                count($missing) === 1 ? 'its path' : 'their paths',
            ))->identifier('cordon.incompleteGraph')->file($files[0])->line(1)->tip('Learn more: https://chrisabner.github.io/Cordon-Modulith/guide/phpstan#cycles')->build()];
        }

        $sorted = [];

        foreach ($graph as $from => $targets) {
            $neighbours = array_map('strval', array_keys($targets));
            sort($neighbours);
            $sorted[(string) $from] = $neighbours;
        }

        [$kept] = $this->context->baseline()->filter(CycleFinder::violations($sorted), $this->context->basePath(), $this->context->modules());

        return array_map(function (Violation $violation) use ($first): IdentifierRuleError {
            [$file, $line] = $first[(string) $violation->sourceModule][(string) $violation->targetModule];

            return RuleErrorBuilder::message($violation->message)
                ->identifier('cordon.cycle')
                ->file($file)
                ->line($line)
                ->tip(self::TIP)
                ->build();
        }, $kept);
    }
}
