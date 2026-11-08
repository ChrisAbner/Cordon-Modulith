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

        return self::classNames($method->params[0]->type ?? null);
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
        if (! $call->class instanceof FullyQualified
            || $call->class->toString() !== self::EVENT_FACADE
            || ! $call->name instanceof Node\Identifier
            || $call->name->toLowerString() !== 'listen') {
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
            if ($value instanceof Expr\ClassConstFetch
                && $value->class instanceof Node\Name
                && $value->name instanceof Node\Identifier
                && $value->name->toLowerString() === 'class') {
                $fetches[] = $value;
            }
        }

        return $fetches;
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
