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
 * the analysed code, without a parent that sets a table of its own or a
 * getTable() method. Anything else is left out.
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
        $declaring = [];
        $owned = [];

        foreach ($analyses as $analysis) {
            $module = isset($hostFiles[$analysis->file]) ? Module::HOST : $modules->forPath($analysis->file)?->name;

            if ($module === null) {
                continue;
            }

            $owned[] = [$analysis, $module];

            foreach ($analysis->tables as $use) {
                if ($use->class !== null) {
                    $declaring[strtolower($use->class)] = true;
                }
            }
        }

        $creators = [];
        $uses = [];
        $dynamic = [];

        foreach ($owned as [$analysis, $module]) {
            $inModule = $module !== Module::HOST;

            foreach ($analysis->tables as $use) {
                if ($use->kind === TableUse::CREATE) {
                    $creators[$use->table][$module] = true;
                } elseif (! $inModule) {
                    continue;
                } elseif ($use->kind === TableUse::MODEL) {
                    if (self::isModel($use->class, $symbols, $declaring)[0]) {
                        $uses[] = new TableUse($use->file, TableUse::QUERY, $use->table, $use->line, $use->class);
                    }
                } elseif ($use->kind === TableUse::DYNAMIC) {
                    if (self::isModel($use->class, $symbols, $declaring)[0]) {
                        $dynamic[] = $use;
                    }
                } else {
                    $uses[] = $use;
                }
            }

            if (! $inModule) {
                continue;
            }

            foreach ($analysis->declarations as $declaration) {
                if ($declaration->parent === null || isset($declaring[strtolower($declaration->fqcn)])) {
                    continue;
                }

                [$model, $inherited] = self::isModel($declaration->fqcn, $symbols, $declaring);

                if ($model && ! $inherited) {
                    $uses[] = new TableUse($declaration->file, TableUse::QUERY, TableNames::forModel($declaration->fqcn), $declaration->line, $declaration->fqcn);
                }
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
     * Whether a class provably extends a model, and whether a parent in the
     * analysed code sets a table of its own (then the table it inherits is not
     * the class's default one).
     *
     * @param  array<string, true>  $declaring  The classes, lowercase, that set a table or override getTable().
     * @return array{0: bool, 1: bool}
     */
    private static function isModel(?string $class, SymbolTable $symbols, array $declaring): array
    {
        $parent = $class === null ? null : $symbols->get($class)?->parent;
        $inherited = false;
        $seen = [];

        while ($parent !== null) {
            $key = strtolower(ltrim($parent, '\\'));

            if (isset($seen[$key])) {
                return [false, false];
            }

            $seen[$key] = true;

            if (in_array($key, self::MODELS, true)) {
                return [true, $inherited];
            }

            $declaration = $symbols->get($parent);

            if ($declaration === null) {
                return [false, false];
            }

            $inherited = $inherited || isset($declaring[$key]);
            $parent = $declaration->parent;
        }

        return [false, false];
    }
}
