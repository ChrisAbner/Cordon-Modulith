<?php

declare(strict_types=1);

namespace Cordon\Support;

use Illuminate\Support\Str;

/**
 * The table names Laravel infers, worked out with Laravel's own string
 * helpers so the plural of "category" or "person" is the one Laravel uses:
 * the analysed code is never loaded, only its names are turned into strings.
 *
 * @internal
 */
final class TableNames
{
    /**
     * The table a model uses when it sets no $table: the snake case plural of
     * its class name (Eloquent's Model::getTable()), "order_items" for OrderItem.
     */
    public static function forModel(string $class): string
    {
        $position = strrpos($class, '\\');

        return Str::snake(Str::pluralStudly($position === false ? $class : substr($class, $position + 1)));
    }

    /**
     * The table foreignId('order_id')->constrained() points at when it is
     * given none (ForeignIdColumnDefinition::constrained()): the plural of the
     * column without its "_id" suffix.
     */
    public static function foreignTable(string $column): string
    {
        return Str::plural(Str::beforeLast($column, '_id'));
    }
}
