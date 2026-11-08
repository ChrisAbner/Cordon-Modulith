<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Closure;
use Cordon\Contracts\Rule;
use Cordon\Support\ConfigValidator;
use InvalidArgumentException;

/**
 * @internal
 */
final class RuleSet
{
    private const BUILT_IN = [InternalAccessRule::ID, UndeclaredDependencyRule::ID, CycleRule::ID, UnusedDependencyRule::ID, HostAccessRule::ID];

    /**
     * Built-in rules that start below error: checks added after 0.3 arrive as
     * warnings, so an upgrade does not fail a build that passed (see the
     * compatibility page).
     */
    private const DEFAULTS = [UnusedDependencyRule::ID => 'warn', HostAccessRule::ID => 'warn'];

    /**
     * Built-in rules, enabled unless set to off (or false), followed by custom rules.
     *
     * Custom rules are class names implementing Cordon\Contracts\Rule: listed
     * as values ([MyRule::class] or ['my_rule' => MyRule::class]), or as keys
     * with a severity ([MyRule::class => 'warn']).
     *
     * @param  array<array-key, mixed>  $config  e.g. ['cycles' => 'warn', MyRule::class]
     * @param  (Closure(class-string<Rule>): Rule)|null  $make  Builds custom rules (the Laravel container in the service provider).
     * @param  array<array-key, mixed>  $listeners  The `listeners` config: whether listener edges count for cycles and depends_on.
     * @return list<Rule>
     */
    public static function fromConfig(array $config = [], ?Closure $make = null, array $listeners = []): array
    {
        $available = [
            InternalAccessRule::ID => static fn (): Rule => new InternalAccessRule,
            UndeclaredDependencyRule::ID => static fn (): Rule => new UndeclaredDependencyRule(($listeners['depends_on'] ?? true) === true),
            CycleRule::ID => static fn (): Rule => new CycleRule(($listeners['cycles'] ?? false) === true),
            UnusedDependencyRule::ID => static fn (): Rule => new UnusedDependencyRule(($listeners['depends_on'] ?? true) === true),
            HostAccessRule::ID => static fn (): Rule => new HostAccessRule,
        ];

        $rules = [];

        foreach ($available as $id => $factory) {
            if (Severity::fromConfig($id, $config[$id] ?? self::DEFAULTS[$id] ?? true) !== Severity::Off) {
                $rules[] = $factory();
            }
        }

        $ids = array_fill_keys(array_keys($available), true);

        foreach ($config as $key => $value) {
            $entry = self::customEntry($key, $value);

            if ($entry === null) {
                continue;
            }

            if ($entry['class'] === null) {
                throw new InvalidArgumentException(self::unknownRule($entry['key']));
            }

            if (Severity::fromConfig($entry['class'], $entry['value']) === Severity::Off) {
                continue;
            }

            $rule = self::custom($entry['class'], $make);

            if (isset($ids[$rule->id()])) {
                throw new InvalidArgumentException(sprintf(
                    'Custom rule [%s] uses the id [%s], which is already taken. Return a unique id from %s::id().',
                    $entry['class'],
                    $rule->id(),
                    $entry['class'],
                ));
            }

            $ids[$rule->id()] = true;
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * The configured severity of each rule in $rules, by rule id.
     *
     * @param  array<array-key, mixed>  $config
     * @param  list<Rule>  $rules  The rules fromConfig() built from the same config.
     * @return array<string, Severity>
     */
    public static function severities(array $config, array $rules): array
    {
        $custom = [];

        foreach ($config as $key => $value) {
            $entry = self::customEntry($key, $value);

            if ($entry !== null && $entry['class'] !== null) {
                $custom[$entry['class']] = $entry['value'];
            }
        }

        $severities = [];

        foreach ($rules as $rule) {
            $severities[$rule->id()] = in_array($rule->id(), self::BUILT_IN, true)
                ? Severity::fromConfig($rule->id(), $config[$rule->id()] ?? self::DEFAULTS[$rule->id()] ?? true)
                : Severity::fromConfig($rule::class, $custom[$rule::class] ?? true);
        }

        return $severities;
    }

    /**
     * Problems in the rules config, in the order they are written, without building any rule.
     *
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    public static function errors(array $config): array
    {
        $errors = [];

        foreach ($config as $key => $value) {
            $entry = self::customEntry($key, $value);

            $error = match (true) {
                $entry === null => self::severityError((string) $key, $value),
                $entry['class'] === null => self::unknownRule($entry['key']),
                ! class_exists($entry['class']) || ! is_subclass_of($entry['class'], Rule::class) => self::notARule($entry['class']),
                default => self::severityError($entry['class'], $entry['value']),
            };

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * A config entry that is not a built-in rule: its class (null when it
     * names no class) and severity value. Null for a built-in rule.
     *
     * @return array{key: string, class: string|null, value: mixed}|null
     */
    private static function customEntry(int|string $key, mixed $value): ?array
    {
        if (is_string($key) && in_array($key, self::BUILT_IN, true)) {
            return null;
        }

        if (is_string($key) && (str_contains($key, '\\') || class_exists($key))) {
            // [MyRule::class => 'warn']
            return ['key' => $key, 'class' => $key, 'value' => $value];
        }

        if (is_string($value) && Severity::tryFrom($value) === null) {
            // [MyRule::class] or ['my_rule' => MyRule::class]
            return ['key' => (string) $key, 'class' => $value, 'value' => true];
        }

        // A severity for a name that is no rule, such as a misspelled built-in id.
        return ['key' => (string) $key, 'class' => null, 'value' => $value];
    }

    private static function severityError(string $rule, mixed $value): ?string
    {
        try {
            Severity::fromConfig($rule, $value);

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    private static function unknownRule(string $key): string
    {
        return sprintf(
            'Unknown rule [%s] in rules.%s Built-in rules: %s.',
            $key,
            ConfigValidator::didYouMean($key, self::BUILT_IN),
            implode(', ', self::BUILT_IN),
        );
    }

    private static function notARule(string $class): string
    {
        return sprintf('Custom rule [%s] must be an existing class that implements %s.', $class, Rule::class);
    }

    /**
     * @param  (Closure(class-string<Rule>): Rule)|null  $make
     */
    private static function custom(string $class, ?Closure $make): Rule
    {
        if (! class_exists($class) || ! is_subclass_of($class, Rule::class)) {
            throw new InvalidArgumentException(self::notARule($class));
        }

        return $make !== null ? $make($class) : new $class;
    }
}
