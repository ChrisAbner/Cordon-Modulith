<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\Module;

/**
 * Decides whether a class belongs to its module's public API.
 *
 * Precedence: #[Internal] > #[PublicApi] > open module > public namespaces
 * (global config + module "public" list) > internal by default.
 */
final readonly class PublicApiPolicy
{
    /**
     * @param  list<string>  $publicNamespaces  Namespaces relative to each module, e.g. "Contracts".
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

        foreach ([...$this->publicNamespaces, ...$module->publicApi] as $prefix) {
            $prefix = trim($prefix, '\\');

            if ($prefix !== '' && ($relative === $prefix || str_starts_with($relative, $prefix.'\\'))) {
                return true;
            }
        }

        return false;
    }
}
