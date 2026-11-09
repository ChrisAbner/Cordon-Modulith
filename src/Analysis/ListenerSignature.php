<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name\FullyQualified;

/**
 * Where a listener names the event it handles, shared by the dependency
 * analysis (listener edges) and the generated event inventory. Expects names
 * already resolved by PhpParser's NameResolver.
 *
 * @internal
 */
final class ListenerSignature
{
    private const EVENT_FACADE = 'Illuminate\\Support\\Facades\\Event';

    private const EVENT_SERVICE_PROVIDER = 'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider';

    /**
     * The class types of the first parameter of handle() or __invoke(), unions included.
     * Callers decide whether those are events: a job's handle() receives services too.
     *
     * @return list<FullyQualified>
     */
    public static function eventTypes(Node\Stmt\ClassMethod $method): array
    {
        if (! in_array($method->name->toLowerString(), ['handle', '__invoke'], true)) {
            return [];
        }

        return self::firstParameterTypes($method);
    }

    /**
     * The class types of the first parameter of a function, method or closure, unions included.
     *
     * @return list<FullyQualified>
     */
    public static function firstParameterTypes(Node\FunctionLike $function): array
    {
        return self::classNames($function->getParams()[0]->type ?? null);
    }

    /**
     * The closure passed as the listener of Event::listen(function (Event $e) {...}), if any.
     */
    public static function listenedClosure(Expr\StaticCall $call): Expr\Closure|Expr\ArrowFunction|null
    {
        $value = self::isEventListen($call) && ($call->args[0] ?? null) instanceof Node\Arg ? $call->args[0]->value : null;

        return $value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction ? $value : null;
    }

    /**
     * The X::class keys of the $listen map of an EventServiceProvider, what nwidart generates.
     * Expects the parent class already resolved.
     *
     * @return list<Expr\ClassConstFetch>
     */
    public static function listenMapFetches(Node\Stmt\Class_ $class): array
    {
        if (! $class->extends instanceof FullyQualified || strcasecmp($class->extends->toString(), self::EVENT_SERVICE_PROVIDER) !== 0) {
            return [];
        }

        $fetches = [];

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== 'listen' || ! $item->default instanceof Expr\Array_) {
                    continue;
                }

                foreach ($item->default->items as $entry) {
                    if (self::isClassFetch($entry->key)) {
                        $fetches[] = $entry->key;
                    }
                }
            }
        }

        return $fetches;
    }

    /**
     * The X::class expressions in the first argument of Event::listen(X::class, ...), alone or in an array.
     *
     * Their class may still be unresolved when a visitor runs in the same pass as
     * the NameResolver, which resolves it on entering the expression.
     *
     * @return list<Expr\ClassConstFetch>
     */
    public static function listenedClassFetches(Expr\StaticCall $call): array
    {
        if (! self::isEventListen($call)) {
            return [];
        }

        $argument = $call->args[0] ?? null;

        if (! $argument instanceof Node\Arg) {
            return [];
        }

        $values = $argument->value instanceof Expr\Array_
            ? array_map(static fn (Node\ArrayItem $item): Expr => $item->value, $argument->value->items)
            : [$argument->value];

        $fetches = [];

        foreach ($values as $value) {
            if (self::isClassFetch($value)) {
                $fetches[] = $value;
            }
        }

        return $fetches;
    }

    private static function isEventListen(Expr\StaticCall $call): bool
    {
        return $call->class instanceof FullyQualified
            && strcasecmp($call->class->toString(), self::EVENT_FACADE) === 0
            && $call->name instanceof Node\Identifier
            && $call->name->toLowerString() === 'listen';
    }

    /**
     * @phpstan-assert-if-true Expr\ClassConstFetch $node
     */
    private static function isClassFetch(?Node $node): bool
    {
        return $node instanceof Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && $node->name->toLowerString() === 'class';
    }

    /**
     * @return list<FullyQualified>
     */
    private static function classNames(?Node $type): array
    {
        return match (true) {
            $type instanceof FullyQualified => [$type],
            $type instanceof Node\NullableType => self::classNames($type->type),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_merge(...array_map(
                static fn (Node $inner): array => self::classNames($inner),
                $type->types,
            )),
            default => [],
        };
    }
}
