<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * A database table named in an analysed file, read from literals only: a
 * table name in a variable, a constant or a config() call is never guessed.
 *
 * @internal
 */
final readonly class TableUse
{
    /** Schema::create('t'): the module of the file creates the table, and so owns it. */
    public const CREATE = 'create';

    /** Schema::table('t'), drop(), dropIfExists() and rename(): the table is changed. */
    public const ALTER = 'alter';

    /** A foreign key to the table: ->on('t'), ->constrained('t') and constrained() with the table Laravel infers. */
    public const FOREIGN = 'foreign';

    /** DB::table('t') and its joins, or a model whose table is 't'. */
    public const QUERY = 'query';

    /** A class that sets `protected $table = 't'`; only a model counts, which TableMap decides. */
    public const MODEL = 'model';

    /** A class whose table is not a literal (`$table = self::NAME`, getTable()): its table is unknown. */
    public const DYNAMIC = 'dynamic';

    /**
     * @param  self::CREATE|self::ALTER|self::FOREIGN|self::QUERY|self::MODEL|self::DYNAMIC  $kind
     * @param  string  $table  The table; empty for a dynamic one.
     * @param  ?string  $class  The class that sets its table (MODEL and DYNAMIC).
     * @param  ?string  $reason  Why the table is unknown (DYNAMIC).
     */
    public function __construct(
        public string $file,
        public string $kind,
        public string $table,
        public int $line,
        public ?string $class = null,
        public ?string $reason = null,
    ) {}

    /**
     * @internal
     *
     * @return array{kind: string, table: string, line: int, class: ?string, reason: ?string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'table' => $this->table, 'line' => $this->line, 'class' => $this->class, 'reason' => $this->reason];
    }

    /**
     * @internal
     *
     * @param  array<array-key, mixed>  $data  What toArray() wrote; anything else throws.
     */
    public static function fromArray(array $data, string $file): self
    {
        return new self(
            $file,
            $data['kind'] ?? null,
            $data['table'] ?? null,
            $data['line'] ?? null,
            $data['class'] ?? null,
            $data['reason'] ?? null,
        );
    }
}
