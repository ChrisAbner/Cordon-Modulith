<?php

declare(strict_types=1);

namespace Cordon\PHPStan;

use Cordon\Analysis\ClassDeclaration;
use Cordon\Analysis\DeclaredVisibility;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Attributes\Internal;
use Cordon\Attributes\PublicApi;
use Cordon\Laravel\ResolverFactory;
use Cordon\Laravel\StandaloneConfig;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Rules\InternalAccessRule as CordonInternalAccessRule;
use Cordon\Support\Paths;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports internal_access in PHPStan (and therefore in editors), per file.
 *
 * Uses the same extractor and public API policy as cordon:verify. Target
 * classes are looked up with PHPStan's static reflection, so #[Internal] and
 * #[PublicApi] are honoured without loading code.
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

        $file = $scope->getFile();
        $from = $modules->forPath($file);

        if (! $this->enabled || $from === null || $this->isExcluded($from, $file)) {
            return [];
        }

        $errors = [];
        $reported = [];

        foreach ((new PhpParserExtractor)->extract($file)->references as $reference) {
            $target = $reference->target;
            $to = $modules->forClass($target);

            if ($to === null || $to->name === $from->name || isset($reported[$target])) {
                continue;
            }

            if ($policy->isPublic($target, $to, $this->declaration($target))) {
                continue;
            }

            $reported[$target] = true;
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Module [%s] uses %s, which is internal to module [%s].',
                $from->name,
                $target,
                $to->name,
            ))
                ->identifier('cordon.internalAccess')
                ->line($reference->line)
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

            $this->modules = ResolverFactory::make($config, $this->basePath)
                ->resolve()
                ->configure((array) $config->get('cordon.modules', []));
            $this->policy = new PublicApiPolicy(self::strings($config->get('cordon.public_namespaces', [])));
            $this->exclude = self::strings($config->get('cordon.exclude', ['vendor', 'node_modules']));
            $this->enabled = $config->get('cordon.rules.'.CordonInternalAccessRule::ID, true) !== false;
        }

        return [$this->modules, $this->policy];
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
