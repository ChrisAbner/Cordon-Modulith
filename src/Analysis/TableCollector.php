<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Support\TableNames;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the database tables a file names, for the `tables` rule: only
 * literal table names count (a name in a variable, a constant or config() is
 * never guessed), and a call counts only when its receiver is certain, so a
 * Collection's join('orders') or an unrelated on('orders') is not a table.
 *
 * Must run after PhpParser's NameResolver: Schema and DB are matched by their
 * resolved names, Illuminate\Support\Facades\* or the global aliases (a
 * namespaced file that imports nothing resolves them to its own namespace,
 * which is no facade).
 *
 * @internal
 */
final class TableCollector extends NodeVisitorAbstract
{
    private const FACADES = 'illuminate\\support\\facades\\';

    /** Schema methods that change a table, by lowercase name. */
    private const ALTERS = ['table', 'drop', 'dropifexists', 'rename'];

    /** Query builder methods whose first argument is a joined table, by lowercase name. */
    private const JOINS = ['join', 'leftjoin', 'rightjoin', 'crossjoin', 'joinwhere', 'leftjoinwhere', 'rightjoinwhere'];

    /** Blueprint methods that start a foreign key, by lowercase name. */
    private const FOREIGN_STARTS = ['foreign', 'foreignid', 'foreignuuid', 'foreignulid', 'foreignidfor'];

    /** The foreignId family: the column name is the first argument, and the default table comes from it. */
    private const FOREIGN_IDS = ['foreignid', 'foreignuuid', 'foreignulid'];

    /** @var list<TableUse> */
    private array $uses = [];

    public function __construct(private readonly string $file) {}

    public function enterNode(Node $node)
    {
        match (true) {
            $node instanceof Node\Expr\StaticCall => $this->staticCall($node),
            $node instanceof Node\Expr\MethodCall => $this->methodCall($node),
            $node instanceof Node\Stmt\Class_ => $this->classTable($node),
            default => null,
        };

        return null;
    }

    /**
     * @return list<TableUse>
     */
    public function uses(): array
    {
        return $this->uses;
    }

    private function staticCall(Node\Expr\StaticCall $call): void
    {
        $method = $this->methodName($call);
        $table = $this->literal($call->args[0] ?? null);

        if ($method === null || $table === null) {
            return;
        }

        $line = $call->name->getStartLine();

        if ($this->isFacade($call->class, 'schema')) {
            if ($method === 'create') {
                $this->add(TableUse::CREATE, $table, $line);
            } elseif (in_array($method, self::ALTERS, true)) {
                $this->add(TableUse::ALTER, $table, $line);
            }
        } elseif ($method === 'table' && $this->isFacade($call->class, 'db')) {
            $this->add(TableUse::QUERY, self::withoutAlias($table), $line);
        }
    }

    private function methodCall(Node\Expr\MethodCall $call): void
    {
        $method = $this->methodName($call);

        if ($method === null) {
            return;
        }

        $line = $call->name->getStartLine();

        if (in_array($method, self::JOINS, true)) {
            $table = $this->literal($call->args[0] ?? null);

            if ($table !== null && $this->startsAtDbTable($call->var)) {
                $this->add(TableUse::QUERY, self::withoutAlias($table), $line);
            }

            return;
        }

        if ($method === 'on') {
            $table = $this->literal($call->args[0] ?? null);

            if ($table !== null && $this->chain($call->var, self::FOREIGN_STARTS) !== null) {
                $this->add(TableUse::FOREIGN, $table, $line);
            }

            return;
        }

        if ($method === 'constrained') {
            $table = $this->constrainedTable($call);

            if ($table !== null) {
                $this->add(TableUse::FOREIGN, $table, $line);
            }
        }
    }

    /**
     * The table of ->constrained(): the one named in its first argument (or
     * `table:`), or, without arguments, the one Laravel infers from the
     * column of foreignId(), foreignUuid() or foreignUlid(). Null when it is
     * not certain: a table in a variable, a column argument, foreignIdFor().
     */
    private function constrainedTable(Node\Expr\MethodCall $call): ?string
    {
        $start = $this->chain($call->var, self::FOREIGN_STARTS);

        if ($start === null) {
            return null;
        }

        foreach ($call->args as $index => $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack) {
                return null;
            }

            $name = $arg->name?->toString();

            if ($name === 'table' || ($name === null && $index === 0)) {
                return $this->literal($arg);
            }

            // Another argument (a column) changes what Laravel would infer.
            return null;
        }

        return in_array($this->methodName($start), self::FOREIGN_IDS, true) && ($column = $this->literal($start->args[0] ?? null)) !== null
            ? TableNames::foreignTable($column)
            : null;
    }

    /**
     * A class that sets its table: `protected $table = 'orders'` is a model's
     * table; any other value, or a getTable() method, leaves it unknown.
     * Whether the class is a model is decided by TableMap, from its parents.
     */
    private function classTable(Node\Stmt\Class_ $class): void
    {
        if ($class->extends === null || ! isset($class->namespacedName)) {
            return;
        }

        $name = $class->namespacedName->toString();

        if ($class->getMethod('getTable') !== null) {
            $this->uses[] = new TableUse($this->file, TableUse::DYNAMIC, '', $class->getStartLine(), $name, 'it overrides getTable()');

            return;
        }

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== 'table' || $property->isStatic() || $item->default === null) {
                    continue;
                }

                if ($item->default instanceof Node\Scalar\String_ && $item->default->value !== '') {
                    $this->uses[] = new TableUse($this->file, TableUse::MODEL, $item->default->value, $item->getStartLine(), $name);
                } elseif (! ($item->default instanceof Node\Expr\ConstFetch && strtolower($item->default->name->toString()) === 'null')) {
                    $this->uses[] = new TableUse($this->file, TableUse::DYNAMIC, '', $item->getStartLine(), $name, 'its $table is not a literal');
                }
            }
        }
    }

    /**
     * Whether a chain of calls starts at DB::table('...'): a joined table
     * counts only on the query builder, never on a Collection or a model.
     */
    private function startsAtDbTable(Node\Expr $expression): bool
    {
        while ($expression instanceof Node\Expr\MethodCall) {
            $expression = $expression->var;
        }

        return $expression instanceof Node\Expr\StaticCall
            && $this->methodName($expression) === 'table'
            && $this->isFacade($expression->class, 'db')
            && $this->literal($expression->args[0] ?? null) !== null;
    }

    /**
     * The first call in a chain, going down its receivers, that is one of $names.
     *
     * @param  list<string>  $names  Lowercase method names.
     */
    private function chain(Node\Expr $expression, array $names): ?Node\Expr\MethodCall
    {
        while ($expression instanceof Node\Expr\MethodCall) {
            if (in_array($this->methodName($expression), $names, true)) {
                return $expression;
            }

            $expression = $expression->var;
        }

        return null;
    }

    private function isFacade(Node $class, string $facade): bool
    {
        return $class instanceof Node\Name && in_array(strtolower($class->toString()), [self::FACADES.$facade, $facade], true);
    }

    private function methodName(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? $call->name->toLowerString() : null;
    }

    private function literal(?Node $argument): ?string
    {
        if (! $argument instanceof Node\Arg || $argument->unpack || $argument->name !== null && $argument->name->toString() !== 'table') {
            return null;
        }

        return $argument->value instanceof Node\Scalar\String_ && $argument->value->value !== '' ? $argument->value->value : null;
    }

    /**
     * "orders as o" is the table orders.
     */
    private static function withoutAlias(string $table): string
    {
        return (preg_split('/\s+as\s+/i', trim($table)) ?: [$table])[0];
    }

    /**
     * @param  TableUse::CREATE|TableUse::ALTER|TableUse::FOREIGN|TableUse::QUERY  $kind
     */
    private function add(string $kind, string $table, int $line): void
    {
        $this->uses[] = new TableUse($this->file, $kind, $table, $line);
    }
}
