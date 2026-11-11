<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use InvalidArgumentException;

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

    /**
     * @internal
     *
     * @return array{fqcn: string, line: int, visibility: string, parent: ?string, interfaces: list<string>}
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'line' => $this->line,
            'visibility' => $this->visibility->value,
            'parent' => $this->parent,
            'interfaces' => $this->interfaces,
        ];
    }

    /**
     * @internal
     *
     * @param  array<array-key, mixed>  $data  What toArray() wrote; anything else throws.
     */
    public static function fromArray(array $data, string $file): self
    {
        $interfaces = $data['interfaces'] ?? null;

        if (! is_array($interfaces) || ! array_is_list($interfaces)) {
            throw new InvalidArgumentException('Expected a list of interfaces.');
        }

        return new self(
            $data['fqcn'] ?? null,
            $file,
            $data['line'] ?? null,
            DeclaredVisibility::from($data['visibility'] ?? null),
            $data['parent'] ?? null,
            array_map(static fn (mixed $interface): string => is_string($interface) ? $interface : throw new InvalidArgumentException('Expected an interface name.'), $interfaces),
        );
    }
}
