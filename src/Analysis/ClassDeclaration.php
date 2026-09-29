<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * A class, interface, trait or enum declared in an analysed file.
 */
final readonly class ClassDeclaration
{
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public DeclaredVisibility $visibility = DeclaredVisibility::Unspecified,
    ) {}
}
