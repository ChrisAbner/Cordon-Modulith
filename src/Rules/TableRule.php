<?php

declare(strict_types=1);

namespace Cordon\Rules;

use Cordon\Analysis\AnalysisContext;
use Cordon\Analysis\TableUse;
use Cordon\Analysis\Violation;
use Cordon\Contracts\Rule;

/**
 * A module may not alter, query or point a foreign key at a table that
 * another module creates: the tables of a module are as internal as its
 * classes. A heuristic, off by default: it reads literal table names only
 * (never a variable, a constant or config()), and it does not report a
 * table that no module, or more than one, creates (see TableMap). A
 * foreign key is reported even when depends_on lists the owner: coupling
 * through a table is what the cross-module relations recipe advises against.
 * A nested module may use the tables of its ancestors. One violation per file,
 * table and kind of use.
 *
 * @internal
 */
final class TableRule implements Rule
{
    public const ID = 'tables';

    /** Added to every message. */
    public const HINT = 'Read or change another module\'s data through its public API instead: see https://chrisabner.github.io/Cordon-Modulith/recipes/cross-module-relations';

    public function id(): string
    {
        return self::ID;
    }

    public function check(AnalysisContext $context): iterable
    {
        $reported = [];

        foreach ($context->tables->crossings($context->modules) as ['use' => $use, 'user' => $user, 'owner' => $owner]) {
            $key = $use->file.'|'.$use->kind.'|'.$use->table;

            if (isset($reported[$key])) {
                continue;
            }

            $reported[$key] = true;

            yield new Violation(
                self::ID,
                sprintf(
                    match ($use->kind) {
                        TableUse::ALTER => 'Module [%s] alters table [%s], which belongs to [%s].',
                        TableUse::FOREIGN => 'Module [%s] adds a foreign key to table [%s], which belongs to [%s].',
                        default => 'Module [%s] queries table [%s], which belongs to [%s].',
                    }.' '.self::HINT,
                    $user->name,
                    $use->table,
                    $owner->name,
                ),
                $use->file,
                $use->line,
                $user->name,
                $owner->name,
                $use->table,
            );
        }
    }
}
