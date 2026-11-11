<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\Module;

/**
 * Decides whether a class belongs to its module's public API.
 *
 * Precedence: #[Internal] > #[PublicApi] > open module > public namespaces
 * (global config, matched at any depth; module "public" list, anchored at the
 * module root) > internal by default.
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
    ) {}

    public function isPublic(string $fqcn, Module $module, ?ClassDeclaration $declaration = null): bool
    {
        return $this->decide($fqcn, $module, $declaration)[0];
    }

    /**
     * Why the class is public or internal, in words (for cordon:explain).
     * The wording may change in any version.
     *
     * @internal
     */
    public function reason(string $fqcn, Module $module, ?ClassDeclaration $declaration = null): string
    {
        return $this->decide($fqcn, $module, $declaration)[1];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function decide(string $fqcn, Module $module, ?ClassDeclaration $declaration): array
    {
        if ($declaration?->visibility === DeclaredVisibility::Internal) {
            return [false, 'it is marked #[Internal]'];
        }

        if ($declaration?->visibility === DeclaredVisibility::PublicApi) {
            return [true, 'it is marked #[PublicApi]'];
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
