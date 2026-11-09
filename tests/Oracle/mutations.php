<?php

/*
 * Known bugs, put back into a copy of src/ to check that the position
 * matrix finds them (the positive control of php-oracles). Each one is a
 * plain search-and-replace that must match exactly once.
 *
 * @return array<string, array{file: string, search: string, replace: string, why: string}>
 */
return [
    'case-sensitive-modules' => [
        'file' => 'Module/Module.php',
        'search' => "return str_starts_with(strtolower(ltrim(\$fqcn, '\\\\')), strtolower(\$this->namespace).'\\\\');",
        'replace' => "return str_starts_with(ltrim(\$fqcn, '\\\\'), \$this->namespace.'\\\\');",
        'why' => 'Bug #26: a class written in other letter case belonged to no module.',
    ],
    'skip-attributes' => [
        'file' => 'Analysis/ReferenceCollector.php',
        'search' => "    public function enterNode(Node \$node)\n    {\n",
        'replace' => "    public function enterNode(Node \$node)\n    {\n        if (\$node instanceof Node\\Attribute) {\n            \$node->name->setAttribute(self::SKIP, true);\n        }\n\n",
        'why' => 'A collector that does not count attribute names.',
    ],
    'skip-property-hooks' => [
        'file' => 'Analysis/ReferenceCollector.php',
        'search' => "    public function enterNode(Node \$node)\n    {\n",
        'replace' => "    public function enterNode(Node \$node)\n    {\n        if (\$node instanceof Node\\PropertyHook) {\n            return \\PhpParser\\NodeVisitor::DONT_TRAVERSE_CHILDREN;\n        }\n\n",
        'why' => 'What php-parser 5.2 did: names inside property hooks were lost.',
    ],
];
