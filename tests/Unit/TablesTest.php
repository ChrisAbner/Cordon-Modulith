<?php

use Cordon\Analysis\AnalysisCache;
use Cordon\Analysis\Analyzer;
use Cordon\Analysis\CachedExtractor;
use Cordon\Analysis\FileAnalysis;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Result;
use Cordon\Analysis\TableCollector;
use Cordon\Analysis\TableUse;
use Cordon\Analysis\Violation;
use Cordon\Baseline\Baseline;
use Cordon\Contracts\DependencyExtractor;
use Cordon\Documentation\DocumentationGenerator;
use Cordon\Documentation\EventInventory;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Resolvers\NestedResolver;
use Cordon\Rules\RuleSet;
use Cordon\Rules\Severity;
use Cordon\Support\TableNames;

const TABLES_HINT = ' Read or change another module\'s data through its public API instead: see https://chrisabner.github.io/Cordon-Modulith/recipes/cross-module-relations';

/**
 * tests/Fixtures/tables-app (modules Billing, Catalog, Orders, and migrations in database/migrations) analysed with the tables rule.
 *
 * @param  array<array-key, mixed>  $rules
 * @param  array<string, mixed>  $modules
 * @param  list<string>|null  $hostPaths  Relative to the root; the code outside the modules.
 */
function analyse_tables_app(array $rules = ['tables' => 'warn'], ?string $root = null, array $modules = [], ?array $hostPaths = ['app', 'database'], bool $tables = true): Result
{
    $root ??= fixture_path('tables-app');
    $map = (new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve()->configure($modules);

    return tables_analyzer($rules, $tables)->analyze($map, $root, array_map(fn (string $path) => $root.'/'.$path, $hostPaths ?? []));
}

/**
 * @param  array<array-key, mixed>  $rules
 */
function tables_analyzer(array $rules = ['tables' => 'warn'], bool $tables = true, ?DependencyExtractor $extractor = null): Analyzer
{
    $built = RuleSet::fromConfig($rules);

    return new Analyzer($extractor ?? new PhpParserExtractor(tables: $tables), new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));
}

/**
 * "file:line message" of each violation, relative to the root.
 *
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function table_findings(array $violations, ?string $root = null): array
{
    $root ??= fixture_path('tables-app');
    $found = array_map(
        fn (Violation $violation) => substr((string) $violation->file, strlen($root) + 1).':'.$violation->line.' '.$violation->message,
        array_values(array_filter($violations, fn (Violation $violation) => $violation->rule === 'tables')),
    );

    sort($found);

    return $found;
}

/**
 * A project in a temporary directory with the given files (path => contents).
 *
 * @param  array<string, string>  $files
 */
function tables_project(array $files): string
{
    $root = temp_project();

    foreach ($files as $path => $code) {
        is_dir(dirname($root.'/'.$path)) || mkdir(dirname($root.'/'.$path), 0777, true);
        file_put_contents($root.'/'.$path, $code);
    }

    return $root;
}

function migration(string $body): string
{
    return "<?php\n\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nSchema::create('x', function (Blueprint \$table) {\n});\n".$body;
}

function model(string $namespace, string $class, string $extends = 'Model', string $body = '', string $use = 'use Illuminate\\Database\\Eloquent\\Model;'): string
{
    return "<?php\n\nnamespace {$namespace};\n\n{$use}\n\nclass {$class} extends {$extends}\n{\n{$body}\n}\n";
}

// off by default

it('does nothing unless the tables rule is turned on', function () {
    $off = analyse_tables_app([], tables: false);
    $explicit = analyse_tables_app(['tables' => 'off'], tables: false);

    expect($off->warnings)->toBe([])->and($off->violations)->toBe([])
        ->and($explicit->warnings)->toBe([])->and($explicit->violations)->toBe([])
        ->and(array_map(fn ($rule) => $rule->id(), RuleSet::fromConfig()))->not->toContain('tables')
        ->and(RuleSet::build()[1])->not->toHaveKey('tables');
});

it('knows tables as a built-in rule that can be turned on', function () {
    $rules = RuleSet::fromConfig(['tables' => 'warn']);

    expect(array_map(fn ($rule) => $rule->id(), $rules))->toBe(['internal_access', 'undeclared_dependency', 'cycles', 'unused_dependency', 'host_access', 'layers', 'public_api_leak', 'external_dependency', 'tables'])
        ->and(RuleSet::severities(['tables' => 'warn'], $rules)['tables'])->toBe(Severity::Warn)
        ->and(RuleSet::errors(['tables' => 'warn']))->toBe([])
        ->and(RuleSet::errors(['tables' => 'loud']))->toBe(['Rule [tables] has an invalid value [loud]. Use error, warn or off (or true/false).']);
});

it('reports the tables of other modules as warnings', function () {
    $result = analyse_tables_app();

    expect(table_findings($result->warnings))->toBe([
        'app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:13 Module [Billing] adds a foreign key to table [orders], which belongs to [Orders].'.TABLES_HINT,
        'app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:15 Module [Billing] adds a foreign key to table [products], which belongs to [Catalog].'.TABLES_HINT,
        'app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:16 Module [Billing] adds a foreign key to table [categories], which belongs to [Catalog].'.TABLES_HINT,
        'app/Modules/Billing/Database/Migrations/2024_02_02_000000_add_total_to_orders_table.php:11 Module [Billing] alters table [orders], which belongs to [Orders].'.TABLES_HINT,
        'app/Modules/Billing/Models/Receipt.php:9 Module [Billing] queries table [order_items], which belongs to [Orders].'.TABLES_HINT,
        'app/Modules/Billing/Services/InvoiceReport.php:17 Module [Billing] queries table [orders], which belongs to [Orders].'.TABLES_HINT,
        'app/Modules/Billing/Services/InvoiceReport.php:18 Module [Billing] queries table [products], which belongs to [Catalog].'.TABLES_HINT,
    ])->and($result->violations)->toBe([]);
});

it('fills the violation like the other rules do', function () {
    $violation = array_values(array_filter(analyse_tables_app()->warnings, fn (Violation $v) => $v->line === 13))[0];

    expect($violation->rule)->toBe('tables')
        ->and($violation->sourceModule)->toBe('Billing')
        ->and($violation->targetModule)->toBe('Orders')
        ->and($violation->target)->toBe('orders')
        ->and($violation->severity)->toBe(Severity::Warn)
        ->and($violation->docsUrl())->toEndWith('/guide/rules#tables');
});

it('reports errors when the rule is an error', function () {
    $result = analyse_tables_app(['tables' => 'error']);

    expect(count(table_findings($result->violations)))->toBe(7)->and($result->warnings)->toBe([]);
});

it('reports a foreign key only once per file and table', function () {
    $root = tables_project([
        'app/Modules/Orders/Models/Order.php' => model('App\\Modules\\Orders\\Models', 'Order'),
        'app/Modules/Orders/Database/Migrations/a.php' => migration('Schema::create(\'orders\', fn () => null);'),
        'app/Modules/Billing/Database/Migrations/b.php' => "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nSchema::table('invoices', function (\$table) {\n    \$table->foreignId('order_id')->constrained();\n    \$table->foreignId('order_id')->constrained('orders');\n    \$table->foreign('order_id')->references('id')->on('orders');\n});\n",
    ]);

    expect(table_findings(analyse_tables_app(root: $root, hostPaths: [])->warnings, $root))->toBe([
        'app/Modules/Billing/Database/Migrations/b.php:6 Module [Billing] adds a foreign key to table [orders], which belongs to [Orders].'.TABLES_HINT,
    ]);
});

// Review Focus 2: a table without a single owner

it('does not report a table that two modules create, nor a model with a dynamic $table', function () {
    $result = analyse_tables_app();
    $lines = array_map(fn (Violation $v) => $v->target, $result->warnings);

    // audit_log is created by Billing and Catalog and queried from Orders; Tenant sets its table from a constant.
    expect($lines)->not->toContain('audit_log')->not->toContain('tenants');
});

it('has no owner for a table two modules create, and lists why', function () {
    $snapshot = tables_analyzer()->snapshot((new NamespaceResolver(fixture_path('tables-app/app/Modules'), 'App\\Modules'))->resolve(), [fixture_path('tables-app/database')]);
    $tables = $snapshot->context->tables;

    expect($tables->owner('audit_log'))->toBeNull()
        ->and($tables->creators('audit_log'))->toBe(['Billing', 'Catalog'])
        ->and($tables->ownerless())->toBe(['audit_log' => ['Billing', 'Catalog']])
        ->and($tables->owner('orders'))->toBe('Orders')
        ->and($tables->owner('users'))->toBe('host')
        ->and($tables->owner('nothing'))->toBeNull()
        ->and($tables->ownedBy('Catalog'))->toBe(['categories', 'products'])
        ->and(array_map(fn (TableUse $use) => [$use->class, $use->reason], $tables->dynamic()))->toBe([
            ['App\\Modules\\Orders\\Models\\Tenant', 'its $table is not a literal'],
        ]);
});

it('does not report a table that a module and the host both create', function () {
    $root = tables_project([
        'app/Modules/Orders/Database/Migrations/a.php' => migration("Schema::create('orders', fn () => null);"),
        'app/Modules/Billing/Services/Report.php' => "<?php\n\nnamespace App\\Modules\\Billing\\Services;\n\nuse Illuminate\\Support\\Facades\\DB;\n\nDB::table('orders')->get();\n",
        'database/migrations/a.php' => migration("Schema::create('orders', fn () => null);"),
    ]);

    expect(table_findings(analyse_tables_app(root: $root, hostPaths: ['database'])->warnings, $root))->toBe([]);
});

// Review Focus 3: constrained() without a table name

it('infers the table of constrained() like Laravel does', function (string $column, string $table) {
    expect(TableNames::foreignTable($column))->toBe($table);
})->with([
    'plain' => ['order_id', 'orders'],
    'irregular plural' => ['category_id', 'categories'],
    'person' => ['person_id', 'people'],
    'status' => ['status_id', 'statuses'],
    'only the last _id goes' => ['parent_order_id', 'parent_orders'],
    'without the suffix' => ['owner', 'owners'],
    'uppercase kept' => ['Order_id', 'Orders'],
]);

it('names the default table of a model like Laravel does', function (string $class, string $table) {
    expect(TableNames::forModel($class))->toBe($table);
})->with([
    'plain' => ['Order', 'orders'],
    'studly' => ['OrderItem', 'order_items'],
    'irregular' => ['Person', 'people'],
    'namespaced' => ['App\\Modules\\Billing\\Models\\InvoiceLine', 'invoice_lines'],
]);

it('reports constrained() only when its table is certain', function () {
    $found = table_findings(analyse_tables_app()->warnings);
    $invoices = array_values(array_filter($found, fn (string $line) => str_contains($line, 'create_invoices_table')));

    // line 13 (inferred orders), 15 (on), 16 (inferred categories); 17 and 18 are users, which the host owns;
    // 19 foreignIdFor() and 20 constrained($variable) name no table Cordon can know.
    expect(array_map(fn (string $line) => explode(' ', $line)[0], $invoices))->toBe([
        'app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:13',
        'app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:15',
        'app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php:16',
    ]);
});

it('does not guess a table from constrained() when it cannot be known', function (string $call) {
    $root = tables_project([
        'app/Modules/Orders/Database/Migrations/a.php' => migration("Schema::create('orders', fn () => null);Schema::create('people', fn () => null);"),
        'app/Modules/Billing/Database/Migrations/b.php' => "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nSchema::table('invoices', function (\$table) {\n    {$call}\n});\n",
    ]);

    expect(table_findings(analyse_tables_app(root: $root, hostPaths: [])->warnings, $root))->toBe([]);
})->with([
    'foreignIdFor' => ['$table->foreignIdFor(App\\Models\\Order::class)->constrained();'],
    'a variable name' => ['$table->foreignId($name)->constrained();'],
    'a variable table' => ['$table->foreignId(\'order_id\')->constrained($target);'],
    'a column argument' => ['$table->foreignId(\'order_id\')->constrained(column: \'uuid\');'],
    'null as the table' => ['$table->foreignId(\'order_id\')->constrained(null, \'id\');'],
    'foreign without references' => ['$table->foreign(\'order_id\');'],
    'on without a foreign key' => ['$query->on(\'orders\');'],
    'constrained on something else' => ['$builder->constrained(\'orders\');'],
]);

it('reads the other foreign key forms', function (string $call) {
    $root = tables_project([
        'app/Modules/Orders/Database/Migrations/a.php' => migration("Schema::create('orders', fn () => null);Schema::create('people', fn () => null);"),
        'app/Modules/Billing/Database/Migrations/b.php' => "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nSchema::table('invoices', function (\$table) {\n    {$call}\n});\n",
    ]);

    expect(table_findings(analyse_tables_app(root: $root, hostPaths: [])->warnings, $root))->toHaveCount(1);
})->with([
    'foreignUuid' => ['$table->foreignUuid(\'order_id\')->constrained();'],
    'foreignUlid' => ['$table->foreignUlid(\'order_id\')->constrained();'],
    'foreignId with modifiers' => ['$table->foreignId(\'order_id\')->nullable()->index()->constrained()->cascadeOnDelete();'],
    'a table for foreignIdFor' => ['$table->foreignIdFor(App\\Models\\Order::class)->constrained(\'orders\');'],
    'a table in the first argument' => ['$table->foreignId(\'buyer_id\')->constrained(\'orders\', \'id\');'],
    'a named table' => ['$table->foreignId(\'buyer_id\')->constrained(table: \'orders\');'],
    'on first' => ['$table->foreign(\'order_id\')->on(\'orders\')->references(\'id\');'],
    'foreignId references on' => ['$table->foreignId(\'order_id\')->references(\'id\')->on(\'orders\');'],
    'an irregular plural' => ['$table->foreignId(\'person_id\')->constrained();'],
]);

// the other table uses

it('reads the table of every Schema call and of DB::table chains only', function () {
    $code = <<<'PHP'
<?php

namespace App\Modules\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schema::create('a1', fn () => null);
Schema::table('a2', fn () => null);
Schema::drop('a3');
Schema::dropIfExists('a4');
Schema::rename('a5', 'a6');
Schema::hasTable('a7');
Schema::connection('x')->create('a8', fn () => null);
Schema::table($name, fn () => null);
Schema::create(self::NAME, fn () => null);
Schema::create('', fn () => null);
Other::create('b1');
DB::table('c1')->get();
DB::table('c2 as t')->join('c3 as j', 'a', 'b')->leftJoin('c4', 'a', 'b')->rightJoin('c5', 'a', 'b')->crossJoin('c6');
DB::table('c7')->join($other, 'a', 'b')->join(fn () => null);
DB::table('c8')->where('x', 1)->joinWhere('c9', 'a', '=', 'b');
DB::table($name)->join('d1', 'a', 'b');
DB::connection('x')->table('d2');
$collection->join('d3', ', ');
$query->join('d4', 'a', 'b');
User::query()->join('d5', 'a', 'b');
DB::table('e1')->{$method}()->join('e2', 'a', 'b');
PHP;
    $file = tables_project(['Report.php' => $code]).'/Report.php';
    $uses = array_map(fn (TableUse $use) => $use->kind.' '.$use->table, (new PhpParserExtractor(tables: true))->extract($file)->tables);

    sort($uses);

    expect($uses)->toBe([
        'alter a2', 'alter a3', 'alter a4', 'alter a5', 'create a1',
        'query c1', 'query c2', 'query c3', 'query c4', 'query c5', 'query c6',
        'query c7', 'query c8', 'query c9', 'query e1',
    ]);
});

it('resolves Schema and DB like PHP does', function (string $header, int $count) {
    $file = tables_project(['R.php' => $header."\nSchema::create('a', fn () => null);\nDB::table('b')->get();\n"]).'/R.php';

    expect(count((new PhpParserExtractor(tables: true))->extract($file)->tables))->toBe($count);
})->with([
    'imported facades' => ["<?php\n\nnamespace App;\n\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Schema;\n", 2],
    'the global aliases without a namespace' => ["<?php\n", 2],
    'imports of the global aliases' => ["<?php\n\nnamespace App;\n\nuse DB;\nuse Schema;\n", 2],
    'a namespace without imports' => ["<?php\n\nnamespace App;\n", 0],
    'an import of another class' => ["<?php\n\nnamespace App;\n\nuse Other\\Schema;\nuse Other\\DB;\n", 0],
    'an aliased import' => ["<?php\n\nnamespace App;\n\nuse Illuminate\\Support\\Facades\\Schema as S;\nuse Illuminate\\Support\\Facades\\DB as Database;\n", 0],
]);

it('reads the table of a model: $table, or the default name', function () {
    $root = tables_project([
        'app/Modules/Orders/Database/Migrations/a.php' => migration("Schema::create('orders', fn () => null);Schema::create('order_items', fn () => null);Schema::create('people', fn () => null);Schema::create('custom', fn () => null);Schema::create('base_things', fn () => null);Schema::create('users', fn () => null);Schema::create('things', fn () => null);Schema::create('mixed', fn () => null);Schema::create('pivots', fn () => null);Schema::create('roles', fn () => null);Schema::create('articles', fn () => null);"),
        'app/Modules/Billing/Models/Order.php' => model('App\\Modules\\Billing\\Models', 'Order'),
        'app/Modules/Billing/Models/OrderItem.php' => model('App\\Modules\\Billing\\Models', 'OrderItem'),
        'app/Modules/Billing/Models/Person.php' => model('App\\Modules\\Billing\\Models', 'Person'),
        'app/Modules/Billing/Models/Renamed.php' => model('App\\Modules\\Billing\\Models', 'Renamed', body: "    protected \$table = 'custom';"),
        'app/Modules/Billing/Models/Thing.php' => model('App\\Modules\\Billing\\Models', 'Thing', 'BaseModel', use: 'use App\\Modules\\Billing\\Models\\BaseModel;'),
        'app/Modules/Billing/Models/BaseModel.php' => model('App\\Modules\\Billing\\Models', 'BaseModel'),
        'app/Modules/Billing/Models/Sub.php' => model('App\\Modules\\Billing\\Models', 'Sub', 'Parented'),
        'app/Modules/Billing/Models/Parented.php' => model('App\\Modules\\Billing\\Models', 'Parented', body: "    protected \$table = 'mixed';"),
        'app/Modules/Billing/Models/Method.php' => model('App\\Modules\\Billing\\Models', 'Method', body: "    public function getTable(): string\n    {\n        return 'users';\n    }"),
        'app/Modules/Billing/Models/Role.php' => model('App\\Modules\\Billing\\Models', 'Role', 'SpatieRole', use: 'use Spatie\\Permission\\Models\\Role as SpatieRole;'),
        'app/Modules/Billing/Models/Article.php' => model('App\\Modules\\Billing\\Models', 'Article', 'Pivot', use: 'use Illuminate\\Database\\Eloquent\\Relations\\Pivot;'),
        'app/Modules/Billing/Models/User.php' => model('App\\Modules\\Billing\\Models', 'User', 'Authenticatable', use: 'use Illuminate\\Foundation\\Auth\\User as Authenticatable;'),
        'app/Modules/Billing/Services/Plain.php' => model('App\\Modules\\Billing\\Services', 'Plain', 'Service', "    protected \$table = 'users';", 'use Other\\Service;'),
        'app/Modules/Billing/Services/NoParent.php' => "<?php\n\nnamespace App\\Modules\\Billing\\Services;\n\nclass NoParent\n{\n    protected \$table = 'orders';\n}\n",
        'app/Modules/Billing/Services/Cart.php' => model('App\\Modules\\Billing\\Services', 'Cart', 'Eloquent', '    protected $table;', 'use Illuminate\\Database\\Eloquent\\Model as Eloquent;'),
    ]);
    $messages = array_map(
        fn (Violation $v) => basename((string) $v->file, '.php').' -> '.$v->target,
        analyse_tables_app(root: $root, hostPaths: [])->warnings,
    );
    sort($messages);

    expect($messages)->toBe([
        'Order -> orders',
        'OrderItem -> order_items',
        'Parented -> mixed',
        'Person -> people',
        'Renamed -> custom',
        'Thing -> things',
        'User -> users',
    ]);
});

it('does not report a model whose table is dynamic or overridden, and lists it', function () {
    $root = tables_project([
        'app/Modules/Orders/Database/Migrations/a.php' => migration("Schema::create('orders', fn () => null);Schema::create('users', fn () => null);"),
        'app/Modules/Billing/Models/Order.php' => model('App\\Modules\\Billing\\Models', 'Order', body: "    protected \$table = self::NAME;\n    const NAME = 'orders';"),
        'app/Modules/Billing/Models/User.php' => model('App\\Modules\\Billing\\Models', 'User', body: "    public function getTable(): string\n    {\n        return 'users';\n    }"),
        'app/Modules/Billing/Models/Other.php' => model('App\\Modules\\Billing\\Models', 'Other', 'Thing', body: '    protected $table = $x;', use: 'use Other\\Thing;'),
    ]);
    $snapshot = tables_analyzer()->snapshot((new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve());

    expect(table_findings(analyse_tables_app(root: $root, hostPaths: [])->warnings, $root))->toBe([])
        ->and(array_map(fn (TableUse $use) => $use->class.': '.$use->reason, $snapshot->context->tables->dynamic()))->toBe([
            'App\\Modules\\Billing\\Models\\Order: its $table is not a literal',
            'App\\Modules\\Billing\\Models\\User: it overrides getTable()',
        ]);
});

// default table inference: only when nothing can change the table

/**
 * Orders creates `orders`; the given Billing files are analysed with the tables rule.
 *
 * @param  array<string, string>  $files  Relative to app/Modules/Billing.
 * @return array{0: list<string>, 1: list<string>} "Class -> table" of each report, and "Class: reason" of each model listed without a table.
 */
function inferred_tables(array $files): array
{
    $project = ['app/Modules/Orders/Database/Migrations/a.php' => migration("Schema::create('orders', fn () => null);")];

    foreach ($files as $path => $code) {
        $project['app/Modules/Billing/'.$path] = $code;
    }

    $root = tables_project($project);
    $snapshot = tables_analyzer()->snapshot((new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve());
    $reported = array_map(fn (Violation $v) => basename((string) $v->file, '.php').' -> '.$v->target, analyse_tables_app(root: $root, hostPaths: [])->warnings);
    $listed = array_map(fn (TableUse $use) => basename(str_replace('\\', '/', (string) $use->class)).': '.$use->reason, $snapshot->context->tables->dynamic());
    sort($reported);
    sort($listed);

    return [$reported, $listed];
}

function trait_file(string $name, string $body, string $use = ''): string
{
    return "<?php\n\nnamespace App\\Modules\\Billing\\Concerns;\n\n{$use}\n\ntrait {$name}\n{\n{$body}\n}\n";
}

const BILLING_MODELS = 'App\\Modules\\Billing\\Models';
const BILLING_CONCERNS = 'use App\\Modules\\Billing\\Concerns\\Tabled;';

it('does not infer the default table of a model that uses a trait of the project that declares $table', function () {
    [$reported, $listed] = inferred_tables([
        'Concerns/Tabled.php' => trait_file('Tabled', "    protected \$table = 'legacy_orders';"),
        'Models/Order.php' => model(BILLING_MODELS, 'Order', body: '    use \\App\\Modules\\Billing\\Concerns\\Tabled;'),
    ]);

    expect($reported)->toBe([])->and($listed)->toBe(['Order: it uses a trait that sets its table']);
});

it('follows traits that use traits, and the traits of a parent', function () {
    [$reported] = inferred_tables([
        'Concerns/Tabled.php' => trait_file('Tabled', "    protected \$table = 'legacy_orders';"),
        'Concerns/Wrapper.php' => trait_file('Wrapper', '    use Tabled;'),
        'Models/Base.php' => model(BILLING_MODELS, 'Base', body: '    use \\App\\Modules\\Billing\\Concerns\\Wrapper;'),
        'Models/Order.php' => model(BILLING_MODELS, 'Order', 'Base', use: ''),
    ]);

    expect($reported)->toBe([]);
});

it('does not infer the default table when a trait assigns it', function () {
    [$reported] = inferred_tables([
        'Concerns/Tabled.php' => trait_file('Tabled', "    public function initializeTabled(): void\n    {\n        \$this->table = 'legacy_orders';\n    }"),
        'Models/Order.php' => model(BILLING_MODELS, 'Order', body: '    use \\App\\Modules\\Billing\\Concerns\\Tabled;'),
    ]);

    expect($reported)->toBe([]);
});

it('does not infer the default table of a model that assigns it or calls setTable()', function (string $body) {
    [$reported, $listed] = inferred_tables([
        'Models/Order.php' => model(BILLING_MODELS, 'Order', body: $body),
    ]);

    expect($reported)->toBe([])->and($listed)->toBe(['Order: it assigns its table in code']);
})->with([
    'the constructor' => ["    public function __construct()\n    {\n        \$this->table = 'legacy_orders';\n    }"],
    'another method' => ["    public function swap(): void\n    {\n        \$this->table = config('tables.orders');\n    }"],
    'setTable' => ["    public function swap(): void\n    {\n        \$this->setTable('legacy_orders');\n    }"],
    'setTable on another receiver' => ["    public static function swap(self \$other): void\n    {\n        \$other->setTable('legacy_orders');\n    }"],
]);

it('does not infer the default table of a model with a $connection, nor read its literal $table', function (string $body, array $extra) {
    [$reported, $listed] = inferred_tables([...$extra, 'Models/Order.php' => model(BILLING_MODELS, 'Order', $extra === [] ? 'Model' : 'Base', body: $body, use: $extra === [] ? 'use Illuminate\\Database\\Eloquent\\Model;' : '')]);

    expect($reported)->toBe([]);
})->with([
    'its own' => ["    protected \$connection = 'legacy';", []],
    'its own with a literal table' => ["    protected \$connection = 'legacy';\n    protected \$table = 'orders';", []],
    'a parent' => ['', ['Models/Base.php' => "<?php\n\nnamespace App\\Modules\\Billing\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Base extends Model\n{\n    protected \$connection = 'legacy';\n}\n"]],
    'a trait' => ['    use \\App\\Modules\\Billing\\Concerns\\Tabled;', ['Concerns/Tabled.php' => '<?php

namespace App\\Modules\\Billing\\Concerns;

trait Tabled
{
    protected $connection = \'legacy\';
}
', 'Models/Base.php' => "<?php\n\nnamespace App\\Modules\\Billing\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Base extends Model {}\n"]],
]);

it('does not read a literal $table when something else can change the table', function (string $body, array $extra, string $reason) {
    [$reported, $listed] = inferred_tables([
        ...$extra,
        'Models/Order.php' => model(BILLING_MODELS, 'Order', $extra === [] || isset($extra['Concerns/Tabled.php']) && ! isset($extra['Models/Base.php']) ? 'Model' : 'Base', body: $body, use: 'use Illuminate\\Database\\Eloquent\\Model;'),
    ]);

    expect($reported)->toBe([])->and($listed)->toBe($reason === '' ? $listed : [$reason])->and(count($listed))->toBeLessThanOrEqual(1);
})->with([
    'getTable()' => ["    protected \$table = 'orders';\n    public function getTable(): string\n    {\n        return 'users';\n    }", [], 'Order: it overrides getTable()'],
    'an assignment' => ["    protected \$table = 'orders';\n    public function swap(): void\n    {\n        \$this->table = 'legacy';\n    }", [], 'Order: it assigns its table in code'],
    'a coalescing assignment' => ["    protected \$table = 'orders';\n    public function swap(): void\n    {\n        \$this->table ??= 'legacy';\n    }", [], 'Order: it assigns its table in code'],
    'setTable()' => ["    protected \$table = 'orders';\n    public function swap(): void\n    {\n        \$this->setTable('legacy');\n    }", [], 'Order: it assigns its table in code'],
    'a trait that assigns it' => ["    use \\App\\Modules\\Billing\\Concerns\\Tabled;\n    protected \$table = 'orders';", ['Concerns/Tabled.php' => trait_file('Tabled', "    public function initializeTabled(): void\n    {\n        \$this->table = 'legacy';\n    }")], 'Order: it uses a trait that sets its table'],
    'a parent with getTable()' => ["    protected \$table = 'orders';", ['Models/Base.php' => model(BILLING_MODELS, 'Base', body: "    public function getTable(): string\n    {\n        return 'users';\n    }", use: 'use Illuminate\\Database\\Eloquent\\Model;')], 'Base: it overrides getTable()'],
]);

it('lists a model with a $connection without a table', function () {
    [, $listed] = inferred_tables(['Models/Order.php' => model(BILLING_MODELS, 'Order', body: "    protected \$connection = 'legacy';")]);

    expect($listed)->toBe(['Order: it sets $connection']);
});

it('keeps inferring the default table of a model that only uses traits from vendor', function () {
    [$reported, $listed] = inferred_tables([
        'Models/Order.php' => model(BILLING_MODELS, 'Order', body: '    use HasFactory, SoftDeletes;', use: "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;"),
    ]);

    expect($reported)->toBe(['Order -> orders'])->and($listed)->toBe([]);
});

it('keeps inferring the default table with a trait of the project that sets no table', function () {
    [$reported] = inferred_tables([
        'Concerns/Tabled.php' => trait_file('Tabled', '    public function label(): string
    {
        return "x";
    }'),
        'Models/Order.php' => model(BILLING_MODELS, 'Order', body: '    use \\App\\Modules\\Billing\\Concerns\\Tabled;'),
    ]);

    expect($reported)->toBe(['Order -> orders']);
});

it('reads a join only on the query builder, not on what a terminal call returns', function (string $chain, array $tables) {
    $file = tables_project(['R.php' => "<?php\n\nuse Illuminate\\Support\\Facades\\DB;\n\n{$chain};\n"]).'/R.php';
    $uses = array_map(fn (TableUse $use) => $use->table, (new PhpParserExtractor(tables: true))->extract($file)->tables);

    sort($uses);
    sort($tables);

    expect($uses)->toBe($tables);
})->with([
    'get' => ["DB::table('a')->get()->join('orders', ',')", ['a']],
    'pluck' => ["DB::table('a')->pluck('n')->join('orders', ',')", ['a']],
    'first' => ["DB::table('a')->first()->join('orders')", ['a']],
    'all' => ["DB::table('a')->all()->join('orders')", ['a']],
    'cursor' => ["DB::table('a')->cursor()->join('orders')", ['a']],
    'lazy' => ["DB::table('a')->lazy()->join('orders')", ['a']],
    'value' => ["DB::table('a')->value('n')->join('orders')", ['a']],
    'count' => ["DB::table('a')->count()->join('orders')", ['a']],
    'sum' => ["DB::table('a')->sum('n')->join('orders')", ['a']],
    'exists' => ["DB::table('a')->exists()->join('orders')", ['a']],
    'paginate' => ["DB::table('a')->paginate()->join('orders')", ['a']],
    'chunk' => ["DB::table('a')->chunk(10, fn () => 1)->join('orders')", ['a']],
    'each' => ["DB::table('a')->each(fn () => 1)->join('orders')", ['a']],
    'toArray' => ["DB::table('a')->toArray()->join('orders')", ['a']],
    'a method nobody knows' => ["DB::table('a')->mystery()->join('orders')", ['a']],
    'a terminal after builder calls' => ["DB::table('a')->where('x', 1)->orderBy('y')->get()->join('orders')", ['a']],
    'builder calls keep the builder' => ["DB::table('a')->where('x', 1)->orWhereIn('y', [1])->select('z')->join('orders', 'a', 'b')->orderByDesc('y')->leftJoin('items', 'a', 'b')", ['a', 'items', 'orders']],
]);

// host

it('makes the host the owner of the tables its migrations create, and reports nothing from or about it', function () {
    $result = analyse_tables_app();
    $findings = table_findings($result->warnings);

    // Catalog queries users and Billing points foreign keys at it: the host owns it. The host alters orders and
    // queries invoices (app/Http), and none of that is reported.
    expect(array_filter($findings, fn (string $line) => str_contains($line, '[users]') || str_contains($line, 'database/migrations') || str_contains($line, 'app/Http')))->toBe([])
        ->and(array_map(fn (Violation $v) => $v->sourceModule, $result->warnings))->not->toContain('host');
});

it('analyses without the code outside the modules too', function () {
    $result = analyse_tables_app(hostPaths: []);

    expect(table_findings($result->warnings))->toHaveCount(7);
});

// nested modules

it('reports sibling and parent use of a nested module\'s table, never a child using its ancestors\'', function () {
    $root = tables_project([
        'app/Modules/Billing/Database/Migrations/a.php' => migration("Schema::create('ledger', fn () => null);"),
        'app/Modules/Billing/Services/Ledger.php' => "<?php\n\nnamespace App\\Modules\\Billing\\Services;\n\nuse Illuminate\\Support\\Facades\\DB;\n\nDB::table('invoices')->get();\n",
        'app/Modules/Billing/Invoices/Database/Migrations/a.php' => migration("Schema::create('invoices', fn () => null);"),
        'app/Modules/Billing/Invoices/Services/Totals.php' => "<?php\n\nnamespace App\\Modules\\Billing\\Invoices\\Services;\n\nuse Illuminate\\Support\\Facades\\DB;\n\nDB::table('ledger')->get();\nDB::table('invoices')->get();\n",
        'app/Modules/Billing/Payments/Services/Pay.php' => "<?php\n\nnamespace App\\Modules\\Billing\\Payments\\Services;\n\nuse Illuminate\\Support\\Facades\\DB;\n\nDB::table('ledger')->get();\nDB::table('invoices')->get();\n",
        'app/Modules/Orders/Services/Report.php' => "<?php\n\nnamespace App\\Modules\\Orders\\Services;\n\nuse Illuminate\\Support\\Facades\\DB;\n\nDB::table('invoices')->get();\nDB::table('ledger')->get();\n",
    ]);
    $config = ['Billing' => ['nested' => ['Invoices', 'Payments']]];
    $map = (new NestedResolver(new NamespaceResolver($root.'/app/Modules', 'App\\Modules'), $config))->resolve()->configure($config);
    $result = tables_analyzer()->analyze($map, $root);

    expect(table_findings($result->warnings, $root))->toBe([
        // a sibling, from inside Billing
        'app/Modules/Billing/Payments/Services/Pay.php:8 Module [Billing.Payments] queries table [invoices], which belongs to [Billing.Invoices].'.TABLES_HINT,
        // the parent uses the table of its nested module
        'app/Modules/Billing/Services/Ledger.php:7 Module [Billing] queries table [invoices], which belongs to [Billing.Invoices].'.TABLES_HINT,
        // from outside Billing a child does not exist: its tables belong to Billing
        'app/Modules/Orders/Services/Report.php:7 Module [Orders] queries table [invoices], which belongs to [Billing].'.TABLES_HINT,
        'app/Modules/Orders/Services/Report.php:8 Module [Orders] queries table [ledger], which belongs to [Billing].'.TABLES_HINT,
    ]);
});

// @cordon-ignore, baseline, determinism

it('honours @cordon-ignore for tables', function () {
    $root = temp_project('tables-app');
    $file = $root.'/app/Modules/Billing/Database/Migrations/2024_02_02_000000_add_total_to_orders_table.php';
    file_put_contents($file, str_replace('        Schema::table(\'orders\'', "        // @cordon-ignore tables (the legacy column lives in orders until the split)\n        Schema::table('orders'", (string) file_get_contents($file)));
    $result = analyse_tables_app(root: $root);

    expect(table_findings($result->warnings, $root))->toHaveCount(6)
        ->and($result->ignored)->toBe(1);
});

it('keeps a table violation out of the baseline when it is recorded', function () {
    $result = analyse_tables_app(['tables' => 'error']);
    $baseline = Baseline::fromViolations($result->violations, fixture_path('tables-app'));
    $filtered = $result->withBaseline($baseline);

    expect($filtered->violations)->toBe([]);
});

it('reports in the same order whatever the order the modules are read in', function () {
    $first = array_map(fn (Violation $v) => $v->toArray(), analyse_tables_app()->warnings);
    $second = array_map(fn (Violation $v) => $v->toArray(), analyse_tables_app()->warnings);

    expect($first)->toBe($second)->and($first)->not->toBe([]);
});

// the collector only runs when the rule is on

it('does not build the table collector unless the extractor is asked for tables', function () {
    $made = [];
    $factory = function (string $file) use (&$made): TableCollector {
        $made[] = basename($file);

        return new TableCollector($file);
    };
    $files = (new FileCollector)->collect(fixture_path('tables-app/app/Modules/Billing'));

    $off = new PhpParserExtractor(tableCollectors: $factory);

    foreach ($files as $file) {
        expect($off->extract($file)->tables)->toBe([]);
    }

    expect($made)->toBe([]);

    $on = new PhpParserExtractor(tables: true, tableCollectors: $factory);

    foreach ($files as $file) {
        $on->extract($file);
    }

    expect($made)->toBe(array_map('basename', $files));
});

it('does not report anything with the rule on when the extractor was not asked for tables', function () {
    expect(analyse_tables_app(['tables' => 'warn'], tables: false)->warnings)->toBe([]);
});

// the cache

it('round-trips the tables through the analysis cache data', function () {
    $file = fixture_path('tables-app/app/Modules/Billing/Database/Migrations/2024_02_01_000000_create_invoices_table.php');
    $analysis = (new PhpParserExtractor(tables: true))->extract($file);
    $again = FileAnalysis::fromArray(json_decode((string) json_encode($analysis->toArray()), true), $file);

    expect($analysis->tables)->not->toBe([])
        ->and($again)->toEqual($analysis)
        ->and($analysis->toArray()['tables'][0])->toBe(['kind' => 'create', 'table' => 'invoices', 'line' => 11, 'class' => null, 'reason' => null]);
});

it('treats an entry without the tables as a miss', function () {
    $data = (new FileAnalysis('x.php'))->toArray();
    unset($data['tables']);

    expect(fn () => FileAnalysis::fromArray($data, 'x.php'))->toThrow(InvalidArgumentException::class);
});

it('gives the same report cold, warm and without the cache', function () {
    $root = temp_project('tables-app');
    $cacheFile = $root.'/cache.json';
    $map = fn () => (new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve();
    $run = function (bool $cached) use ($root, $cacheFile, $map): array {
        $extractor = $cached
            ? new CachedExtractor(new PhpParserExtractor(tables: true), new AnalysisCache($cacheFile, $root, AnalysisCache::fingerprint(['docblocks' => false, 'tables' => true])))
            : new PhpParserExtractor(tables: true);
        $result = tables_analyzer(extractor: $extractor)->analyze($map(), $root, [$root.'/app', $root.'/database']);
        $extractor instanceof CachedExtractor && $extractor->save();

        return array_map(fn (Violation $v) => $v->toArray($root), $result->warnings);
    };

    $cold = $run(true);
    $warm = $run(true);

    expect($warm)->toBe($cold)->and($run(false))->toBe($cold)->and(count($cold))->toBe(7);
});

it('changes the cache fingerprint when the tables are turned on', function () {
    expect(AnalysisCache::fingerprint(['docblocks' => false, 'tables' => true]))->not->toBe(AnalysisCache::fingerprint(['docblocks' => false, 'tables' => false]));
});

// the documentation

it('lists the tables of each module in its card', function () {
    $root = fixture_path('tables-app');
    $analyzer = tables_analyzer();
    $snapshot = $analyzer->snapshot((new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve());
    $files = (new DocumentationGenerator)->generate($snapshot, [], EventInventory::build($snapshot), $root);
    $section = fn (string $module): string => explode("\n## Violations", explode("\n## Tables\n", $files['modules/'.$module.'.md'])[1] ?? '')[0];

    expect($section('Orders'))->toBe("\n- Owns: `order_items`, `orders`, `tenants`\n- Uses tables of other modules: none\n- Without owner: `audit_log` (created by Billing and Catalog), `App\\Modules\\Orders\\Models\\Tenant` (its \$table is not a literal)\n")
        ->and($section('Billing'))->toBe("\n- Owns: `invoices`\n- Uses tables of other modules: `categories` (Catalog), `order_items` (Orders), `orders` (Orders), `products` (Catalog)\n- Without owner: `audit_log` (created by Billing and Catalog)\n")
        ->and($section('Catalog'))->toBe("\n- Owns: `categories`, `products`\n- Uses tables of other modules: none\n- Without owner: `audit_log` (created by Billing and Catalog)\n");
});

it('leaves the documentation as it was when no table was read', function () {
    $root = fixture_path('tables-app');
    $snapshot = tables_analyzer(tables: false)->snapshot((new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve());
    $files = (new DocumentationGenerator)->generate($snapshot, [], EventInventory::build($snapshot), $root);

    expect($files['modules/Billing.md'])->not->toContain('## Tables');
});
