<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Module\Module;

/**
 * Named interfaces in depends_on: "Catalog::Pricing" limits what a module may
 * use of Catalog to the classes of that interface. "Catalog" and "Catalog::*"
 * keep meaning the whole public API. Shared by the CLI and PHPStan rules, so
 * both give the same message.
 *
 * @internal
 */
final class NamedInterfaces
{
    /**
     * The message for $class, a class of $to (the module $from sees), when
     * $from lists $to only through interfaces and none of them has the class.
     * Null when depends_on allows the class, or does not restrict $to.
     *
     * Whether the class is public is for the caller: an internal class is the
     * business of internal_access.
     */
    public static function outside(Module $from, Module $to, string $class): ?string
    {
        if ($from->dependsOn === null || ! $from->declares($to->name) || $from->declaresWhole($to->name)) {
            return null;
        }

        $declared = $from->declaredInterfaces($to->name);

        foreach ($to->interfacesOf($class) as $name) {
            if (in_array($name, $declared, true)) {
                return null;
            }
        }

        return sprintf(
            'Module [%s] uses %s, which is not in the interfaces it declares on [%s] (%s).',
            $from->name,
            $class,
            $to->name,
            implode(', ', $declared),
        );
    }
}
