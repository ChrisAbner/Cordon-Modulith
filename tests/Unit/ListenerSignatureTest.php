<?php

use Cordon\Analysis\ListenerSignature;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

mutates(ListenerSignature::class);

// A notice in the signature reader, such as reading a property a node does not have, fails the test.
beforeEach(function () {
    set_error_handler(static function (int $level, string $message, string $path, int $line): never {
        throw new ErrorException($message, 0, $level, $path, $line);
    });
});

afterEach(fn () => restore_error_handler());

/**
 * The nodes of a snippet, names resolved. The snippet imports the Event facade.
 *
 * @return list<Node>
 */
function listener_ast(string $code): array
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php\nuse Illuminate\\Support\\Facades\\Event;\nuse Shop\\Events\\Placed;\nuse Shop\\Events\\Paid;\n".$code) ?? [];

    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);

    return $traverser->traverse($ast);
}

function listener_method(string $code): Node\Stmt\ClassMethod
{
    $method = (new NodeFinder)->findFirstInstanceOf(listener_ast("class Listener { {$code} }"), Node\Stmt\ClassMethod::class);
    assert($method instanceof Node\Stmt\ClassMethod);

    return $method;
}

function listener_call(string $code): Node\Expr\StaticCall
{
    $call = (new NodeFinder)->findFirstInstanceOf(listener_ast($code), Node\Expr\StaticCall::class);
    assert($call instanceof Node\Expr\StaticCall);

    return $call;
}

/**
 * @param  list<Node\Name>  $names
 * @return list<string>
 */
function listener_names(array $names): array
{
    return array_map(fn (Node\Name $name) => $name->toString(), $names);
}

/**
 * @param  list<Node\Expr\ClassConstFetch>  $fetches
 * @return list<string>
 */
function listener_fetched(array $fetches): array
{
    return array_map(fn (Node\Expr\ClassConstFetch $fetch) => $fetch->class->toString(), $fetches);
}

it('reads the event from the first parameter of handle() and __invoke()', function (string $method) {
    expect(listener_names(ListenerSignature::eventTypes(listener_method("public function {$method}(Placed \$event, Paid \$other) {}"))))->toBe(['Shop\\Events\\Placed']);
})->with(['handle', 'Handle', '__invoke', '__INVOKE']);

it('reads no event from other methods', function () {
    expect(ListenerSignature::eventTypes(listener_method('public function failed(Placed $event) {}')))->toBe([])
        ->and(ListenerSignature::eventTypes(listener_method('public function handler(Placed $event) {}')))->toBe([]);
});

it('reads every class of a union, an intersection or a nullable type, and none of a scalar', function (string $type, array $expected) {
    expect(listener_names(ListenerSignature::eventTypes(listener_method("public function handle({$type} \$event) {}"))))->toBe($expected);
})->with([
    'a union' => ['Placed|Paid', ['Shop\\Events\\Placed', 'Shop\\Events\\Paid']],
    'a union with null' => ['Placed|null', ['Shop\\Events\\Placed']],
    'an intersection' => ['Placed&Paid', ['Shop\\Events\\Placed', 'Shop\\Events\\Paid']],
    'a nullable' => ['?Placed', ['Shop\\Events\\Placed']],
    'a scalar' => ['int', []],
    'an array' => ['array', []],
    'a union of scalars' => ['int|string', []],
    'a union with a scalar' => ['int|Paid', ['Shop\\Events\\Paid']],
]);

it('reads no event from a method without parameters or with an untyped one', function () {
    expect(ListenerSignature::eventTypes(listener_method('public function handle() {}')))->toBe([])
        ->and(ListenerSignature::eventTypes(listener_method('public function handle($event) {}')))->toBe([]);
});

it('reads the first parameter of a closure or an arrow function', function () {
    $closure = (new NodeFinder)->findFirstInstanceOf(listener_ast('$f = function (Placed $a, Paid $b) {};'), Node\Expr\Closure::class);
    $arrow = (new NodeFinder)->findFirstInstanceOf(listener_ast('$f = fn (Paid $a, Placed $b) => 1;'), Node\Expr\ArrowFunction::class);

    expect(listener_names(ListenerSignature::firstParameterTypes($closure)))->toBe(['Shop\\Events\\Placed'])
        ->and(listener_names(ListenerSignature::firstParameterTypes($arrow)))->toBe(['Shop\\Events\\Paid']);
});

it('finds the closure given to Event::listen', function (string $call, ?string $class) {
    $closure = ListenerSignature::listenedClosure(listener_call($call));

    expect($closure === null ? null : $closure::class)->toBe($class);
})->with([
    'a closure' => ['Event::listen(function (Placed $e) {});', Node\Expr\Closure::class],
    'an arrow function' => ['Event::listen(fn (Placed $e) => 1);', Node\Expr\ArrowFunction::class],
    'the method in another case' => ['Event::LISTEN(function (Placed $e) {});', Node\Expr\Closure::class],
    'the facade in another case' => ['\\illuminate\\support\\facades\\EVENT::listen(function (Placed $e) {});', Node\Expr\Closure::class],
    'a class name' => ['Event::listen(Placed::class, function (Placed $e) {});', null],
    'a variable' => ['Event::listen($listener);', null],
    'another method' => ['Event::dispatch(function (Placed $e) {});', null],
    'another class' => ['Bus::listen(function (Placed $e) {});', null],
    'a dynamic method' => ['Event::$method(function (Placed $e) {});', null],
    'a dynamic class' => ['$class::listen(function (Placed $e) {});', null],
    'a first-class callable' => ['Event::listen(...);', null],
    'no argument' => ['Event::listen();', null],
]);

it('finds the class keys of the $listen map of an EventServiceProvider', function () {
    $class = (new NodeFinder)->findFirstInstanceOf(listener_ast(implode("\n", [
        'use Illuminate\Foundation\Support\Providers\EventServiceProvider as Base;',
        'class Provider extends Base {',
        '    protected $other = [Paid::class => []];',
        '    protected $listen = [',
        '        Placed::class => [Notify::class],',
        "        'plain' => [],",
        '        Paid::class => [],',
        '        Status::DEFAULT => [],',
        '    ];',
        '}',
    ])), Node\Stmt\Class_::class);

    expect(listener_fetched(ListenerSignature::listenMapFetches($class)))->toBe(['Shop\\Events\\Placed', 'Shop\\Events\\Paid']);
});

it('reads the $listen map whatever the case of the parent class, and ignores other properties and defaults', function () {
    $find = fn (string $code) => (new NodeFinder)->findFirstInstanceOf(listener_ast($code), Node\Stmt\Class_::class);

    expect(listener_fetched(ListenerSignature::listenMapFetches($find('class P extends \illuminate\foundation\support\providers\EVENTSERVICEPROVIDER { protected $listen = [Placed::class => []]; }'))))->toBe(['Shop\\Events\\Placed'])
        ->and(ListenerSignature::listenMapFetches($find('class P extends \Illuminate\Foundation\Support\Providers\EventServiceProvider { protected $listen = null; }')))->toBe([])
        ->and(ListenerSignature::listenMapFetches($find('class P extends \Illuminate\Foundation\Support\Providers\EventServiceProvider { protected $subscribe = [Placed::class => []]; }')))->toBe([])
        ->and(ListenerSignature::listenMapFetches($find('class P extends \Illuminate\Foundation\Support\Providers\EventServiceProvider { protected $listen = [Placed::class => [], Paid::class => []]; }')))->toHaveCount(2)
        ->and(ListenerSignature::listenMapFetches($find('class P extends \Illuminate\Support\ServiceProvider { protected $listen = [Placed::class => []]; }')))->toBe([])
        ->and(ListenerSignature::listenMapFetches($find('class P { protected $listen = [Placed::class => []]; }')))->toBe([]);
});

it('reads the $listen map when several properties are declared together', function () {
    $class = (new NodeFinder)->findFirstInstanceOf(listener_ast('class P extends \Illuminate\Foundation\Support\Providers\EventServiceProvider { protected $subscribe = [], $listen = [Placed::class => []]; }'), Node\Stmt\Class_::class);

    expect(listener_fetched(ListenerSignature::listenMapFetches($class)))->toBe(['Shop\\Events\\Placed']);
});

it('finds the classes named by Event::listen, alone or in an array', function (string $call, array $expected) {
    expect(listener_fetched(ListenerSignature::listenedClassFetches(listener_call($call))))->toBe($expected);
})->with([
    'one class' => ['Event::listen(Placed::class, $listener);', ['Shop\\Events\\Placed']],
    'an array of classes' => ['Event::listen([Placed::class, Paid::class], $listener);', ['Shop\\Events\\Placed', 'Shop\\Events\\Paid']],
    'an array with a string' => ["Event::listen([Placed::class, 'paid'], \$listener);", ['Shop\\Events\\Placed']],
    'a string' => ["Event::listen('placed', \$listener);", []],
    'a constant' => ['Event::listen(Placed::NAME, $listener);', []],
    'a dynamic constant name' => ['Event::listen(Placed::{$name}, $listener);', []],
    'a dynamic class constant' => ['Event::listen($class::class, $listener);', []],
    'a variable' => ['Event::listen($events, $listener);', []],
    'another method' => ['Event::dispatch(Placed::class);', []],
    'another class' => ['Bus::listen(Placed::class, $listener);', []],
    'no argument' => ['Event::listen();', []],
    'a first-class callable' => ['Event::listen(...);', []],
]);
