<?php

declare(strict_types=1);

namespace Cordon\PHPStan;

use Cordon\Analysis\ClassDeclaration;
use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\ReferenceCollector;
use Cordon\Analysis\Violation;
use Cordon\Attributes\Internal;
use Cordon\Attributes\PublicApi;
use Cordon\Baseline\Baseline;
use Cordon\Laravel\ResolverFactory;
use Cordon\Laravel\StandaloneConfig;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\InternalAccessRule as CordonInternalAccessRule;
use Cordon\Support\Paths;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Throwable;

/**
 * Reports internal_access in PHPStan (and therefore in editors), per file.
 *
 * Uses the same reference collector, public API policy and baseline as
 * cordon:verify, on the AST PHPStan already parsed. Target classes are looked
 * up with PHPStan's static reflection, so #[Internal] and #[PublicApi] are
 * honoured without loading code.
 *
 * @implements Rule<FileNode>
 */
final class InternalAccessRule implements Rule
{
    private ?ModuleMap $modules = null;

    private ?PublicApiPolicy $policy = null;

    /** @var list<string> */
    private array $exclude = [];

    private bool $enabled = true;

    private Baseline $baseline;

    /** @var list<string> */
    private array $trackedFiles = [];

    /** @var list<string> */
    private array $pendingWarnings = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly string $basePath,
    ) {}

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        [$modules, $policy] = $this->boot();

        // The result depends on files PHPStan does not analyse. Without this,
        // its result cache keeps old errors after the baseline or config change.
        // DependencyTracker exists since PHPStan 2.3; older versions skip this.
        if ($scope instanceof DependencyTracker) { // @phpstan-ignore instanceof.alwaysTrue (always true on 2.3+, the version Cordon is developed with)
            foreach ($this->trackedFiles as $tracked) {
                $scope->trackFileDependency($tracked);
            }
        }

        $errors = [];

        // Reported once, on the first analysed file, so a broken config is not silent.
        foreach ($this->pendingWarnings as $warning) {
            $errors[] = RuleErrorBuilder::message($warning)->identifier('cordon.configuration')->line(1)->build();
        }

        $this->pendingWarnings = [];

        $file = $scope->getFile();
        $from = $modules->forPath($file);

        if (! $this->enabled || $from === null || $this->isExcluded($from, $file)) {
            return $errors;
        }

        // PHPStan already parsed the file and resolved its names: collect the
        // references from its AST instead of parsing the file again.
        $collector = new ReferenceCollector($file);
        (new NodeTraverser($collector))->traverse($node->getNodes());

        $violations = [];

        foreach ($collector->references() as $reference) {
            $target = $reference->target;
            $to = $modules->forClass($target);

            // One violation per target and file, like cordon:verify, so the baseline keys match.
            if ($to === null || $to->name === $from->name || isset($violations[$target])) {
                continue;
            }

            if ($policy->isPublic($target, $to, $this->declaration($target))) {
                continue;
            }

            $violations[$target] = new Violation(CordonInternalAccessRule::ID, '', $file, $reference->line, $from->name, $to->name, $target);
        }

        [$kept] = $this->baseline->filter(array_values($violations), $this->basePath);

        foreach ($kept as $violation) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Module [%s] uses %s, which is internal to module [%s].',
                $violation->sourceModule,
                $violation->target,
                $violation->targetModule,
            ))
                ->identifier('cordon.internalAccess')
                ->line((int) $violation->line)
                ->tip('Depend on its public API instead: a class in a public namespace such as Contracts, or one marked #[PublicApi].')
                ->build();
        }

        return $errors;
    }

    /**
     * @return array{0: ModuleMap, 1: PublicApiPolicy}
     */
    private function boot(): array
    {
        if ($this->modules === null || $this->policy === null) {
            $config = StandaloneConfig::load($this->basePath);
            $this->pendingWarnings = $config->warnings();

            $this->modules = ResolverFactory::make($config, $this->basePath)
                ->resolve()
                ->configure((array) $config->get('cordon.modules', []));
            $this->policy = new PublicApiPolicy(self::strings($config->get('cordon.public_namespaces', [])));
            $this->exclude = self::strings($config->get('cordon.exclude', ['vendor', 'node_modules']));
            $this->enabled = $config->get('cordon.rules.'.CordonInternalAccessRule::ID, true) !== false;
            $this->baseline = $this->loadBaseline($config->get('cordon.baseline', 'cordon-baseline.json'));
            $this->trackedFiles = [
                $this->baselinePath($config->get('cordon.baseline', 'cordon-baseline.json')),
                ...array_map(fn (string $name): string => Paths::join($this->basePath, 'config/'.$name.'.php'), ['cordon', 'modules', 'app-modules']),
            ];
        }

        return [$this->modules, $this->policy];
    }

    /**
     * The same baseline cordon:verify applies. A file that cannot be read is
     * reported once and not applied, rather than hiding every violation.
     */
    private function loadBaseline(mixed $file): Baseline
    {
        $path = $this->baselinePath($file);
        $relative = (string) Paths::relative($this->basePath, $path);

        if (! is_file($path)) {
            return Baseline::empty();
        }

        try {
            return Baseline::load($path);
        } catch (Throwable $e) {
            $this->pendingWarnings[] = sprintf(
                'Cordon could not read the baseline %s (%s), so it is not applied. Fix the file or regenerate it with cordon:verify --generate-baseline.',
                $relative,
                $e->getMessage(),
            );

            return Baseline::empty();
        }
    }

    private function baselinePath(mixed $file): string
    {
        return Paths::join($this->basePath, is_string($file) && $file !== '' ? $file : 'cordon-baseline.json');
    }

    private function isExcluded(Module $module, string $file): bool
    {
        $relative = (string) Paths::relative($module->path, $file);
        $directories = array_slice(explode('/', $relative), 0, -1);

        return array_intersect($directories, $this->exclude) !== [];
    }

    private function declaration(string $class): ?ClassDeclaration
    {
        if (! $this->reflectionProvider->hasClass($class)) {
            return null;
        }

        $native = $this->reflectionProvider->getClass($class)->getNativeReflection();
        $visibility = DeclaredVisibility::Unspecified;

        foreach ($native->getAttributes() as $attribute) {
            $name = ltrim($attribute->getName(), '\\');

            if ($name === Internal::class) {
                $visibility = DeclaredVisibility::Internal;

                break;
            }

            if ($name === PublicApi::class) {
                $visibility = DeclaredVisibility::PublicApi;
            }
        }

        return new ClassDeclaration($class, (string) $native->getFileName(), (int) $native->getStartLine(), $visibility);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_map('strval', array_filter((array) $value, 'is_scalar')));
    }
}
