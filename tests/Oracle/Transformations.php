<?php

declare(strict_types=1);

namespace Cordon\Tests\Oracle;

use Closure;
use FilesystemIterator;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Rewrites copies of fixture projects for the metamorphic tests: each
 * change must leave the analysis the same, or change it in a known way.
 * Names are rewritten through the AST, not with regular expressions.
 */
final class Transformations
{
    public static function copy(string $from): string
    {
        $to = Temp::directory('cordon-metamorphic-');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $target = $to.substr($file->getPathname(), strlen($from));
            $file->isDir() ? mkdir($target, 0777, true) : copy($file->getPathname(), $target);
        }

        return $to;
    }

    /**
     * Every class name written fully qualified (\A\B), with the imports removed.
     */
    public static function fullyQualified(string $root): void
    {
        self::rewrite($root, static fn (string $name): string => '\\'.$name, false);
    }

    /**
     * Every class name fully qualified and lowercased: PHP class names are case-insensitive.
     */
    public static function lowercase(string $root): void
    {
        self::rewrite($root, static fn (string $name): string => '\\'.strtolower($name), false);
    }

    /**
     * Every class name imported under an alias (use A\B as Alias1).
     */
    public static function aliased(string $root): void
    {
        self::rewrite($root, static fn (string $name): string => 'Alias'.substr(sha1(strtolower($name)), 0, 8), true);
    }

    /**
     * Adds an unused import, a docblock, a string and a comment that name
     * each of $classes to the end of every PHP file.
     *
     * @param  list<string>  $classes
     */
    public static function noise(string $root, array $classes): void
    {
        $lines = ["\nuse Noise\\Unused\\Thing;"];

        foreach ($classes as $class) {
            $lines[] = sprintf("/** @var \\%s \$noise */\n\$noise = '%s';\n// %s", $class, addslashes($class), $class);
        }

        foreach (self::files($root) as $file) {
            file_put_contents($file, rtrim((string) file_get_contents($file))."\n".implode("\n", $lines)."\n");
        }
    }

    /**
     * Renames a module of the namespace resolver: its directory under
     * $modules and its namespace segment, in every file.
     */
    public static function renameModule(string $root, string $modules, string $namespace, string $from, string $to): void
    {
        rename($root.'/'.$modules.'/'.$from, $root.'/'.$modules.'/'.$to);
        self::replaceText($root, $namespace.'\\'.$from.'\\', $namespace.'\\'.$to.'\\');
        self::replaceText($root, 'namespace '.$namespace.'\\'.$from.';', 'namespace '.$namespace.'\\'.$to.';');
    }

    /**
     * Moves a class to another namespace of its module: the file and every mention of its name.
     */
    public static function moveClass(string $root, string $from, string $fromFile, string $to, string $toFile): void
    {
        if (! is_dir(dirname($root.'/'.$toFile))) {
            mkdir(dirname($root.'/'.$toFile), 0777, true);
        }
        rename($root.'/'.$fromFile, $root.'/'.$toFile);
        $namespace = static fn (string $class): string => substr($class, 0, (int) strrpos($class, '\\'));

        self::replaceText($root, $from, $to);
        $moved = (string) file_get_contents($root.'/'.$toFile);
        file_put_contents($root.'/'.$toFile, str_replace('namespace '.$namespace($from).';', 'namespace '.$namespace($to).';', $moved));
    }

    /**
     * Adds a second reference to $class at the end of $file.
     */
    public static function duplicate(string $file, string $class): void
    {
        file_put_contents($file, rtrim((string) file_get_contents($file))."\n\n\\{$class}::class;\n");
    }

    /**
     * @param  Closure(string): string  $write  How a resolved class name is written.
     */
    private static function rewrite(string $root, Closure $write, bool $import): void
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $printer = new Standard;

        foreach (self::files($root) as $file) {
            $ast = $parser->parse((string) file_get_contents($file)) ?? [];
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $rewriter = new class($write) extends NodeVisitorAbstract
            {
                /** @var array<string, string> lowercased class => alias */
                public array $aliases = [];

                public function __construct(private readonly Closure $write) {}

                public function enterNode(Node $node)
                {
                    // Function and constant names are not classes.
                    if (($node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\ConstFetch) && $node->name instanceof Node\Name) {
                        $node->name->setAttribute('cordon.keep', true);
                    }

                    return null;
                }

                public function leaveNode(Node $node)
                {
                    if ($node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse) {
                        return $node->type === Node\Stmt\Use_::TYPE_NORMAL || $node->type === Node\Stmt\Use_::TYPE_UNKNOWN ? NodeTraverser::REMOVE_NODE : null;
                    }

                    if ($node instanceof Node\Name\FullyQualified && $node->getAttribute('cordon.keep') !== true) {
                        $written = ($this->write)($node->toString());

                        if (! str_starts_with($written, '\\')) {
                            $this->aliases[strtolower($node->toString())] = $node->toString().' as '.$written;

                            return new Node\Name($written, $node->getAttributes());
                        }

                        return new Node\Name\FullyQualified(ltrim($written, '\\'), $node->getAttributes());
                    }

                    return null;
                }
            };
            $traverser->addVisitor($rewriter);
            $ast = $traverser->traverse($ast);

            if ($import && $rewriter->aliases !== []) {
                ksort($rewriter->aliases);
                $uses = array_map(static fn (string $use): string => 'use '.$use.';', array_values($rewriter->aliases));
                $ast = self::withImports($ast, $parser->parse("<?php\n".implode("\n", $uses)) ?? []);
            }

            file_put_contents($file, $printer->prettyPrintFile($ast)."\n");
        }
    }

    /**
     * @param  array<Node\Stmt>  $ast
     * @param  array<Node\Stmt>  $imports
     * @return array<Node\Stmt>
     */
    private static function withImports(array $ast, array $imports): array
    {
        foreach ($ast as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                $statement->stmts = [...$imports, ...$statement->stmts];

                return $ast;
            }
        }

        return [...$imports, ...$ast];
    }

    private static function replaceText(string $root, string $search, string $replace): void
    {
        foreach (self::files($root) as $file) {
            $code = (string) file_get_contents($file);

            if (str_contains($code, $search)) {
                file_put_contents($file, str_replace($search, $replace, $code));
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function files(string $root): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/config/')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
