<?php

use Cordon\Analysis\Analyzer;
use Cordon\Analysis\FileAnalysis;
use Cordon\Analysis\FileCollector;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\PublicApiPolicy;
use Cordon\Analysis\Reference;
use Cordon\Analysis\Result;
use Cordon\Resolvers\NamespaceResolver;
use Cordon\Rules\RuleSet;
use Cordon\Tests\Oracle\Temp;

/**
 * The references of kind docblock the extractor finds in a fixture, by target.
 *
 * @return array<string, list<int>> target => lines
 */
function docblock_targets(string $file, bool $docblocks = true): array
{
    $analysis = (new PhpParserExtractor(docblocks: $docblocks))->extract($file);

    expect($analysis->error)->toBeNull();

    return docblock_lines($analysis);
}

/**
 * @return array<string, list<int>>
 */
function docblock_lines(FileAnalysis $analysis): array
{
    $found = [];

    foreach ($analysis->references as $reference) {
        if ($reference->kind === Reference::DOCBLOCK) {
            $found[$reference->target][] = $reference->line;
        }
    }

    ksort($found);

    return $found;
}

/**
 * @return array<string, list<int>> The docblock references of a piece of code.
 */
function docblock_code_targets(string $code, bool $docblocks = true): array
{
    $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-docblock-'.bin2hex(random_bytes(4)).'.php');
    file_put_contents($file, $code);

    return docblock_targets($file, $docblocks);
}

const DOCBLOCK_EXPECTED = [
    'Countable',
    'Edge\\Source\\Sibling',
    'Edge\\Source\\Sub\\Relative',
    'Edge\\Source\\Sub\\Thing',
    'Edge\\Target\\Aliased',
    'Edge\\Target\\CallableParam',
    'Edge\\Target\\CallableReturn',
    'Edge\\Target\\ClassStringTarget',
    'Edge\\Target\\ConditionalSubject',
    'Edge\\Target\\ExtendsArgument',
    'Edge\\Target\\ExtendsBase',
    'Edge\\Target\\FunctionParam',
    'Edge\\Target\\GroupedA',
    'Edge\\Target\\GroupedB',
    'Edge\\Target\\Imported',
    'Edge\\Target\\ImplementsContract',
    'Edge\\Target\\InlineVar',
    'Edge\\Target\\ListItem',
    'Edge\\Target\\MethodParameter',
    'Edge\\Target\\MethodReturn',
    'Edge\\Target\\MethodTemplateBound',
    'Edge\\Target\\MixinTarget',
    'Edge\\Target\\NestedGeneric',
    'Edge\\Target\\OtherThrown',
    'Edge\\Target\\ParamIntersection',
    'Edge\\Target\\PropertyTag',
    'Edge\\Target\\PropertyVar',
    'Edge\\Target\\ShapeItem',
    'Edge\\Target\\ShapeOwner',
    'Edge\\Target\\TemplateBound',
    'Edge\\Target\\ThrownException',
    'Edge\\Target\\UsedGeneric',
];

it('finds no docblock reference unless docblocks is on', function () {
    $file = fixture_path('edge-cases/Docblocks.php');
    $off = (new PhpParserExtractor(docblocks: false))->extract($file);
    $default = (new PhpParserExtractor)->extract($file);

    expect(docblock_lines($off))->toBe([])
        ->and($default->references)->toEqual($off->references)
        ->and(array_map(fn (Reference $reference) => $reference->kind, $off->references))->each->toBe(Reference::USE);
});

it('reads the class names of every docblock tag', function (string $target, int $line) {
    expect(docblock_targets(fixture_path('edge-cases/Docblocks.php')))->toHaveKey($target)
        ->and(docblock_targets(fixture_path('edge-cases/Docblocks.php'))[$target])->toContain($line);
})->with([
    '@template ... of' => ['Edge\\Target\\TemplateBound', 10],
    '@extends, with a generic argument' => ['Edge\\Target\\ExtendsBase', 12],
    '@extends, in the arguments' => ['Edge\\Target\\ExtendsArgument', 12],
    '@implements' => ['Edge\\Target\\ImplementsContract', 13],
    '@implements, an imported argument' => ['Edge\\Target\\Imported', 13],
    '@mixin' => ['Edge\\Target\\MixinTarget', 14],
    '@property' => ['Edge\\Target\\PropertyTag', 15],
    '@property-read, through a use alias' => ['Edge\\Target\\Aliased', 16],
    '@property-write, through a group use' => ['Edge\\Target\\GroupedA', 17],
    '@method, the return type' => ['Edge\\Target\\MethodReturn', 18],
    '@method, a parameter' => ['Edge\\Target\\MethodParameter', 18],
    '@method static' => ['Edge\\Target\\GroupedB', 19],
    '@phpstan-type' => ['Edge\\Target\\ShapeItem', 20],
    '@use' => ['Edge\\Target\\UsedGeneric', 24],
    '@var on a property' => ['Edge\\Target\\PropertyVar', 27],
    '@var, generics inside generics' => ['Edge\\Target\\NestedGeneric', 30],
    '@var, a union through a use alias' => ['Edge\\Target\\Aliased', 33],
    '@var, class-string<T>' => ['Edge\\Target\\ClassStringTarget', 36],
    '@var, a name relative to the namespace' => ['Edge\\Source\\Sibling', 39],
    '@var, a qualified name relative to the namespace' => ['Edge\\Source\\Sub\\Thing', 42],
    '@var, namespace\\ ' => ['Edge\\Source\\Sub\\Relative', 45],
    '@template on a method' => ['Edge\\Target\\MethodTemplateBound', 55],
    '@param, an intersection' => ['Edge\\Target\\ParamIntersection', 59],
    '@param, a global class' => ['Countable', 59],
    '@param, callable parameter' => ['Edge\\Target\\CallableParam', 60],
    '@param, callable return' => ['Edge\\Target\\CallableReturn', 60],
    '@param, array shape' => ['Edge\\Target\\ShapeOwner', 61],
    '@param, a typed array' => ['Edge\\Target\\ListItem', 62],
    '@return, a conditional type' => ['Edge\\Target\\ConditionalSubject', 66],
    '@throws, first of a union' => ['Edge\\Target\\ThrownException', 68],
    '@throws, second of a union' => ['Edge\\Target\\OtherThrown', 68],
    '@var inside a method body' => ['Edge\\Target\\InlineVar', 72],
    '@param on a function' => ['Edge\\Target\\FunctionParam', 87],
]);

it('reports exactly those names and nothing else, templates, aliases and pseudo-types included', function () {
    expect(array_keys(docblock_targets(fixture_path('edge-cases/Docblocks.php'))))->toEqualCanonicalizing(DOCBLOCK_EXPECTED);
});

it('attributes a docblock reference to the class that holds it', function () {
    $analysis = (new PhpParserExtractor(docblocks: true))->extract(fixture_path('edge-cases/Docblocks.php'));
    $sources = [];

    foreach ($analysis->references as $reference) {
        if ($reference->kind === Reference::DOCBLOCK) {
            $sources[$reference->target] = $reference->sourceClass;
        }
    }

    expect($sources['Edge\\Target\\ExtendsBase'])->toBe('Edge\\Source\\Docblocks')
        ->and($sources['Edge\\Target\\InlineVar'])->toBe('Edge\\Source\\Docblocks')
        ->and($sources['Edge\\Target\\FunctionParam'])->toBeNull();
});

it('keeps the references of the code themselves when it reads docblocks', function () {
    $file = fixture_path('edge-cases/References.php');
    $off = (new PhpParserExtractor(docblocks: false))->extract($file);
    $on = (new PhpParserExtractor(docblocks: true))->extract($file);
    $native = array_values(array_filter($on->references, fn (Reference $reference) => $reference->kind !== Reference::DOCBLOCK));

    expect($native)->toEqual($off->references)
        ->and($on->declarations)->toEqual($off->declarations);
});

it('is not fooled by names that only look like classes', function (string $docblock) {
    $code = "<?php\nnamespace App;\n\nuse Other\\Imported;\n\nclass Holder\n{\n    /**\n     * @template TT\n     * @phpstan-type Alias array{a: int}\n     * {$docblock}\n     */\n    public function run(): void {}\n}\n";

    expect(docblock_code_targets($code))->toBe([]);
})->with([
    'a template' => '@param TT $a',
    'a type alias' => '@return Alias',
    'array-key' => '@param array-key $a',
    'positive-int' => '@param positive-int $a',
    'non-empty-string' => '@param non-empty-string $a',
    'static' => '@return static',
    'self' => '@return self',
    '$this' => '@return $this',
    'parent' => '@return parent',
    'class-string without an argument' => '@param class-string $a',
    'class-string of a template' => '@param class-string<TT> $a',
    'scalars' => '@param int|string|float|bool|null $a',
    'callable' => '@param callable $a',
    'a callable signature of pseudo-types' => '@param callable(int, string): void $a',
    'void and never' => '@return void|never',
    'iterable of mixed' => '@param iterable<mixed> $a',
    'an array shape with names as keys' => '@param array{foo: int, bar?: string} $a',
    'an array shape with numeric keys' => '@param array{0: int, 1: string} $a',
    'a constant of the class itself' => '@param self::FOO|static::BAR $a',
    'an int range' => '@param int<0, max> $a',
    'a literal string' => "@param 'Other\\\\Imported' \$a",
    'a literal number' => '@param 1|2.5 $a',
    'key-of a template' => '@param key-of<TT> $a',
    'numeric-string and lowercase-string' => '@param numeric-string|lowercase-string $a',
    'object shape' => '@param object{id: int} $a',
    'not a type at all' => '@param $a',
    'a description only' => 'Returns Imported when the sun shines.',
    'a tag that is not read' => '@see Imported',
    '@param-out' => '@param-out Imported $a',
    '@deprecated with a class name' => '@deprecated use Imported instead',
    '@inheritDoc' => '@inheritDoc',
]);

it('still resolves a name that a template of another scope shadowed', function () {
    $code = <<<'PHP'
    <?php
    namespace App;

    /** @template Box */
    class Holder
    {
        /** @param Box $inside */
        public function a($inside): void {}
    }

    class Other
    {
        /** @param Box $outside */
        public function b($outside): void {}
    }
    PHP;

    expect(docblock_code_targets($code))->toBe(['App\\Box' => [13]]);
});

it('does nothing for broken docblocks', function (string $docblock) {
    $code = "<?php\nnamespace App;\n\nclass Holder\n{\n    {$docblock}\n    public function run(): void {}\n}\n";
    $analysis = null;

    expect(function () use ($code, &$analysis) {
        $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-docblock-'.bin2hex(random_bytes(4)).'.php');
        file_put_contents($file, $code);
        $analysis = (new PhpParserExtractor(docblocks: true))->extract($file);
    })->not->toThrow(Throwable::class);

    expect($analysis->error)->toBeNull()
        ->and(array_values(array_filter($analysis->references, fn (Reference $reference) => $reference->kind === Reference::DOCBLOCK && str_contains($reference->target, 'Good'))))->toBe([]);
})->with([
    'an unclosed generic' => '/** @param array<string, Good $a */',
    'an unclosed array shape' => '/** @param array{a: Good $a */',
    'a trailing pipe' => '/** @param Good| $a */',
    'a bare generic opener' => '/** @param Good< $a */',
    'a callable without a closing parenthesis' => '/** @param callable(Good $a */',
    'a tag without a type' => '/** @param */',
    'an empty docblock' => '/** */',
    'only stars' => "/**\n *\n *\n */",
    'an unterminated string' => "/** @param 'Good \$a */",
    'garbage characters' => "/** @param \x01\x02\x03 ??? ((( ]]] \$a */",
    'a tag with only an at sign' => '/** @ */',
    'a conditional that never ends' => '/** @return ($a is Good ? */',
    'a template without a name' => '/** @template of Good */',
    'deeply nested and never closed' => '/** @param '.str_repeat('A<', 400).'Good $a */',
]);

it('keeps the good tags of a docblock that has a broken one', function () {
    $code = "<?php\nnamespace App;\n\nclass Holder\n{\n    /**\n     * @param array<string, Broken \$a\n     * @return \\Other\\Fine\n     */\n    public function run(\$a): void {}\n}\n";

    expect(array_keys(docblock_code_targets($code)))->toContain('Other\\Fine');
});

it('reads a docblock whatever the line endings are', function () {
    $code = "<?php\r\nnamespace App;\r\n\r\nclass Holder\r\n{\r\n    /**\r\n     * @param \\Other\\Fine \$a\r\n     */\r\n    public function run(\$a): void {}\r\n}\r\n";

    expect(docblock_code_targets($code))->toBe(['Other\\Fine' => [7]]);
});

it('does not read ordinary comments or docblocks written as strings', function () {
    $code = <<<'PHP'
    <?php
    namespace App;

    class Holder
    {
        // @var \Other\Line
        /* @var \Other\Block */
        # @var \Other\Hash
        private string $doc = '/** @var \Other\InString */';
    }
    PHP;

    expect(docblock_code_targets($code))->toBe([]);
});

it('resolves names the way PHP does in a file with several namespaces and a global one', function () {
    $code = <<<'PHP'
    <?php
    namespace One {
        use Shared\Thing;

        /** @var Thing|Local */
        class A {}
    }

    namespace Two {
        /** @var Thing|Local */
        class B {}
    }

    namespace {
        /** @var Thing */
        class C {}
    }
    PHP;

    expect(docblock_code_targets($code))->toBe([
        'One\\Local' => [5],
        'Shared\\Thing' => [5],
        'Thing' => [15],
        'Two\\Local' => [10],
        'Two\\Thing' => [10],
    ]);
});

it('ignores use function and use const when resolving class names', function () {
    $code = <<<'PHP'
    <?php
    namespace App;

    use function Lib\helper;
    use const Lib\LIMIT;
    use Lib\Real;

    /** @var helper|LIMIT|Real */
    class Holder {}
    PHP;

    expect(docblock_code_targets($code))->toBe([
        'App\\LIMIT' => [8],
        'App\\helper' => [8],
        'Lib\\Real' => [8],
    ]);
});

/**
 * tests/Fixtures/docblocks-app, where Billing uses Catalog only in docblocks and Orders uses Billing only in one.
 *
 * @param  array<string, mixed>  $modules
 * @param  array<array-key, mixed>  $rules
 */
function analyse_docblocks_app(array $modules, bool $docblocks, array $rules = []): Result
{
    $root = fixture_path('docblocks-app');
    $built = RuleSet::fromConfig($rules, null, ['cycles' => true]);
    $analyzer = new Analyzer(new PhpParserExtractor(docblocks: $docblocks), new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));

    return $analyzer->analyze((new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve()->configure($modules), $root);
}

/**
 * "rule source -> target" of each violation, sorted.
 *
 * @return list<string>
 */
function docblock_findings(Result $result): array
{
    $found = array_map(fn ($violation) => $violation->rule.' '.$violation->sourceModule.' -> '.$violation->targetModule.' '.$violation->target, [...$result->violations, ...$result->warnings]);
    sort($found);

    return $found;
}

const DOCBLOCK_MODULES = ['Billing' => ['depends_on' => ['Orders']]];

it('changes nothing in a project that does not turn docblocks on', function () {
    expect(docblock_findings(analyse_docblocks_app(DOCBLOCK_MODULES, false)))->toBe([]);
});

it('treats a docblock reference like any other for internal_access, undeclared_dependency and cycles', function () {
    $result = analyse_docblocks_app(DOCBLOCK_MODULES, true);

    $internal = array_values(array_filter($result->violations, fn ($violation) => $violation->rule === 'internal_access'));

    expect(docblock_findings($result))->toBe([
        'cycles Billing -> Orders Billing -> Orders',
        'cycles Orders -> Billing Orders -> Billing',
        'internal_access Billing -> Catalog App\\Modules\\Catalog\\Models\\Stock',
        'undeclared_dependency Billing -> Catalog Catalog',
    ])
        ->and($internal[0]->line)->toBe(16)
        ->and(basename((string) $internal[0]->file))->toBe('Invoicer.php');
});

it('counts a docblock reference as a use of the dependency', function () {
    $modules = ['Billing' => ['depends_on' => ['Orders', 'Catalog']]];

    expect(docblock_findings(analyse_docblocks_app($modules, false, ['unused_dependency' => 'error'])))->toBe(['unused_dependency Billing -> Catalog Billing -> Catalog'])
        ->and(docblock_findings(analyse_docblocks_app($modules, true, ['unused_dependency' => 'error', 'internal_access' => 'off', 'cycles' => 'off'])))->toBe([]);
});

it('limits a docblock reference to the named interfaces a module depends on', function () {
    $modules = [
        'Catalog' => ['interfaces' => ['Pricing' => ['Contracts\\PriceList']]],
        'Billing' => ['depends_on' => ['Orders', 'Catalog::Pricing']],
    ];
    $messages = array_map(fn ($violation) => $violation->message, array_filter(analyse_docblocks_app($modules, true)->violations, fn ($violation) => $violation->rule === 'undeclared_dependency'));

    expect(array_values($messages))->toBe([
        'Module [Billing] uses App\\Modules\\Catalog\\Contracts\\Inventory, which is not in the interfaces it declares on [Catalog] (Pricing).',
    ]);
});

it('keeps the kind and the line of a docblock reference in the cache format', function (string $file) {
    $analysis = (new PhpParserExtractor(docblocks: true))->extract($file);
    $restored = FileAnalysis::fromArray(json_decode((string) json_encode($analysis->toArray()), true), $analysis->file);

    expect(docblock_lines($analysis))->not->toBe([])
        ->and($restored)->toEqual($analysis)
        ->and(docblock_lines($restored))->toBe(docblock_lines($analysis));
})->with([
    'the docblock fixture' => fn () => fixture_path('edge-cases/Docblocks.php'),
    'a module class' => fn () => fixture_path('docblocks-app/app/Modules/Billing/Services/Invoicer.php'),
]);

/**
 * Analyse a copy of $fixture with the given rules, listener switches and layers.
 *
 * @param  array<string, mixed>  $modules
 * @param  array<array-key, mixed>  $rules
 * @param  array<string, bool>  $listeners
 * @param  list<list<string>>  $layers
 */
function analyse_project_with_docblocks(string $root, array $modules, bool $docblocks, array $rules = [], array $listeners = [], array $layers = []): Result
{
    $built = RuleSet::fromConfig($rules, null, $listeners, $layers);
    $analyzer = new Analyzer(new PhpParserExtractor(docblocks: $docblocks), new FileCollector, new PublicApiPolicy, $built, RuleSet::severities($rules, $built));

    return $analyzer->analyze((new NamespaceResolver($root.'/app/Modules', 'App\\Modules'))->resolve()->configure($modules), $root);
}

/**
 * "rule file:line message" of each violation and warning, sorted.
 *
 * @return list<string>
 */
function located_findings(Result $result): array
{
    $found = array_map(fn ($violation) => $violation->rule.' '.($violation->file === null ? '-' : basename($violation->file).':'.$violation->line).' '.$violation->message, [...$result->violations, ...$result->warnings]);
    sort($found);

    return $found;
}

it('treats a docblock mention of the event a listener handles like the listener reference', function (array $listeners) {
    $project = temp_project('listeners-docblocks-app');
    $modules = ['Billing' => ['depends_on' => []], 'Orders' => ['depends_on' => []]];
    $rules = ['unused_dependency' => 'error'];
    $layers = [['Orders'], ['Billing']];

    $off = located_findings(analyse_project_with_docblocks($project, $modules, false, $rules, $listeners, $layers));
    $on = located_findings(analyse_project_with_docblocks($project, $modules, true, $rules, $listeners, $layers));

    expect($on)->toBe($off)
        ->and(array_values(array_filter($on, fn (string $finding) => str_starts_with($finding, 'cycles') || str_starts_with($finding, 'layers'))))->toBe(array_values(array_filter($off, fn (string $finding) => str_starts_with($finding, 'cycles') || str_starts_with($finding, 'layers'))));
})->with([
    'default listener settings' => [[]],
    'listeners count for depends_on only' => [['cycles' => false, 'depends_on' => true]],
    'listeners count for cycles only' => [['cycles' => true, 'depends_on' => false]],
    'listeners count for everything' => [['cycles' => true, 'depends_on' => true]],
    'listeners count for nothing' => [['cycles' => false, 'depends_on' => false]],
]);

it('does not drop the docblock mention of an event in a class that does not listen to it', function () {
    $code = "<?php\nnamespace App\\Modules\\Billing\\Services;\n\nfinal class Reporter\n{\n    /** @param \\App\\Modules\\Orders\\Events\\OrderPlaced \$event */\n    public function report(\$event): void {}\n}\n";

    expect(docblock_code_targets($code))->toBe(['App\\Modules\\Orders\\Events\\OrderPlaced' => [6]]);
});

it('keeps the line of the first reference in code when the class is also named in a docblock', function () {
    $project = temp_project('docblock-ignore-app');
    $modules = ['Billing' => ['depends_on' => []]];
    $off = analyse_project_with_docblocks($project, $modules, false);
    $on = analyse_project_with_docblocks($project, $modules, true);

    $withoutDocblockOnly = fn (array $found): array => array_values(array_filter($found, fn (string $finding) => ! str_contains($finding, 'DocblockOnly.php')));

    expect($withoutDocblockOnly(located_findings($on)))->toBe(located_findings($off))
        ->and(array_values(array_filter(located_findings($on), fn (string $finding) => str_contains($finding, 'Reported.php:'))))->each->toContain('Reported.php:14')
        ->and($on->ignored)->toBe(2)
        ->and($on->ignoreWarnings)->toBe([])
        ->and(array_filter(located_findings($on), fn (string $finding) => str_contains($finding, 'Ignored.php')))->toBe([]);
});

it('reports a class named only in a docblock at the docblock line', function () {
    $project = temp_project('docblock-ignore-app');
    $found = located_findings(analyse_project_with_docblocks($project, ['Billing' => ['depends_on' => []]], true));

    expect(array_values(array_filter($found, fn (string $finding) => str_contains($finding, 'DocblockOnly.php'))))->each->toContain('DocblockOnly.php:10');
});
