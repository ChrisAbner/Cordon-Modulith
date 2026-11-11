<?php

/*
 * The reflection oracle (D2): runs the declaration cells of the position
 * matrix in PHP and compares the class names reflection sees (parent,
 * interfaces, traits, attributes, types of parameters, returns, properties,
 * constants and hooks) with the targets the matrix expects Cordon to find.
 *
 *   php tests/Oracle/reflection.php
 *
 * Needs no dependencies, so CI runs it on every PHP version. Cells above
 * the running PHP version are skipped. Each kind of stub (the target as a
 * class, an interface or a trait) runs in its own process.
 */

declare(strict_types=1);

require_once __DIR__.'/Cell.php';
require_once __DIR__.'/PositionMatrix.php';

use Cordon\Tests\Oracle\Cell;
use Cordon\Tests\Oracle\PositionMatrix;

const STUBS = [
    'class' => 'class Foo {}',
    'interface' => 'interface Foo {}',
    'trait' => 'trait Foo { public function f(): void {} }',
];

/**
 * The kind of declaration the target must be for a cell to run.
 */
function stub_for(Cell $cell): string
{
    return match ($cell->position) {
        'class_implements', 'interface_extends', 'enum_implements' => 'interface',
        'trait_use' => 'trait',
        default => 'class',
    };
}

/**
 * The declaration cells this PHP can run: one form (the positions are what
 * reflection checks) and no anonymous classes (running them would need arguments).
 *
 * @return list<Cell>
 */
function runnable_cells(): array
{
    return array_values(array_filter(
        PositionMatrix::cells(),
        static fn (Cell $cell): bool => $cell->declaration && $cell->form === 'fully_qualified' && $cell->container !== 'anonymous',
    ));
}

/**
 * @return list<string>
 */
function type_names(?ReflectionType $type): array
{
    return match (true) {
        $type instanceof ReflectionNamedType => [$type->getName()],
        $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => array_merge(...array_map(type_names(...), $type->getTypes())),
        default => [],
    };
}

/**
 * @param  list<ReflectionAttribute<object>>  $attributes
 * @return list<string>
 */
function attribute_names(array $attributes): array
{
    return array_map(static fn (ReflectionAttribute $attribute): string => $attribute->getName(), $attributes);
}

/**
 * @return list<string>
 */
function function_names(ReflectionFunctionAbstract $function): array
{
    $names = [...attribute_names($function->getAttributes()), ...type_names($function->getReturnType())];

    foreach ($function->getParameters() as $parameter) {
        array_push($names, ...type_names($parameter->getType()), ...attribute_names($parameter->getAttributes()));
    }

    return $names;
}

/**
 * Every class name reflection sees in the declarations of $class.
 *
 * @return list<string>
 */
function reflected_names(ReflectionClass $class): array
{
    $names = [...attribute_names($class->getAttributes()), ...$class->getInterfaceNames(), ...$class->getTraitNames()];

    if (($parent = $class->getParentClass()) !== false) {
        $names[] = $parent->getName();
    }

    foreach ($class->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() === $class->getName()) {
            array_push($names, ...function_names($method));
        }
    }

    foreach ($class->getProperties() as $property) {
        array_push($names, ...type_names($property->getType()), ...attribute_names($property->getAttributes()));

        if (method_exists($property, 'getHooks')) {
            foreach ($property->getHooks() as $hook) {
                array_push($names, ...function_names($hook));
            }
        }
    }

    foreach ($class->getReflectionConstants() as $constant) {
        array_push($names, ...attribute_names($constant->getAttributes()));

        if (method_exists($constant, 'getType')) {
            array_push($names, ...type_names($constant->getType()));
        }
    }

    if ($class->isEnum()) {
        foreach ((new ReflectionEnum($class->getName()))->getCases() as $case) {
            array_push($names, ...attribute_names($case->getAttributes()));
        }
    }

    return $names;
}

/**
 * Runs one cell with its stub, in this process: the class names reflection
 * sees under Matrix\Target, or why the cell does not run.
 *
 * @return array{found?: list<string>, error?: string}
 */
function run_cell(Cell $cell): array
{
    eval('namespace Matrix\Target; '.STUBS[stub_for($cell)]);

    try {
        eval(str_replace('<?php', '', $cell->code));
    } catch (Throwable $error) {
        return ['error' => $error->getMessage()];
    }

    $found = [];

    foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $declared) {
        if (str_starts_with($declared, 'Matrix\\') && ! str_starts_with($declared, 'Matrix\\Target\\')) {
            foreach (reflected_names(new ReflectionClass($declared)) as $name) {
                if (str_starts_with(strtolower($name), 'matrix\\target\\')) {
                    $found[strtolower($name)] = true;
                }
            }
        }
    }

    $found = array_keys($found);
    sort($found);

    return ['found' => $found];
}

$cells = runnable_cells();
$options = getopt('', ['cell:']);

if (isset($options['cell']) && is_string($options['cell'])) {
    echo json_encode(run_cell($cells[(int) $options['cell']])), PHP_EOL;

    exit(0);
}

$failures = [];
$checked = 0;
$skipped = 0;

foreach ($cells as $index => $cell) {
    if (version_compare(PHP_VERSION, $cell->phpMin, '<')) {
        $skipped++;

        continue;
    }

    // One process per cell: a compile error in one cell must not hide the others.
    $output = trim((string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --cell='.$index.' 2>&1'));
    $result = json_decode($output, true);
    $checked++;

    if (! is_array($result)) {
        $failures[] = sprintf('%s: does not run: %s', $cell->id(), $output);
    } elseif (isset($result['error'])) {
        $failures[] = sprintf('%s: does not run: %s', $cell->id(), $result['error']);
    } elseif ($result['found'] !== $cell->expected) {
        $failures[] = sprintf('%s: reflection sees [%s], the matrix expects [%s]', $cell->id(), implode(', ', $result['found']), implode(', ', $cell->expected));
    }
}

printf("Reflection oracle: %d cells checked on PHP %s, %d skipped (newer PHP), %d failed\n", $checked, PHP_VERSION, $skipped, count($failures));

foreach ($failures as $failure) {
    echo '  - ', $failure, PHP_EOL;
}

// No cell checked would mean a broken generator, not agreement.
exit($failures === [] && $checked > 0 ? 0 : 1);
