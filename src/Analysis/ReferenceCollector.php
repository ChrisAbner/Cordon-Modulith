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
            $fqcn = $node->namespacedName?->toString();

            if ($fqcn !== null) {
                $this->declarations[] = new ClassDeclaration($fqcn, $this->file, $node->getStartLine(), $this->visibilityOf($node));
            }

            // Anonymous classes are attributed to the enclosing class.
            $this->classStack[] = $fqcn ?? $this->currentClass();

            return null;
        }

        // Function and constant names are not class dependencies.
        if (($node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\ConstFetch) && $node->name instanceof Node\Name) {
            $node->name->setAttribute(self::SKIP, true);

            return null;
        }

        if ($node instanceof Node\Name\FullyQualified && $node->getAttribute(self::SKIP) !== true) {
            $this->references[] = new Reference($this->file, $this->currentClass(), $node->toString(), $node->getStartLine());
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
