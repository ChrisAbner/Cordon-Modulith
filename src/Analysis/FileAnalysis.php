<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class FileAnalysis
{
    /**
     * @param  list<ClassDeclaration>  $declarations
     * @param  list<Reference>  $references
     * @param  list<IgnoreDirective>  $ignores  Its @cordon-ignore comments.
     * @param  list<TableUse>  $tables  The tables it names; empty unless the extractor was asked for them (the `tables` rule is on).
     */
    public function __construct(
        public string $file,
        public array $declarations = [],
        public array $references = [],
        public ?string $error = null,
        public array $ignores = [],
        public array $tables = [],
    ) {}

    /**
     * The analysis as plain data for the cache. The file path is left out: the
     * cache is keyed by it, and the paths stored must not depend on where the
     * project lives.
     *
     * @internal
     *
     * @return array{declarations: list<array<string, mixed>>, references: list<array<string, mixed>>, error: ?string, ignores: list<array<string, mixed>>, tables: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'declarations' => array_map(static fn (ClassDeclaration $declaration): array => $declaration->toArray(), $this->declarations),
            'references' => array_map(static fn (Reference $reference): array => $reference->toArray(), $this->references),
            'error' => $this->error,
            'ignores' => array_map(static fn (IgnoreDirective $ignore): array => $ignore->toArray(), $this->ignores),
            'tables' => array_map(static fn (TableUse $use): array => $use->toArray(), $this->tables),
        ];
    }

    /**
     * Rebuilds what toArray() wrote for $file. Data that is not in that shape
     * throws a TypeError, ValueError or similar: the cache treats it as a miss.
     *
     * @internal
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $file): self
    {
        return new self(
            $file,
            array_map(static fn (array $declaration): ClassDeclaration => ClassDeclaration::fromArray($declaration, $file), self::list($data['declarations'] ?? null)),
            array_map(static fn (array $reference): Reference => Reference::fromArray($reference, $file), self::list($data['references'] ?? null)),
            $data['error'] ?? null,
            array_map(static fn (array $ignore): IgnoreDirective => IgnoreDirective::fromArray($ignore), self::list($data['ignores'] ?? null)),
            array_map(static fn (array $use): TableUse => TableUse::fromArray($use, $file), self::list($data['tables'] ?? null)),
        );
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Expected a list.');
        }

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Expected a list of objects.');
            }
        }

        return $value;
    }
}
