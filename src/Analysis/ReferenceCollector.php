<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Attributes\Internal;
use Cordon\Attributes\PublicApi;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects class declarations and fully qualified class references.
 *
 * Must run after PhpParser's NameResolver, which turns every class name in a
 * class position into a Name\FullyQualified node. Import statements (use ...)
 * are plain Name nodes and therefore never count as a dependency on their own.
 *
 * @internal
 */
final class ReferenceCollector extends NodeVisitorAbstract
{
    private const SKIP = 'cordon.skip';

    private const KIND = 'cordon.kind';

    private const RELATION = 'cordon.relation';

    private const SIGNATURE = 'cordon.signature';

    /** Eloquent methods whose first argument is the related model. */
    private const RELATIONS = ['belongsto', 'hasone', 'hasmany', 'belongstomany', 'hasonethrough', 'hasmanythrough', 'morphone', 'morphmany', 'morphtomany', 'morphedbymany'];

    /** @var list<ClassDeclaration> */
    private array $declarations = [];

    /** @var list<Reference> */
    private array $references = [];

    /** @var list<string|null> */
    private array $classStack = [];

    /** @var list<bool> Per open class: whether it is named (members of anonymous classes are no API). */
    private array $namedStack = [];

    /**
     * Per open class: the index of its first reference, and the events its
     * handle() or __invoke() handles, so its other methods (failed()) count too.
     *
     * @var list<array{start: int, events: array<string, true>}>
     */
    private array $listenerStack = [];

    public function __construct(private readonly string $file) {}

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            // PHPStan's AST leaves namespacedName unset on anonymous classes.
            $fqcn = isset($node->namespacedName) ? $node->namespacedName->toString() : null;

            if ($fqcn !== null) {
                $interfaces = match (true) {
                    $node instanceof Node\Stmt\Class_, $node instanceof Node\Stmt\Enum_ => $node->implements,
                    $node instanceof Node\Stmt\Interface_ => $node->extends,
                    default => [],
                };

                $this->declarations[] = new ClassDeclaration(
                    $fqcn,
                    $this->file,
                    $node->getStartLine(),
                    $this->visibilityOf($node),
                    $node instanceof Node\Stmt\Class_ ? $node->extends?->toString() : null,
                    array_values(array_map(static fn (Node\Name $name): string => $name->toString(), $interfaces)),
                );
            }

            // Anonymous classes are attributed to the enclosing class.
            $this->classStack[] = $fqcn ?? $this->currentClass();
            $this->namedStack[] = $fqcn !== null;
            $this->listenerStack[] = ['start' => count($this->references), 'events' => []];

            if ($node instanceof Node\Stmt\Class_) {
                foreach (ListenerSignature::listenMapFetches($node) as $fetch) {
                    $fetch->setAttribute(self::KIND, Reference::LISTENER);
                }
            }

            return null;
        }

        if (end($this->namedStack) === true) {
            $this->markPublicSignature($node);
        }

        // A listener's event is marked before its name is visited: such an edge
        // does not close a dependency cycle (see Reference::LISTENER).
        if ($node instanceof Node\Stmt\ClassMethod && $this->currentClass() !== null) {
            foreach (ListenerSignature::eventTypes($node) as $name) {
                // handle() also receives injected services (jobs, commands): only
                // a class under an Events namespace counts as the handled event.
                // Class names are case-insensitive, so the segment is compared in lowercase.
                if (in_array('events', array_slice(explode('\\', strtolower($name->toString())), 0, -1), true)) {
                    $name->setAttribute(self::KIND, Reference::LISTENER);

                    if (($top = array_key_last($this->listenerStack)) !== null) {
                        $this->listenerStack[$top]['events'][strtolower($name->toString())] = true;
                    }
                }
            }
        }

        if ($node instanceof Node\Expr\StaticCall) {
            foreach (ListenerSignature::listenedClassFetches($node) as $fetch) {
                $fetch->setAttribute(self::KIND, Reference::LISTENER);
            }

            ListenerSignature::listenedClosure($node)?->setAttribute(self::KIND, Reference::LISTENER);
        }

        // A closure registered with Event::listen: its parameter types are resolved on entering it.
        if (($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) && $node->getAttribute(self::KIND) === Reference::LISTENER) {
            foreach (ListenerSignature::firstParameterTypes($node) as $name) {
                $name->setAttribute(self::KIND, Reference::LISTENER);
            }
        }

        // $this->belongsTo(Model::class): a relation ties the tables of two modules together.
        if ($node instanceof Node\Expr\MethodCall
            && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier && in_array($node->name->toLowerString(), self::RELATIONS, true)
            && ($node->args[0] ?? null) instanceof Node\Arg && $node->args[0]->value instanceof Node\Expr\ClassConstFetch) {
            $node->args[0]->value->setAttribute(self::RELATION, true);
        }

        // The NameResolver replaces the class of X::class on entering it, so the
        // marks move from the expression to its (now resolved) name here.
        if ($node instanceof Node\Expr\ClassConstFetch) {
            if ($node->getAttribute(self::KIND) === Reference::LISTENER) {
                $node->class->setAttribute(self::KIND, Reference::LISTENER);
            }

            if ($node->getAttribute(self::RELATION) === true) {
                $node->class->setAttribute(self::RELATION, true);
            }
        }

        // Function and constant names are not class dependencies.
        if (($node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\ConstFetch) && $node->name instanceof Node\Name) {
            $node->name->setAttribute(self::SKIP, true);

            return null;
        }

        if ($node instanceof Node\Name\FullyQualified && $node->getAttribute(self::SKIP) !== true) {
            $kind = $node->getAttribute(self::KIND) === Reference::LISTENER ? Reference::LISTENER : Reference::USE;
            $signature = $node->getAttribute(self::SIGNATURE);
            $this->references[] = new Reference($this->file, $this->currentClass(), $node->toString(), $node->getStartLine(), $kind, $node->getAttribute(self::RELATION) === true, is_string($signature) ? $signature : null);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);
            array_pop($this->namedStack);
            $this->markHandledEvents((array) array_pop($this->listenerStack));
        }

        return null;
    }

    /**
     * Marks the class names of a public member's signature (the NameResolver
     * has already resolved them): public methods' parameters and return type,
     * public properties (promoted ones too) and public typed constants. A
     * constructor's other parameters are not API: the container passes them.
     */
    private function markPublicSignature(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassMethod && $node->isPublic()) {
            $method = $node->name->toString();

            foreach ($node->params as $param) {
                $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : '';

                if ($param->flags !== 0) {
                    if (($param->flags & (Modifiers::PRIVATE | Modifiers::PROTECTED)) === 0) {
                        self::mark($param->type, '$'.$name.' has type');
                    }
                } elseif ($method !== '__construct') {
                    self::mark($param->type, $method.'() takes');
                }
            }

            self::mark($node->returnType, $method.'() returns');
        }

        if ($node instanceof Node\Stmt\Property && $node->isPublic() && $node->props !== []) {
            self::mark($node->type, '$'.$node->props[0]->name->toString().' has type');
        }

        if ($node instanceof Node\Stmt\ClassConst && $node->isPublic() && $node->consts !== []) {
            self::mark($node->type, $node->consts[0]->name->toString().' has type');
        }
    }

    private static function mark(?Node $type, string $signature): void
    {
        match (true) {
            $type instanceof Node\Name\FullyQualified => $type->setAttribute(self::SIGNATURE, $signature),
            $type instanceof Node\NullableType => self::mark($type->type, $signature),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_map(static fn (Node $member) => self::mark($member, $signature), $type->types),
            default => null,
        };
    }

    /**
     * In a listener, every reference to the event it handles is the listener's,
     * wherever it appears (failed(), a helper method), before or after handle().
     *
     * @param  array{start?: int, events?: array<string, true>}  $class
     */
    private function markHandledEvents(array $class): void
    {
        $events = $class['events'] ?? [];

        if ($events === []) {
            return;
        }

        $start = $class['start'] ?? count($this->references);
        $references = [];

        foreach ($this->references as $i => $reference) {
            $references[] = $i >= $start && isset($events[strtolower($reference->target)]) && $reference->kind === Reference::USE
                ? new Reference($reference->sourceFile, $reference->sourceClass, $reference->target, $reference->line, Reference::LISTENER, $reference->relation, $reference->signature)
                : $reference;
        }

        $this->references = $references;
    }

    /**
     * @return list<ClassDeclaration>
     */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /**
     * @return list<Reference>
     */
    public function references(): array
    {
        return $this->references;
    }

    private function currentClass(): ?string
    {
        return $this->classStack === [] ? null : $this->classStack[array_key_last($this->classStack)];
    }

    private function visibilityOf(Node\Stmt\ClassLike $node): DeclaredVisibility
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = ltrim($attribute->name->toString(), '\\');

                if (strcasecmp($name, Internal::class) === 0) {
                    return DeclaredVisibility::Internal;
                }

                if (strcasecmp($name, PublicApi::class) === 0) {
                    return DeclaredVisibility::PublicApi;
                }
            }
        }

        return DeclaredVisibility::Unspecified;
    }
}
