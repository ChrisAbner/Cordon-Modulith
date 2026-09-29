<?php

declare(strict_types=1);

namespace Cordon\Laravel;

/**
 * Detects an active Xdebug, which makes the analysis several times slower.
 *
 * The inputs can be injected so the logic is testable without Xdebug.
 */
final class XdebugWarning
{
    private readonly bool $loaded;

    private readonly string $mode;

    public function __construct(?bool $loaded = null, ?string $mode = null)
    {
        $this->loaded = $loaded ?? extension_loaded('xdebug');
        $this->mode = strtolower(trim($mode ?? self::detectMode()));
    }

    public function isActive(): bool
    {
        return $this->loaded && ! in_array($this->mode, ['', 'off'], true);
    }

    public function message(): ?string
    {
        return $this->isActive()
            ? 'Xdebug is enabled, which makes the analysis several times slower. Run with XDEBUG_MODE=off to speed it up.'
            : null;
    }

    private static function detectMode(): string
    {
        $env = getenv('XDEBUG_MODE');

        if (is_string($env) && trim($env) !== '') {
            return $env;
        }

        $ini = ini_get('xdebug.mode');

        return is_string($ini) ? $ini : '';
    }
}
