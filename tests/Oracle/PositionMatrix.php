<?php

declare(strict_types=1);

namespace Cordon\Tests\Oracle;

/**
 * A deterministic matrix of positions × name forms × containers, after the
 * type matrices of php-oracles: every cell is a small PHP file with a
 * `// cell:` descriptor and the targets the extractor must find, or none.
 *
 * Cells are parsed, never run, so they are skipped by the php-parser
 * version they need (parserMin), not by the PHP version; phpMin is for the
 * reflection oracle, which runs them.
 */
final class PositionMatrix
{
    public const TARGET = 'Matrix\\Target\\Foo';

    /**
     * How the target is written, and the import it needs.
     *
     * @var array<string, array{0: string, 1: string}> form => [use line, expression]
     */
    public const FORMS = [
        'imported' => ['use Matrix\\Target\\Foo;', 'Foo'],
        'alias' => ['use Matrix\\Target\\Foo as Aliased;', 'Aliased'],
        'group' => ['use Matrix\\Target\\{Foo, Unused};', 'Foo'],
        'fully_qualified' => ['', '\\Matrix\\Target\\Foo'],
        'relative' => ['', 'namespace\\Target\\Foo'],
        'lowercase' => ['', '\\matrix\\target\\foo'],
    ];

    private const ALL_MEMBERS = ['class', 'trait', 'enum', 'interface', 'anonymous'];

    private const WITH_PROPERTIES = ['class', 'trait', 'anonymous'];

    private const WITH_TRAITS = ['class', 'trait', 'enum', 'anonymous'];

    /**
     * Containers: where a position sits. {X} is the position; {BODY} a method body.
     *
     * @var array<string, array<string, string>>
     */
    public const CONTAINERS = [
        'member' => [
            'class' => "final class Holder\n{\n    {X}\n}",
            'trait' => "trait Holder\n{\n    {X}\n}",
            'enum' => "enum Holder\n{\n    case Only;\n\n    {X}\n}",
            'interface' => "interface Holder\n{\n    {X}\n}",
            'anonymous' => "\$holder = new class\n{\n    {X}\n};",
        ],
        'stmt' => [
            'method' => "final class Holder\n{\n    public function run(mixed \$x, mixed \$y, mixed \$cls, object \$obj): void\n    {\n        {X}\n    }\n}",
            'enum_method' => "enum Holder\n{\n    case Only;\n\n    public function run(mixed \$x, mixed \$y, mixed \$cls, object \$obj): void\n    {\n        {X}\n    }\n}",
            'trait_method' => "trait Holder\n{\n    public function run(mixed \$x, mixed \$y, mixed \$cls, object \$obj): void\n    {\n        {X}\n    }\n}",
            'anonymous_method' => "\$holder = new class\n{\n    public function run(mixed \$x, mixed \$y, mixed \$cls, object \$obj): void\n    {\n        {X}\n    }\n};",
            'closure' => "\$run = function (mixed \$x, mixed \$y, mixed \$cls, object \$obj): void {\n    {X}\n};",
            'function' => "function run(mixed \$x, mixed \$y, mixed \$cls, object \$obj): void\n{\n    {X}\n}",
            'top_level' => '{X}',
        ],
        'decl' => ['self' => '{X}'],
    ];

    /**
     * id => [kind, template, ref|none, containers (null: all of the kind), php min, php-parser min, declaration position]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: list<string>|null, 4: string, 5: string, 6: bool}>
     */
    public const POSITIONS = [
        // Members of a class-like.
        'typed_property' => ['member', 'private {T} $p;', 'ref', self::WITH_PROPERTIES, '8.2', '5.3', true],
        'static_property' => ['member', 'public static ?{T} $s = null;', 'ref', self::WITH_PROPERTIES, '8.2', '5.3', true],
        'method_param' => ['member', 'public function m({T} $v): void{BODY}', 'ref', null, '8.2', '5.3', true],
        'method_return' => ['member', 'public function m(): {T}{BODY}', 'ref', null, '8.2', '5.3', true],
        'union_param' => ['member', 'public function m({T}|int $v): void{BODY}', 'ref', null, '8.2', '5.3', true],
        'nullable_return' => ['member', 'public function m(): ?{T}{BODY}', 'ref', null, '8.2', '5.3', true],
        'intersection_param' => ['member', 'public function m({T}&\\Countable $v): void{BODY}', 'ref', null, '8.2', '5.3', true],
        'dnf_param' => ['member', 'public function m(({T}&\\Countable)|null $v): void{BODY}', 'ref', null, '8.2', '5.3', true],
        'promoted_property' => ['member', 'public function __construct(private {T} $p) {}', 'ref', self::WITH_PROPERTIES, '8.2', '5.3', true],
        'default_new' => ['member', 'public function m(object $v = new {T}): void{BODY}', 'ref', null, '8.2', '5.3', false],
        'typed_const' => ['member', 'public const ?{T} C = null;', 'ref', self::ALL_MEMBERS, '8.3', '5.3', true],
        'method_attribute' => ['member', '#[{T}] public function m(): void{BODY}', 'ref', null, '8.2', '5.3', true],
        'param_attribute' => ['member', 'public function m(#[{T}] int $v): void{BODY}', 'ref', null, '8.2', '5.3', true],
        'property_attribute' => ['member', '#[{T}] public int $a = 1;', 'ref', self::WITH_PROPERTIES, '8.2', '5.3', true],
        'const_attribute' => ['member', '#[{T}] public const A = 1;', 'ref', self::ALL_MEMBERS, '8.2', '5.3', true],
        'hook_type' => ['member', 'public {T} $h { get => $this->h; }', 'ref', self::WITH_PROPERTIES, '8.4', '5.3', true],
        'hook_param' => ['member', 'public int $h { set(int|{T} $v) { $this->h = 1; } }', 'ref', self::WITH_PROPERTIES, '8.4', '5.3', true],
        'hook_body' => ['member', 'public object $h { get => new {T}; }', 'ref', self::WITH_PROPERTIES, '8.4', '5.3', false],
        'hook_attribute' => ['member', 'public int $h { #[{T}] get => 1; }', 'ref', self::WITH_PROPERTIES, '8.4', '5.3', false],
        'trait_use' => ['member', 'use {T};', 'ref', self::WITH_TRAITS, '8.2', '5.3', true],
        'trait_insteadof' => ['member', 'use \\Matrix\\Other\\A, {T} { {T}::f insteadof \\Matrix\\Other\\A; }', 'ref', self::WITH_TRAITS, '8.2', '5.3', false],
        'trait_alias' => ['member', 'use {T} { {T}::f as g; }', 'ref', self::WITH_TRAITS, '8.2', '5.3', false],
        'final_promoted' => ['member', 'public function __construct(final public {T} $p) {}', 'ref', ['class', 'anonymous'], '8.5', '5.6', false],
        'docblock_member' => ['member', '/** @var {T} */ public $d;', 'none', self::WITH_PROPERTIES, '8.2', '5.3', false],
        'string_member' => ['member', "public string \$s = '{T}';", 'none', self::WITH_PROPERTIES, '8.2', '5.3', false],

        // Declarations.
        'class_extends' => ['decl', 'final class C extends {T} {}', 'ref', null, '8.2', '5.3', true],
        'class_implements' => ['decl', 'final class C implements {T} {}', 'ref', null, '8.2', '5.3', true],
        'interface_extends' => ['decl', 'interface I extends {T} {}', 'ref', null, '8.2', '5.3', true],
        'enum_implements' => ['decl', 'enum E implements {T} { case A; }', 'ref', null, '8.2', '5.3', true],
        'class_attribute' => ['decl', '#[{T}] final class C {}', 'ref', null, '8.2', '5.3', true],
        'enum_case_attribute' => ['decl', 'enum E { #[{T}] case A; }', 'ref', null, '8.2', '5.3', true],
        'function_attribute' => ['decl', '#[{T}] function g(): void {}', 'ref', null, '8.2', '5.3', false],
        'attribute_new' => ['decl', '#[\\Matrix\\Other\\Outer(new {T})] final class C {}', 'ref', null, '8.2', '5.3', false],
        'attribute_const' => ['decl', '#[\\Matrix\\Other\\Outer({T}::A)] final class C {}', 'ref', null, '8.2', '5.3', false],
        'anonymous_extends' => ['decl', '$a = new class extends {T} {};', 'ref', null, '8.2', '5.3', false],
        'anonymous_implements' => ['decl', '$a = new class implements {T} {};', 'ref', null, '8.2', '5.3', false],
        'anonymous_attribute' => ['decl', '$a = new #[{T}] class {};', 'ref', null, '8.2', '5.3', false],
        'closure_attribute' => ['decl', '$f = #[{T}] function (): void {};', 'ref', null, '8.2', '5.3', false],
        'global_const_attribute' => ['decl', '#[{T}] const G = 1;', 'ref', null, '8.5', '5.6', false],
        'function_param' => ['decl', 'function g({T} $v): void {}', 'ref', null, '8.2', '5.3', false],

        // Statements.
        'new' => ['stmt', 'new {T};', 'ref', null, '8.2', '5.3', false],
        'new_args' => ['stmt', 'new {T}(1);', 'ref', null, '8.2', '5.3', false],
        'new_chained' => ['stmt', 'new {T}()->run();', 'ref', null, '8.4', '5.3', false],
        'static_call' => ['stmt', '{T}::make();', 'ref', null, '8.2', '5.3', false],
        'class_const_fetch' => ['stmt', '{T}::VALUE;', 'ref', null, '8.2', '5.3', false],
        'static_property_fetch' => ['stmt', '{T}::$p;', 'ref', null, '8.2', '5.3', false],
        'class_name' => ['stmt', '{T}::class;', 'ref', null, '8.2', '5.3', false],
        'instanceof' => ['stmt', '$x instanceof {T};', 'ref', null, '8.2', '5.3', false],
        'catch' => ['stmt', 'try {} catch ({T} $e) {}', 'ref', null, '8.2', '5.3', false],
        'multi_catch' => ['stmt', 'try {} catch (\\Matrix\\Other\\B|{T}) {}', 'ref', null, '8.2', '5.3', false],
        'first_class_callable' => ['stmt', '{T}::make(...);', 'ref', null, '8.2', '5.3', false],
        'closure_param' => ['stmt', '$f = static function ({T} $v) {};', 'ref', null, '8.2', '5.3', false],
        'closure_return' => ['stmt', '$f = function (): {T} {};', 'ref', null, '8.2', '5.3', false],
        'arrow_param' => ['stmt', '$f = fn ({T} $v) => 1;', 'ref', null, '8.2', '5.3', false],
        'match_instanceof' => ['stmt', 'match (true) { $x instanceof {T} => 1, default => 2 };', 'ref', null, '8.2', '5.3', false],
        'static_var_new' => ['stmt', 'static $s = new {T};', 'ref', null, '8.3', '5.3', false],
        'clone_with' => ['stmt', '$c = clone($obj, [\'p\' => new {T}]);', 'ref', null, '8.5', '5.6', false],
        'pipe' => ['stmt', '$x |> {T}::m(...);', 'ref', null, '8.5', '5.6', false],
        'dynamic_instanceof' => ['stmt', '$x instanceof $y;', 'none', null, '8.2', '5.3', false],
        'dynamic_new' => ['stmt', 'new $cls;', 'none', null, '8.2', '5.3', false],
        'dynamic_static_call' => ['stmt', '$cls::m();', 'none', null, '8.2', '5.3', false],
        'object_class' => ['stmt', '$obj::class;', 'none', null, '8.2', '5.3', false],
        'function_call' => ['stmt', '{T}();', 'none', null, '8.2', '5.3', false],
        'constant_fetch' => ['stmt', '$c = {T};', 'none', null, '8.2', '5.3', false],
        'string' => ['stmt', "\$s = '{T}';", 'none', null, '8.2', '5.3', false],
        'docblock' => ['stmt', '/** @var {T} $d */ $d = 1;', 'none', null, '8.2', '5.3', false],
        'comment' => ['stmt', '// {T}', 'none', null, '8.2', '5.3', false],
    ];

    /**
     * @return list<Cell>
     */
    public static function cells(): array
    {
        $cells = [];

        foreach (self::POSITIONS as $position => [$kind, $template, $expect, $containers, $phpMin, $parserMin, $declaration]) {
            foreach (self::FORMS as $form => [$use, $expression]) {
                foreach (self::CONTAINERS[$kind] as $container => $wrapper) {
                    if ($containers !== null && ! in_array($container, $containers, true)) {
                        continue;
                    }

                    $member = str_replace('{BODY}', $container === 'interface' ? ';' : ' {}', $template);
                    $body = str_replace('{X}', str_replace('{T}', $expression, $member), $wrapper);
                    $id = $position.' '.$form.' '.$container;
                    $code = "<?php\n\nnamespace Matrix;\n\n".($use !== '' ? $use."\n\n" : '')."// cell: {$id}\n".$body."\n";

                    $cells[] = new Cell($position, $form, $container, $code, $expect === 'ref' ? [strtolower(self::TARGET)] : [], $phpMin, $parserMin, $declaration);
                }
            }
        }

        return $cells;
    }

    /**
     * Writes one file per cell and a MANIFEST.json describing the run.
     *
     * @param  array<string, mixed>  $manifest  Extra fields, such as versions.
     */
    public static function write(string $directory, array $manifest = []): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $cells = self::cells();

        foreach ($cells as $cell) {
            file_put_contents($directory.'/'.$cell->file(), $cell->code);
        }

        file_put_contents($directory.'/MANIFEST.json', json_encode([
            'generator' => sha1((string) file_get_contents(__FILE__)),
            'cells' => count($cells),
            ...$manifest,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
