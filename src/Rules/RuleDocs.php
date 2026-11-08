<?php

declare(strict_types=1);

namespace Cordon\Rules;

/**
 * Where each built-in rule is documented.
 *
 * @internal
 */
final class RuleDocs
{
    private const PAGE = 'https://chrisabner.github.io/Cordon-Modulith/guide/rules#';

    private const RULES = [InternalAccessRule::ID, UndeclaredDependencyRule::ID, CycleRule::ID, UnusedDependencyRule::ID, HostAccessRule::ID, LayerRule::ID, PublicApiLeakRule::ID];

    /**
     * The documentation of a built-in rule, or null for a custom rule.
     */
    public static function url(string $rule): ?string
    {
        return in_array($rule, self::RULES, true) ? self::PAGE.str_replace('_', '-', $rule) : null;
    }
}
