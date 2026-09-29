<?php

declare(strict_types=1);

namespace Cordon\Documentation;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

/**
 * Finds where events are dispatched and listened to. Runs after NameResolver.
 *
 * Dispatch: event(new X), broadcast(new X), $dispatcher->dispatch(new X),
 * Event::dispatch(new X), X::dispatch(), X::dispatchIf(), X::dispatchUnless(), X::broadcast().
 * Listen:   handle(X $event) / __invoke(X $event) in a class, Event::listen(X::class, ...).
 */
final class EventCollector extends NodeVisitorAbstract
{
    private const EVENT_FACADE = 'Illuminate\\Support\\Facades\\Event';

    private const STATIC_DISPATCH = ['dispatch', 'dispatchif', 'dispatchunless', 'broadcast'];

    /** @var list<EventUsage> */
    private array $usages = [];

    /** @var list<string|null> */
    private array $classStack = [];

    public function __construct(private readonly string $file) {}

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classStack[] = $node->namespacedName?->toString() ?? $this->currentClass();

            return null;
        }

        match (true) {
            $node instanceof Node\Stmt\ClassMethod => $this->listenerMethod($node),
            $node instanceof Expr\FuncCall => $this->functionCall($node),
            $node instanceof Expr\MethodCall => $this->methodCall($node),
            $node instanceof Expr\StaticCall => $this->staticCall($node),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);
        }

        return null;
    }

    /**
     * @return list<EventUsage>
     */
    public function usages(): array
    {
        return $this->usages;
    }

    private function listenerMethod(Node\Stmt\ClassMethod $node): void
    {
        if ($this->currentClass() === null || ! in_array($node->name->toLowerString(), ['handle', '__invoke'], true)) {
            return;
        }

        $type = $node->params[0]->type ?? null;

        foreach ($this->classNames($type) as $name) {
            $this->add(EventUsage::LISTEN, $name, $node->getStartLine(), false);
        }
    }

    private function functionCall(Expr\FuncCall $node): void
    {
        if ($node->name instanceof Name && in_array($node->name->toLowerString(), ['event', 'broadcast'], true)) {
            $this->dispatchedInstance($node->args[0] ?? null, $node->getStartLine());
        }
    }

    private function methodCall(Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier && in_array($node->name->toLowerString(), ['dispatch', 'broadcast'], true)) {
            $this->dispatchedInstance($node->args[0] ?? null, $node->getStartLine());
        }
    }

    private function staticCall(Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Name\FullyQualified || ! $node->name instanceof Node\Identifier) {
            return;
        }

        $class = $node->class->toString();
        $method = $node->name->toLowerString();

        if ($class === self::EVENT_FACADE) {
            if ($method === 'dispatch') {
                $this->dispatchedInstance($node->args[0] ?? null, $node->getStartLine());
            }

            if ($method === 'listen') {
                $this->listenedClasses($node->args[0] ?? null, $node->getStartLine());
            }

            return;
        }

        if (in_array($method, self::STATIC_DISPATCH, true)) {
            $this->add(EventUsage::DISPATCH, $class, $node->getStartLine(), false);
        }
    }

    private function dispatchedInstance(?Node $argument, int $line): void
    {
        if ($argument instanceof Arg && $argument->value instanceof Expr\New_ && $argument->value->class instanceof Name\FullyQualified) {
            $this->add(EventUsage::DISPATCH, $argument->value->class->toString(), $line, true);
        }
    }

    private function listenedClasses(?Node $argument, int $line): void
    {
        if (! $argument instanceof Arg) {
            return;
        }

        $values = $argument->value instanceof Expr\Array_
            ? array_map(static fn (Node\ArrayItem $item): Expr => $item->value, $argument->value->items)
            : [$argument->value];

        foreach ($values as $value) {
            if ($value instanceof Expr\ClassConstFetch
                && $value->class instanceof Name\FullyQualified
                && $value->name instanceof Node\Identifier
                && $value->name->toLowerString() === 'class') {
                $this->add(EventUsage::LISTEN, $value->class->toString(), $line, true);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function classNames(?Node $type): array
    {
        return match (true) {
            $type instanceof Name\FullyQualified => [$type->toString()],
            $type instanceof Node\NullableType => $this->classNames($type->type),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_merge(...array_map(
                fn (Node $inner): array => $this->classNames($inner),
                $type->types,
            )),
            default => [],
        };
    }

    /**
     * @param  EventUsage::DISPATCH|EventUsage::LISTEN  $kind
     */
    private function add(string $kind, string $event, int $line, bool $explicit): void
    {
        $this->usages[] = new EventUsage($kind, $event, $this->currentClass(), $this->file, $line, $explicit);
    }

    private function currentClass(): ?string
    {
        return $this->classStack === [] ? null : $this->classStack[array_key_last($this->classStack)];
    }
}
