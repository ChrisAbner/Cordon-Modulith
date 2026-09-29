<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Contracts\Rule;

final class RuleSet
{
    /**
     * Built-in rules, enabled unless explicitly set to false.
     *
     * @param  array<array-key, mixed>  $enabled  e.g. ['cycles' => false]
     * @return list<Rule>
     */
    public static function fromConfig(array $enabled = []): array
    {
        $available = [
            InternalAccessRule::ID => static fn (): Rule => new InternalAccessRule,
            UndeclaredDependencyRule::ID => static fn (): Rule => new UndeclaredDependencyRule,
            CycleRule::ID => static fn (): Rule => new CycleRule,
        ];

        $rules = [];

        foreach ($available as $id => $factory) {
            if (($enabled[$id] ?? true) !== false) {
                $rules[] = $factory();
            }
        }

        return $rules;
    }
}
