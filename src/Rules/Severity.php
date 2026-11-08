<?php

declare(strict_types=1);

namespace Cordon\Rules;

use InvalidArgumentException;

/**
 * How a rule's violations count: errors fail the check, warnings are only
 * reported, and an off rule does not run.
 *
 * @api
 */
enum Severity: string
{
    case Error = 'error';
    case Warn = 'warn';
    case Off = 'off';

    /**
     * Reads a value from the `rules` config. true and false are kept as
     * aliases of error and off, the values before severities existed.
     */
    public static function fromConfig(string $rule, mixed $value): self
    {
        return match (true) {
            $value === true => self::Error,
            $value === false => self::Off,
            is_string($value) && self::tryFrom($value) !== null => self::from($value),
            default => throw new InvalidArgumentException(sprintf(
                'Rule [%s] has an invalid value [%s]. Use error, warn or off (or true/false).',
                $rule,
                is_scalar($value) ? (string) $value : get_debug_type($value),
            )),
        };
    }
}
