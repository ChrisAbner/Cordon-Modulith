<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\Module;
use Cordon\Module\ModuleMap;

/**
 * Decides whether a class belongs to its module's public API.
 *
 * Precedence: a consumer nested in the module (it sees all of it) >
 * #[Internal] > #[PublicApi] > open module > public namespaces (global
 * config, matched at any depth; module "public" list, anchored at the module
 * root) > internal by default. For a class of a nested module seen from
 * outside its parent, the open setting and the public list of the parent
 * decide instead of the nested module's own.
 *
 * Who asks matters for nested modules. A nested module may use any class of its
 * ancestors, internal ones included. Billing.Invoices exists only inside
 * Billing, so the modules inside Billing see its public API, while any other
 * consumer sees its classes as classes of Billing, public only when #[PublicApi]
 * or the public list of Billing says so.
 *
 * @api
 */
final readonly class PublicApiPolicy
{
    /**
     * @param  list<string>  $publicNamespaces  Namespaces matched at any depth relative to each module, e.g. "Contracts".
     */
    public function __construct(
        private array $publicNamespaces = ['Contracts', 'Events', 'Data', 'Enums', 'Exceptions'],
        private ?ModuleMap $modules = null,
    ) {}

    /**
     * The same policy knowing the modules, which it needs to find the parent
     * of a nested module. AnalysisContext does this for the rules.
     */
    public function withModules(ModuleMap $modules): self
    {
        return new self($this->publicNamespaces, $modules);
    }

    /**
     * @param  Module|null  $consumer  The module using the class; null or Module::host() for code outside the module tree.
     */
    public function isPublic(string $fqcn, Module $module, ?ClassDeclaration $declaration = null, ?Module $consumer = null): bool
    {
        return $this->decide($fqcn, $module, $declaration, $consumer)[0];
    }

    /**
     * Why the class is public or internal, in words (for cordon:explain).
     * The wording may change in any version.
     *
     * @internal
     */
    public function reason(string $fqcn, Module $module, ?ClassDeclaration $declaration = null, ?Module $consumer = null): string
    {
        return $this->decide($fqcn, $module, $declaration, $consumer)[1];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function decide(string $fqcn, Module $module, ?ClassDeclaration $declaration, ?Module $consumer): array
    {
        // A nested module sees everything of its ancestors, internal classes included.
        if ($consumer !== null && $consumer->isNestedIn($module)) {
            return [true, sprintf('the consumer [%s] is nested in module [%s], which exposes all of it to its nested modules', $consumer->name, $module->name)];
        }

        if ($declaration?->visibility === DeclaredVisibility::Internal) {
            return [false, 'it is marked #[Internal]'];
        }

        if ($declaration?->visibility === DeclaredVisibility::PublicApi) {
            return [true, 'it is marked #[PublicApi]'];
        }

        $owner = $this->modules?->visible($module, $consumer) ?? $module;

        if ($owner->name !== $module->name) {
            return $this->throughAncestor($fqcn, $module, $owner);
        }

        if ($module->open) {
            return [true, sprintf('its module [%s] is open', $module->name)];
        }

        $relative = $module->relativeName($fqcn);
        $lowerRelative = strtolower($relative);

        foreach ($this->publicNamespaces as $entry) {
            if ($this->matchesAnywhere($lowerRelative, strtolower(trim($entry, '\\')))) {
                return [true, sprintf('it is under the public namespace [%s]', trim($entry, '\\'))];
            }
        }

        foreach ($module->publicApi as $prefix) {
            $lower = strtolower(trim($prefix, '\\'));

            if ($lower !== '' && ($lowerRelative === $lower || str_starts_with($lowerRelative, $lower.'\\'))) {
                return [true, sprintf('config/cordon.php lists [%s] in the public of module [%s]', trim($prefix, '\\'), $module->name)];
            }
        }

        return [false, 'it is not in a public namespace, not marked #[PublicApi], and its module is not open'];
    }

    /**
     * A class of a nested module seen from outside its parent: the child does
     * not exist there, so only the public list of the module that contains it
     * (Billing.public => ['Invoices\Api']) or its being open exposes it. The
     * public namespaces do not reach into a nested module.
     *
     * @return array{0: bool, 1: string}
     */
    private function throughAncestor(string $fqcn, Module $nested, Module $owner): array
    {
        if ($owner->open) {
            return [true, sprintf('its module [%s] is open', $owner->name)];
        }

        $lowerRelative = strtolower($owner->relativeName($fqcn));

        foreach ($owner->publicApi as $prefix) {
            $lower = strtolower(trim($prefix, '\\'));

            if ($lower !== '' && ($lowerRelative === $lower || str_starts_with($lowerRelative, $lower.'\\'))) {
                return [true, sprintf('config/cordon.php lists [%s] in the public of module [%s]', trim($prefix, '\\'), $owner->name)];
            }
        }

        return [false, sprintf('it belongs to module [%s], nested in [%s]: outside [%s] only what the public list of [%s] names is exposed', $nested->name, $owner->name, $owner->name, $owner->name)];
    }

    /**
     * Whether the entry appears as a contiguous run of whole segments in the
     * namespace of the relative class name (the short class name is excluded),
     * or is the relative name itself.
     */
    private function matchesAnywhere(string $relative, string $entry): bool
    {
        if ($entry === '') {
            return false;
        }

        if ($relative === $entry) {
            return true;
        }

        $namespace = substr($relative, 0, (int) strrpos($relative, '\\'));

        return str_contains('\\'.$namespace.'\\', '\\'.$entry.'\\');
    }
}
