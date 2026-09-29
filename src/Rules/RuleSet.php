<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Closure;
use Cordon\Contracts\Rule;
use InvalidArgumentException;

final class RuleSet
{
    /**
     * Built-in rules, enabled unless explicitly set to false, followed by custom rules.
     *
     * Custom rules are class names implementing Cordon\Contracts\Rule, listed
     * as values: [App\Architecture\MyRule::class] or ['my_rule' => MyRule::class].
     *
     * @param  array<array-key, mixed>  $config  e.g. ['cycles' => false, MyRule::class]
     * @param  (Closure(class-string<Rule>): Rule)|null  $make  Builds custom rules (the Laravel container in the service provider).
     * @return list<Rule>
     */
    public static function fromConfig(array $config = [], ?Closure $make = null): array
    {
        $available = [
            InternalAccessRule::ID => static fn (): Rule => new InternalAccessRule,
            UndeclaredDependencyRule::ID => static fn (): Rule => new UndeclaredDependencyRule,
            CycleRule::ID => static fn (): Rule => new CycleRule,
        ];

        $rules = [];

        foreach ($available as $id => $factory) {
            if (($config[$id] ?? true) !== false) {
                $rules[] = $factory();
            }
        }

        $ids = array_fill_keys(array_keys($available), true);

        foreach ($config as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $rule = self::custom($value, $make);

            if (isset($ids[$rule->id()])) {
                throw new InvalidArgumentException(sprintf(
                    'Custom rule [%s] uses the id [%s], which is already taken. Return a unique id from %s::id().',
                    $value,
                    $rule->id(),
                    $value,
                ));
            }

            $ids[$rule->id()] = true;
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * @param  (Closure(class-string<Rule>): Rule)|null  $make
     */
    private static function custom(string $class, ?Closure $make): Rule
    {
        if (! class_exists($class) || ! is_subclass_of($class, Rule::class)) {
            throw new InvalidArgumentException(sprintf(
                'Custom rule [%s] must be an existing class that implements %s.',
                $class,
                Rule::class,
            ));
        }

        return $make !== null ? $make($class) : new $class;
    }
}
