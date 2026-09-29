<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * One usage of a class name inside a file (new, static call, type, extends, attribute...).
 */
final readonly class Reference
{
    public function __construct(
        public string $sourceFile,
        public ?string $sourceClass,
        public string $target,
        public int $line,
    ) {}
}
