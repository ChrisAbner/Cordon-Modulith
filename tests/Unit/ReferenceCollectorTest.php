<?php

use Cordon\Analysis\FileAnalysis;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Analysis\Reference;
use Cordon\Analysis\ReferenceCollector;
use Cordon\Tests\Oracle\Temp;

mutates(ReferenceCollector::class);

/**
 * Extract the declarations and references of a piece of PHP (the opening tag is added).
 */
function collector_extract(string $code): FileAnalysis
{
    $file = Temp::removeAtExit(sys_get_temp_dir().'/cordon-collector-'.uniqid().'.php');
    file_put_contents($file, "<?php\n".$code);

    // A warning or a notice in the collector, such as reading a property a node does not have, fails the test.
    set_error_handler(static function (int $level, string $message, string $path, int $line): never {
        throw new ErrorException($message, 0, $level, $path, $line);
    });

    try {
        $analysis = (new PhpParserExtractor)->extract($file);
    } finally {
        restore_error_handler();
        unlink($file);
    }

    expect($analysis->error)->toBeNull();

    return $analysis;
}

/**
 * The kind of each reference to $target, in file order.
 *
 * @return list<string>
 */
function collector_kinds(FileAnalysis $analysis, string $target): array
{
    return array_values(array_map(
        fn (Reference $reference) => $reference->kind,
        array_filter($analysis->references, fn (Reference $reference) => $reference->target === $target),
    ));
}

it('keeps the parent and the interfaces of every kind of declaration', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App;',
        'class Invoice extends \Base\Model implements \Contracts\Payable, \Contracts\Printable {}',
        'enum Status: string implements \Contracts\Labelled { case Paid = "paid"; }',
        'interface Settled extends \Contracts\Payable, \Contracts\Printable {}',
        'trait Totals {}',
    ]));
    $byName = [];

    foreach ($analysis->declarations as $declaration) {
        $byName[$declaration->fqcn] = $declaration;
    }

    expect($byName['App\\Invoice']->parent)->toBe('Base\\Model')
        ->and($byName['App\\Invoice']->interfaces)->toBe(['Contracts\\Payable', 'Contracts\\Printable'])
        ->and($byName['App\\Status']->parent)->toBeNull()
        ->and($byName['App\\Status']->interfaces)->toBe(['Contracts\\Labelled'])
        ->and($byName['App\\Settled']->parent)->toBeNull()
        ->and($byName['App\\Settled']->interfaces)->toBe(['Contracts\\Payable', 'Contracts\\Printable'])
        ->and($byName['App\\Totals']->interfaces)->toBe([])
        ->and($byName['App\\Invoice']->line)->toBe(3);
});

it('does not declare anonymous classes', function () {
    expect(collector_extract("namespace App;\n\$handler = new class {};")->declarations)->toBe([]);
});

it('tells which class a reference belongs to, and none outside a class', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App;',
        'new \Lib\Before;',
        'class First { public function run() { new \Lib\InFirst; } }',
        'new \Lib\Between;',
        'class Second { public function run() { new \Lib\InSecond; } }',
        'new \Lib\After;',
    ]));
    $classes = [];

    foreach ($analysis->references as $reference) {
        $classes[$reference->target] = $reference->sourceClass;
    }

    expect($classes)->toBe([
        'Lib\\Before' => null,
        'Lib\\InFirst' => 'App\\First',
        'Lib\\Between' => null,
        'Lib\\InSecond' => 'App\\Second',
        'Lib\\After' => null,
    ]);
});

it('goes back to the enclosing class after a class declared inside a method', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App;',
        'class Outer { public function make() { class Local { public function run() { new \Lib\InLocal; } } new \Lib\InOuter; } }',
    ]));
    $classes = [];

    foreach ($analysis->references as $reference) {
        $classes[$reference->target] = $reference->sourceClass;
    }

    expect($classes)->toBe(['Lib\\InLocal' => 'App\\Local', 'Lib\\InOuter' => 'App\\Outer']);
});

it('attributes the references of an anonymous class to the enclosing class', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App;',
        'class Outer {',
        '    public function make() { return new class { public function run() { new \Lib\Inside; } }; }',
        '    public function later() { new \Lib\Later; }',
        '}',
    ]));
    $classes = [];

    foreach ($analysis->references as $reference) {
        $classes[$reference->target] = $reference->sourceClass;
    }

    expect($classes)->toBe(['Lib\\Inside' => 'App\\Outer', 'Lib\\Later' => 'App\\Outer']);
});

it('marks the model of an Eloquent relation', function (string $method) {
    $analysis = collector_extract("namespace App;\nclass Order { public function lines() { return \$this->{$method}(\\Models\\Line::class); } }");

    expect($analysis->references[0]->target)->toBe('Models\\Line')
        ->and($analysis->references[0]->relation)->toBeTrue();
})->with([
    'belongsTo', 'hasOne', 'hasMany', 'belongsToMany', 'hasOneThrough', 'hasManyThrough', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany', 'BELONGSTO',
]);

it('does not take other calls for relations', function (string $call) {
    $analysis = collector_extract("namespace App;\nclass Order { public function lines() { return {$call}; } }");
    $relations = array_filter($analysis->references, fn (Reference $reference) => $reference->relation);

    expect($relations)->toBe([]);
})->with([
    'another receiver' => '$other->hasMany(\Models\Line::class)',
    'a new object' => '(new \Models\Factory)->hasMany(\Models\Line::class)',
    'a chained call' => '$this->query()->hasMany(\Models\Line::class)',
    'a method that is not a relation' => '$this->where(\Models\Line::class)',
    'a dynamic method' => '$this->{$method}(\Models\Line::class)',
    'no argument' => '$this->hasMany()',
    'a first-class callable' => '$this->hasMany(...)',
    'a string' => '$this->hasMany(\'Models\\\\Line\')',
    'a class that is not the first argument' => '$this->hasMany(\'lines\', \Models\Line::class)',
]);

it('marks the event a listener handles, wherever its namespace has an Events segment', function (string $event) {
    $analysis = collector_extract("namespace App\\Listeners;\nclass Notify { public function handle(\\{$event} \$event): void {} }");

    expect(collector_kinds($analysis, $event))->toBe([Reference::LISTENER]);
})->with([
    'in the middle' => 'Shop\\Events\\Placed',
    'in another case' => 'Shop\\EVENTS\\Placed',
    'at the root' => 'Events\\Placed',
    'a nested one' => 'Shop\\Events\\Order\\Placed',
]);

it('does not take a class named Events, or a service, for the event', function (string $type) {
    $analysis = collector_extract("namespace App\\Listeners;\nclass Notify { public function handle(\\{$type} \$service): void {} }");

    expect(collector_kinds($analysis, $type))->toBe([Reference::USE]);
})->with([
    'a class named Events' => 'Shop\\Support\\Events',
    'a service' => 'Shop\\Services\\Mailer',
]);

it('reads the event from __invoke and from a union or nullable type', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App\Listeners;',
        'class Invoked { public function __invoke(\Shop\Events\Placed $event) {} }',
        'class Either { public function handle(\Shop\Events\Paid|\Shop\Events\Refunded $event) {} }',
        'class Maybe { public function handle(?\Shop\Events\Shipped $event) {} }',
    ]));

    expect(collector_kinds($analysis, 'Shop\\Events\\Placed'))->toBe([Reference::LISTENER])
        ->and(collector_kinds($analysis, 'Shop\\Events\\Paid'))->toBe([Reference::LISTENER])
        ->and(collector_kinds($analysis, 'Shop\\Events\\Refunded'))->toBe([Reference::LISTENER])
        ->and(collector_kinds($analysis, 'Shop\\Events\\Shipped'))->toBe([Reference::LISTENER]);
});

it('marks every use of the handled event in the listener, but not outside it or of other classes', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App\Listeners;',
        'new \Shop\Events\Placed;',
        'class Notify {',
        '    public function handle(\Shop\Events\Placed $event): void { new \Shop\Mail\Receipt; }',
        '    public function failed(\Shop\Events\Placed $event): void { new \Shop\Events\Placed; }',
        '}',
        'new \Shop\Events\Placed;',
    ]));

    expect(collector_kinds($analysis, 'Shop\\Events\\Placed'))->toBe([Reference::USE, Reference::LISTENER, Reference::LISTENER, Reference::LISTENER, Reference::USE])
        ->and(collector_kinds($analysis, 'Shop\\Mail\\Receipt'))->toBe([Reference::USE]);
});

it('keeps the events of a listener apart from those of an anonymous class inside it', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App\Listeners;',
        'class Outer {',
        '    public function other() { return new \Shop\Events\Placed; }',
        '    public function make() { return new class { public function handle(\Shop\Events\Placed $event) {} }; }',
        '}',
    ]));

    expect(collector_kinds($analysis, 'Shop\\Events\\Placed'))->toBe([Reference::USE, Reference::LISTENER]);
});

it('goes on marking the events of a listener after an anonymous class inside it', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App\Listeners;',
        'class Outer {',
        '    public function handle(\Shop\Events\Placed $event) {}',
        '    public function make() { return new class {}; }',
        '    public function failed() { return new \Shop\Events\Placed; }',
        '}',
    ]));

    expect(collector_kinds($analysis, 'Shop\\Events\\Placed'))->toBe([Reference::LISTENER, Reference::LISTENER]);
});

it('marks the class of a closure and of an arrow function given to Event::listen', function (string $listener) {
    $analysis = collector_extract("use Illuminate\\Support\\Facades\\Event;\nEvent::listen({$listener});");

    expect(collector_kinds($analysis, 'Shop\\Events\\Placed'))->toBe([Reference::LISTENER]);
})->with([
    'a closure' => 'function (\Shop\Events\Placed $event) { return 1; }',
    'an arrow function' => 'fn (\Shop\Events\Placed $event) => 1',
]);

it('leaves the parameters of other closures as plain uses', function () {
    $analysis = collector_extract("use Illuminate\\Support\\Facades\\Event;\nEvent::dispatch(function (\\Shop\\Events\\Placed \$event) { return 1; });");

    expect(collector_kinds($analysis, 'Shop\\Events\\Placed'))->toBe([Reference::USE]);
});

it('names the member of each public signature that mentions a class', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App;',
        'class Invoice {',
        '    public const ?\Lib\Currency DEFAULT = null;',
        '    public \Lib\Customer $customer;',
        '    public function __construct(public \Lib\Promoted $promoted, private \Lib\Hidden $hidden, protected \Lib\Guarded $guarded, \Lib\Injected $injected) {}',
        '    public function pay(\Lib\Method $method, ?\Lib\Optional $note, \Lib\One|\Lib\Two $either, \Lib\A&\Lib\B $both): \Lib\Receipt {}',
        '    protected function hidden(\Lib\Internal $x): \Lib\InternalReturn {}',
        '}',
    ]));
    $signatures = [];

    foreach ($analysis->references as $reference) {
        $signatures[$reference->target] = $reference->signature;
    }

    expect($signatures)->toBe([
        'Lib\\Currency' => 'DEFAULT has type',
        'Lib\\Customer' => '$customer has type',
        'Lib\\Promoted' => '$promoted has type',
        'Lib\\Hidden' => null,
        'Lib\\Guarded' => null,
        'Lib\\Injected' => null,
        'Lib\\Method' => 'pay() takes',
        'Lib\\Optional' => 'pay() takes',
        'Lib\\One' => 'pay() takes',
        'Lib\\Two' => 'pay() takes',
        'Lib\\A' => 'pay() takes',
        'Lib\\B' => 'pay() takes',
        'Lib\\Receipt' => 'pay() returns',
        'Lib\\Internal' => null,
        'Lib\\InternalReturn' => null,
    ]);
});

it('still names the public signature of a class after an anonymous class inside it', function () {
    $analysis = collector_extract(implode("\n", [
        'namespace App;',
        'class Outer {',
        '    public function first() { return new class {}; }',
        '    public function later(\Lib\Late $late) {}',
        '}',
    ]));

    expect($analysis->references[0]->signature)->toBe('later() takes');
});

it('does not take the members of an anonymous class for public API', function () {
    $analysis = collector_extract("namespace App;\n\$handler = new class { public function run(\\Lib\\Anon \$anon) {} };");

    expect($analysis->references[0]->signature)->toBeNull();
});

it('does not take function or constant names for classes', function () {
    $analysis = collector_extract("namespace App;\nclass Report { public function run(\$callback) { return \\Lib\\helper(\\Lib\\LIMIT, \$callback(\\Lib\\Arg::class)); } }");

    expect(array_map(fn (Reference $reference) => $reference->target, $analysis->references))->toBe(['Lib\\Arg']);
});

it('finds the visibility attribute whatever its spelling', function (string $attribute, string $expected) {
    $analysis = collector_extract("namespace App;\n{$attribute}\nclass Thing {}");

    expect($analysis->declarations[0]->visibility->name)->toBe($expected);
})->with([
    'internal' => ['#[\Cordon\Attributes\Internal]', 'Internal'],
    'internal in another case' => ['#[\cordon\attributes\INTERNAL]', 'Internal'],
    'public api' => ['#[\Cordon\Attributes\PublicApi]', 'PublicApi'],
    'another attribute' => ['#[\Cordon\Attributes\Other]', 'Unspecified'],
    'none' => ['', 'Unspecified'],
]);
