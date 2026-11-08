<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Attributes\Internal;
use Cordon\Attributes\PublicApi;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects class declarations and fully qualified class references.
 *
 * Must run after PhpParser's NameResolver, which turns every class name in a
 * class position into a Name\FullyQualified node. Import statements (use ...)
 * are plain Name nodes and therefore never count as a dependency on their own.
 */
final class ReferenceCollector extends NodeVisitorAbstract
{
    private const SKIP = 'cordon.skip';

    private const KIND = 'cordon.kind';

    /** @var list<ClassDeclaration> */
    private array $declarations = [];

    /** @var list<Reference> */
    private array $references = [];

    /** @var list<string|null> */
    private array $classStack = [];

    public function __construct(private readonly string $file) {}

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            // PHPStan's AST leaves namespacedName unset on anonymous classes.
            $fqcn = isset($node->namespacedName) ? $node->namespacedName->toString() : null;

            if ($fqcn !== null) {
                $this->declarations[] = new ClassDeclaration($fqcn, $this->file, $node->getStartLine(), $this->visibilityOf($node));
            }

            // Anonymous classes are attributed to the enclosing class.
            $this->classStack[] = $fqcn ?? $this->currentClass();

            return null;
        }

        // A listener's event is marked before its name is visited: such an edge
        // does not close a dependency cycle (see Reference::LISTENER).
        if ($node instanceof Node\Stmt\ClassMethod && $this->currentClass() !== null) {
            foreach (ListenerSignature::eventTypes($node) as $name) {
                // handle() also receives injected services (jobs, commands): only
                // a class under an Events namespace counts as the handled event.
                if (in_array('Events', array_slice(explode('\\', $name->toString()), 0, -1), true)) {
                    $name->setAttribute(self::KIND, Reference::LISTENER);
                }
            }
        }

        if ($node instanceof Node\Expr\StaticCall) {
            foreach (ListenerSignature::listenedClassFetches($node) as $fetch) {
                $fetch->setAttribute(self::KIND, Reference::LISTENER);
            }
        }

        // The NameResolver replaces the class of X::class on entering it, so the
        // mark moves from the expression to its (now resolved) name here.
        if ($node instanceof Node\Expr\ClassConstFetch && $node->getAttribute(self::KIND) === Reference::LISTENER) {
            $node->class->setAttribute(self::KIND, Reference::LISTENER);
        }

        // Function and constant names are not class dependencies.
        if (($node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\ConstFetch) && $node->name instanceof Node\Name) {
            $node->name->setAttribute(self::SKIP, true);

            return null;
        }

        if ($node instanceof Node\Name\FullyQualified && $node->getAttribute(self::SKIP) !== true) {
            $kind = $node->getAttribute(self::KIND) === Reference::LISTENER ? Reference::LISTENER : Reference::USE;
            $this->references[] = new Reference($this->file, $this->currentClass(), $node->toString(), $node->getStartLine(), $kind);
        }

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

                if ($name === Internal::class) {
                    return DeclaredVisibility::Internal;
                }

                if ($name === PublicApi::class) {
                    return DeclaredVisibility::PublicApi;
                }
            }
        }

        return DeclaredVisibility::Unspecified;
    }
}
