<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\Module;
use Cordon\Module\ModuleMap;
use Cordon\Support\TableNames;

/**
 * Who owns each database table, and which module touches the tables of
 * another: for the `tables` rule and the module documentation.
 *
 * The owner of a table is the module that creates it with Schema::create().
 * A migration in the code outside the modules (database/migrations) makes the
 * host the owner. A table that two of them create has no single owner, so it
 * is not reported, and neither is a table nobody creates.
 *
 * A model uses the table of its `$table` literal, or Laravel's default name
 * (the plural snake case of its class) when it sets none, but only a class
 * that provably extends Eloquent's Model (or the framework's User) through
 * the analysed code. Its table is unknown, and so it is left out and listed,
 * when the class, a parent or a trait of the project it uses (or a parent's)
 * sets `$table`, overrides getTable(), assigns `$this->table`, calls
 * setTable() or sets `$connection` (another database, so the same name is
 * another table). Traits from packages are assumed not to set a table.
 *
 * @internal
 */
final readonly class TableMap
{
    /** Classes whose table Laravel names after the class, lowercase. */
    private const MODELS = ['illuminate\\database\\eloquent\\model', 'illuminate\\foundation\\auth\\user'];

    /**
     * @param  array<string, list<string>>  $creators  Table => the modules that create it, sorted.
     * @param  list<TableUse>  $uses  What the modules do to a table without creating it (alter, foreign and query), in file and line order.
     * @param  list<TableUse>  $dynamic  Models of the modules whose table is not a literal.
     */
    public function __construct(
        private array $creators = [],
        public array $uses = [],
        private array $dynamic = [],
    ) {}

    /**
     * @param  list<FileAnalysis>  $analyses
     * @param  array<string, true>  $hostFiles  The files of the code outside the modules; the module of any other file is the one that owns its path.
     */
    public static function build(array $analyses, ModuleMap $modules, array $hostFiles, SymbolTable $symbols): self
    {
        // The extractor reads tables only when the rule is on: without any, there is nothing to map (and nothing to pay for).
        foreach ($analyses as $analysis) {
            if ($analysis->tables !== []) {
                return self::map($analyses, $modules, $hostFiles, $symbols);
            }
        }

        return new self;
    }

    /**
     * @param  list<FileAnalysis>  $analyses
     * @param  array<string, true>  $hostFiles
     */
    private static function map(array $analyses, ModuleMap $modules, array $hostFiles, SymbolTable $symbols): self
    {
        /** @var array<string, true> $declaring Classes and traits (lowercase) that set a table or override getTable(). */
        $declaring = [];
        /** @var array<string, true> $connections Classes and traits that set a $connection. */
        $connections = [];
        /** @var array<string, true> $opaque Classes and traits whose table is unknown: a getTable() override, a non-literal $table, an assignment, setTable(), a $table in a trait, a $connection. */
        $opaque = [];
        /** @var array<string, list<string>> $traits The traits each class or trait uses. */
        $traits = [];
        $owned = [];

        foreach ($analyses as $analysis) {
            $module = isset($hostFiles[$analysis->file]) ? Module::HOST : $modules->forPath($analysis->file)?->name;

            if ($module === null) {
                continue;
            }

            $owned[] = [$analysis, $module];

            foreach ($analysis->tables as $use) {
                if ($use->class === null) {
                    continue;
                }

                $key = strtolower($use->class);

                if ($use->kind === TableUse::DYNAMIC || $use->kind === TableUse::CONNECTION) {
                    $opaque[$key] = true;
                }

                match ($use->kind) {
                    TableUse::CONNECTION => $connections[$key] = true,
                    TableUse::TRAIT_USE => $traits[$key][] = strtolower(ltrim($use->table, '\\')),
                    default => $declaring[$key] = true,
                };
            }
        }

        $creators = [];
        $uses = [];
        $unknown = [];
        $models = [];

        foreach ($owned as [$analysis, $module]) {
            $inModule = $module !== Module::HOST;

            foreach ($analysis->tables as $use) {
                if ($use->kind === TableUse::CREATE) {
                    $creators[$use->table][$module] = true;
                } elseif (! $inModule || $use->kind === TableUse::TRAIT_USE) {
                    continue;
                } elseif ($use->kind === TableUse::MODEL) {
                    $models[] = $use;
                } elseif ($use->kind === TableUse::DYNAMIC || $use->kind === TableUse::CONNECTION) {
                    $unknown[strtolower((string) $use->class)][] = $use;
                } else {
                    $uses[] = $use;
                }
            }

            if (! $inModule) {
                continue;
            }

            foreach ($analysis->declarations as $declaration) {
                $trace = $declaration->parent === null ? null : self::trace($declaration->fqcn, $symbols, $traits);

                if ($trace === null) {
                    continue;
                }

                $key = strtolower($declaration->fqcn);

                // A trait of the project that sets the table makes a model's table unknown, whatever else the model says.
                if (! isset($unknown[$key]) && self::any(array_slice($trace['own'], 1), $declaring + $connections)) {
                    $unknown[$key][] = new TableUse($declaration->file, TableUse::DYNAMIC, '', $declaration->line, $declaration->fqcn, 'it uses a trait that sets its table');
                }

                // The default name is Laravel's only when nothing in the class, its traits or its parents can change the table.
                if (! self::any([...$trace['own'], ...$trace['inherited']], $declaring + $connections)) {
                    $uses[] = new TableUse($declaration->file, TableUse::QUERY, TableNames::forModel($declaration->fqcn), $declaration->line, $declaration->fqcn);
                }
            }
        }

        $dynamic = [];

        foreach ($unknown as $key => $found) {
            $trace = self::trace($found[0]->class ?? '', $symbols, $traits);

            if ($trace !== null) {
                usort($found, static fn (TableUse $a, TableUse $b): int => $a->line <=> $b->line);
                $dynamic[] = $found[0];
            }
        }

        foreach ($models as $use) {
            $trace = self::trace($use->class ?? '', $symbols, $traits);

            // A literal $table is only the table when nothing in the class, its traits or its parents can change it.
            if ($trace !== null && ! self::any([...$trace['own'], ...$trace['inherited']], $opaque)) {
                $uses[] = new TableUse($use->file, TableUse::QUERY, $use->table, $use->line, $use->class);
            }
        }

        $names = [];

        foreach ($creators as $table => $modulesOf) {
            $list = array_map('strval', array_keys($modulesOf));
            sort($list);
            $names[(string) $table] = $list;
        }

        ksort($names);
        $compare = static fn (TableUse $a, TableUse $b): int => strcmp($a->file, $b->file) ?: $a->line <=> $b->line ?: strcmp($a->table, $b->table) ?: strcmp($a->kind, $b->kind);
        usort($uses, $compare);
        usort($dynamic, $compare);

        return new self($names, $uses, $dynamic);
    }

    public function isEmpty(): bool
    {
        return $this->creators === [] && $this->uses === [] && $this->dynamic === [];
    }

    /**
     * The module that owns a table: the only one that creates it. Null when nobody does, or several do.
     */
    public function owner(string $table): ?string
    {
        $creators = $this->creators[$table] ?? [];

        return count($creators) === 1 ? $creators[0] : null;
    }

    /**
     * @return list<string> The modules that create a table (the host included).
     */
    public function creators(string $table): array
    {
        return $this->creators[$table] ?? [];
    }

    /**
     * The tables that more than one module creates, with the modules.
     *
     * @return array<string, list<string>>
     */
    public function ownerless(): array
    {
        return array_filter($this->creators, static fn (array $creators): bool => count($creators) > 1);
    }

    /**
     * The tables a module owns, sorted.
     *
     * @return list<string>
     */
    public function ownedBy(string $module): array
    {
        $tables = array_keys(array_filter($this->creators, static fn (array $creators): bool => $creators === [$module]));
        $tables = array_map('strval', $tables);
        sort($tables);

        return $tables;
    }

    /**
     * The models of the modules whose table Cordon cannot know.
     *
     * @return list<TableUse>
     */
    public function dynamic(): array
    {
        return $this->dynamic;
    }

    /**
     * What a module does to the table of another that owns it, once per use:
     * the owner is the one the user sees (a nested module exists only inside
     * its parent). A module may use the tables of its ancestors, as it may use
     * their classes; the host owns tables nobody reports, and the code outside
     * the modules reports nothing.
     *
     * @return list<array{use: TableUse, user: Module, owner: Module}>
     */
    public function crossings(ModuleMap $modules): array
    {
        $crossings = [];

        foreach ($this->uses as $use) {
            $user = $modules->forPath($use->file);
            $owner = ($name = $this->owner($use->table)) === null ? null : $modules->get($name);

            if ($user === null || $owner === null || $owner->name === $user->name || $user->isNestedIn($owner)) {
                continue;
            }

            $crossings[] = ['use' => $use, 'user' => $user, 'owner' => $modules->visible($owner, $user)];
        }

        return $crossings;
    }

    /**
     * Whether any of the classes (lowercase) is in the set.
     *
     * @param  list<string>  $keys
     * @param  array<string, true>  $set
     */
    private static function any(array $keys, array $set): bool
    {
        foreach ($keys as $key) {
            if (isset($set[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What decides the table of a class that provably extends a model through
     * the analysed code (null when it does not): the class and the traits it
     * uses ("own", the class first), and its parents and their traits
     * ("inherited"), all lowercase. A trait of a package is not here: it is
     * assumed not to set a table.
     *
     * @param  array<string, list<string>>  $traits
     * @return array{own: list<string>, inherited: list<string>}|null
     */
    private static function trace(string $class, SymbolTable $symbols, array $traits): ?array
    {
        $own = [strtolower(ltrim($class, '\\'))];
        $inherited = [];
        $parent = $symbols->get($class)?->parent;
        $seen = $own;

        while ($parent !== null) {
            $key = strtolower(ltrim($parent, '\\'));

            if (in_array($key, $seen, true)) {
                return null;
            }

            $seen[] = $key;

            if (in_array($key, self::MODELS, true)) {
                return ['own' => self::withTraits($own, $traits), 'inherited' => self::withTraits($inherited, $traits)];
            }

            $declaration = $symbols->get($parent);

            if ($declaration === null) {
                return null;
            }

            $inherited[] = $key;
            $parent = $declaration->parent;
        }

        return null;
    }

    /**
     * The classes and, after them, the traits they use, and those traits' traits.
     *
     * @param  list<string>  $keys
     * @param  array<string, list<string>>  $traits
     * @return list<string>
     */
    private static function withTraits(array $keys, array $traits): array
    {
        $all = $keys;

        for ($i = 0; $i < count($all); $i++) {
            foreach ($traits[$all[$i]] ?? [] as $trait) {
                if (! in_array($trait, $all, true)) {
                    $all[] = $trait;
                }
            }
        }

        return $all;
    }
}
