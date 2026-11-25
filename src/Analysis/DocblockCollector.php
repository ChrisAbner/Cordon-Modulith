<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Exception;
use PhpParser\Comment\Doc;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node as DocNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser as DocTraverser;
use PHPStan\PhpDocParser\Ast\PhpDoc\ExtendsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ImplementsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MixinTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\UsesTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Reads the class names written in docblock types (the `docblocks` config key):
 * the tags param, return, var, property (read and write), method, throws, extends,
 * implements, use and mixin, the bound of template, and the phpstan-type and
 * import-type aliases.
 *
 * Names are resolved like PHP does, with the namespace and imports of the file,
 * which this visitor follows itself so it works on the AST of the extractor and
 * on the one PHPStan hands to its rules. Anything that could be something else
 * is not a reference: template names and type aliases in scope, PHP and PHPDoc
 * pseudo-types (array-key, positive-int, class-string...), self, static and
 * $this, and every tag or type that does not parse.
 *
 * Must see the same nodes as ReferenceCollector, in the same traversal.
 *
 * @internal
 */
final class DocblockCollector extends NodeVisitorAbstract
{
    /** Reserved by PHP: never a class, whatever the imports say. */
    private const RESERVED = ['int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never', 'array', 'callable', 'self', 'static', 'parent', 'list', 'resource', 'integer', 'double', 'boolean'];

    /** PHPDoc pseudo-types without a hyphen: a class only when imported explicitly. */
    private const PSEUDO = ['scalar', 'numeric', 'number', 'real', 'empty', 'unset', 'noreturn', 'min', 'max'];

    /** @var list<Reference> */
    private array $references = [];

    private NameContext $names;

    /** @var array<string, true> The lowercased aliases imported with a plain `use` in the current namespace. */
    private array $imports = [];

    /** @var list<string|null> */
    private array $classStack = [];

    /**
     * Per open class or function: the names its docblock declares (templates, type aliases).
     *
     * @var list<array<string, true>>
     */
    private array $scopes = [];

    /** @var list<bool> Per open node, whether it opened a scope. */
    private array $opened = [];

    /** @var array<string, true> The docblocks already read: the parser attaches one to several nodes. */
    private array $seen = [];

    /** @var array<string, true> */
    private array $added = [];

    private Lexer $lexer;

    private PhpDocParser $parser;

    public function __construct(private readonly string $file)
    {
        $this->names = new NameContext(new Collecting);
        $this->names->startNamespace();

        $config = new ParserConfig(['lines' => true, 'indexes' => true]);
        $constants = new ConstExprParser($config);
        $this->lexer = new Lexer($config);
        $this->parser = new PhpDocParser($config, new TypeParser($config, $constants), $constants);
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->names->startNamespace($node->name);
            $this->imports = [];
        }

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->import($use->name, $use, $node->type);
            }
        }

        if ($node instanceof Node\Stmt\GroupUse) {
            foreach ($node->uses as $use) {
                $this->import(Node\Name::concat($node->prefix, $use->name) ?? $use->name, $use, $node->type);
            }
        }

        if ($node instanceof Node\Stmt\ClassLike) {
            // Anonymous classes are attributed to the enclosing class, like ReferenceCollector does.
            $this->classStack[] = $node->name !== null ? Node\Name::concat($this->names->getNamespace(), $node->name->toString())?->toString() : $this->currentClass();
        }

        $scope = $node instanceof Node\Stmt\ClassLike || $node instanceof Node\FunctionLike;
        $this->opened[] = $scope;
        $docs = $this->docblocks($node);

        if ($scope) {
            $declared = [];

            foreach ($docs as $doc) {
                $declared += $doc['declared'];
            }

            $this->scopes[] = $declared;
        }

        foreach ($docs as $doc) {
            try {
                $this->read($doc);
            } catch (Exception) {
                // A docblock the parser accepted but the walk cannot make sense of is nobody's dependency either.
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if (array_pop($this->opened) === true) {
            array_pop($this->scopes);
        }

        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);
        }

        return null;
    }

    /**
     * @return list<Reference>
     */
    public function references(): array
    {
        return $this->references;
    }

    private function import(Node\Name $name, Node\UseItem $use, int $type): void
    {
        $type |= $use->type;

        if ($type !== Node\Stmt\Use_::TYPE_NORMAL) {
            return;
        }

        $alias = $use->getAlias()->toString();
        $this->names->addAlias($name, $alias, Node\Stmt\Use_::TYPE_NORMAL);
        $this->imports[strtolower($alias)] = true;
    }

    /**
     * The docblocks of a node that are worth reading and not read yet, parsed.
     *
     * @return list<array{doc: PhpDocNode, line: int, declared: array<string, true>}>
     */
    private function docblocks(Node $node): array
    {
        $docs = [];

        foreach ($node->getComments() as $comment) {
            if (! $comment instanceof Doc || ! str_contains($comment->getText(), '@')) {
                continue;
            }

            $key = $comment->getStartFilePos().':'.$comment->getStartLine().':'.crc32($comment->getText());

            if (isset($this->seen[$key])) {
                continue;
            }

            $this->seen[$key] = true;

            try {
                $doc = $this->parser->parse(new TokenIterator($this->lexer->tokenize($comment->getText())));
            } catch (Exception) {
                // A docblock that does not parse is nobody's dependency.
                continue;
            }

            $docs[] = ['doc' => $doc, 'line' => $comment->getStartLine(), 'declared' => $this->declaredIn($doc)];
        }

        return $docs;
    }

    /**
     * The template and type alias names a docblock declares: they are not classes inside their scope.
     *
     * @return array<string, true>
     */
    private function declaredIn(PhpDocNode $doc): array
    {
        $declared = [];

        foreach ($doc->getTags() as $tag) {
            $value = $tag->value;

            if ($value instanceof TemplateTagValueNode) {
                $declared[$value->name] = true;
            } elseif ($value instanceof TypeAliasTagValueNode) {
                $declared[$value->alias] = true;
            } elseif ($value instanceof TypeAliasImportTagValueNode) {
                $declared[$value->importedAs ?? $value->importedAlias] = true;
            }
        }

        return $declared;
    }

    /**
     * @param  array{doc: PhpDocNode, line: int, declared: array<string, true>}  $docblock
     */
    private function read(array $docblock): void
    {
        $scope = array_merge($docblock['declared'], ...$this->scopes);

        foreach ($docblock['doc']->getTags() as $tag) {
            $value = $tag->value;
            $types = [];
            $local = $scope;

            switch (true) {
                case $value instanceof ParamTagValueNode:
                case $value instanceof ReturnTagValueNode:
                case $value instanceof VarTagValueNode:
                case $value instanceof PropertyTagValueNode:
                case $value instanceof ThrowsTagValueNode:
                case $value instanceof MixinTagValueNode:
                case $value instanceof ExtendsTagValueNode:
                case $value instanceof ImplementsTagValueNode:
                case $value instanceof UsesTagValueNode:
                case $value instanceof TypeAliasTagValueNode:
                    $types[] = $value->type;

                    break;
                case $value instanceof TemplateTagValueNode:
                    $types[] = $value->bound;

                    break;
                case $value instanceof TypeAliasImportTagValueNode:
                    $types[] = $value->importedFrom;

                    break;
                case $value instanceof MethodTagValueNode:
                    $types[] = $value->returnType;

                    foreach ($value->parameters as $parameter) {
                        $types[] = $parameter->type;
                    }

                    foreach ($value->templateTypes as $template) {
                        $local[$template->name] = true;
                        $types[] = $template->bound;
                    }

                    break;
            }

            foreach ($types as $type) {
                if ($type instanceof TypeNode) {
                    $this->walk($type, $docblock['line'], $local);
                }
            }
        }
    }

    /**
     * @param  array<string, true>  $declared
     */
    private function walk(TypeNode $type, int $base, array $declared): void
    {
        $visitor = new class(fn (string $written, ?int $line) => $this->add($written, $line === null ? $base : $base + $line - 1, $declared)) extends AbstractNodeVisitor
        {
            /** @param \Closure(string, ?int): void $found */
            public function __construct(private readonly \Closure $found) {}

            /** @return int|null */
            public function enterNode(DocNode $node)
            {
                if ($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) {
                    // The key of a shape is a name, not a class: only its value type is.
                    (new DocTraverser([$this]))->traverse([$node->valueType]);

                    return DocTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof IdentifierTypeNode) {
                    ($this->found)($node->name, self::line($node));
                }

                // A class constant (Foo::BAR, int-mask-of<Foo::*>): the class is the name. A plain constant has none.
                if ($node instanceof ConstFetchNode && $node->className !== '') {
                    ($this->found)($node->className, self::line($node));
                }

                return null;
            }

            private static function line(DocNode $node): ?int
            {
                $line = $node->getAttribute(Attribute::START_LINE);

                return is_int($line) ? $line : null;
            }
        };

        (new DocTraverser([$visitor]))->traverse([$type]);
    }

    /**
     * @param  array<string, true>  $declared
     */
    private function add(string $written, int $line, array $declared): void
    {
        $target = $this->resolve($written, $declared);

        if ($target === null) {
            return;
        }

        $key = strtolower($this->currentClass().'|'.$target.'|'.$line);

        if (isset($this->added[$key])) {
            return;
        }

        $this->added[$key] = true;
        $this->references[] = new Reference($this->file, $this->currentClass(), $target, $line, Reference::DOCBLOCK);
    }

    /**
     * The fully qualified class a written name stands for, or null when it
     * could be anything else.
     *
     * @param  array<string, true>  $declared
     */
    private function resolve(string $written, array $declared): ?string
    {
        // A class name has letters, digits and underscores in segments; anything else (a hyphen, a dot, a digit first) is a keyword.
        if (preg_match('/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $written) !== 1) {
            return null;
        }

        $segments = explode('\\', ltrim($written, '\\'));
        $first = strtolower($segments[0]);

        if (count($segments) === 1) {
            $imported = isset($this->imports[$first]) && ! str_starts_with($written, '\\');

            if (isset($declared[$written]) || in_array($first, self::RESERVED, true) || (! $imported && in_array($first, self::PSEUDO, true))) {
                return null;
            }
        }

        $name = match (true) {
            str_starts_with($written, '\\') => new Node\Name\FullyQualified(ltrim($written, '\\')),
            $first === 'namespace' && count($segments) > 1 => new Node\Name\Relative(implode('\\', array_slice($segments, 1))),
            default => new Node\Name($written),
        };

        return $this->names->getResolvedClassName($name)->toString();
    }

    private function currentClass(): ?string
    {
        return $this->classStack === [] ? null : $this->classStack[array_key_last($this->classStack)];
    }
}
