<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * A class, interface, trait or enum declared in an analysed file.
 *
 * @api
 */
final readonly class ClassDeclaration
{
    /**
     * @param  string|null  $parent  The class it extends, for classes.
     * @param  list<string>  $interfaces  The interfaces it implements (or extends, for an interface).
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public DeclaredVisibility $visibility = DeclaredVisibility::Unspecified,
        public ?string $parent = null,
        public array $interfaces = [],
    ) {}
}
