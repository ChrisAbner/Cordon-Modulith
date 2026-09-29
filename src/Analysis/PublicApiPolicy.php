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
        if ($declaration?->visibility === DeclaredVisibility::Internal) {
            return false;
        }

        if ($declaration?->visibility === DeclaredVisibility::PublicApi || $module->open) {
            return true;
        }

        $relative = $module->relativeName($fqcn);

        foreach ($this->publicNamespaces as $entry) {
            if ($this->matchesAnywhere($relative, trim($entry, '\\'))) {
                return true;
            }
        }

        foreach ($module->publicApi as $prefix) {
            $prefix = trim($prefix, '\\');

            if ($prefix !== '' && ($relative === $prefix || str_starts_with($relative, $prefix.'\\'))) {
                return true;
            }
        }

        return false;
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
