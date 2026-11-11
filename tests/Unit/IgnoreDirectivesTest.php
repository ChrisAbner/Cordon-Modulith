<?php

use Cordon\Analysis\IgnoreDirective;
use Cordon\Analysis\IgnoreDirectives;

mutates(IgnoreDirectives::class);

/**
 * @return list<IgnoreDirective>
 */
function directives(string $code): array
{
    return IgnoreDirectives::parse("<?php\n".$code);
}

it('reads a directive with its rules, its reason and the lines it covers', function () {
    $directive = directives(implode("\n", [
        '',
        '// @cordon-ignore internal_access, undeclared_dependency (the invoice copies the price)',
        '',
        'new Price;',
    ]))[0];

    expect($directive->line)->toBe(3)
        ->and($directive->rules)->toBe(['internal_access', 'undeclared_dependency'])
        ->and($directive->reason)->toBe('the invoice copies the price')
        ->and($directive->lines)->toBe([3, 5]);
});

it('covers only its own line when it follows the code', function () {
    expect(directives("new Product; // @cordon-ignore internal_access (shown on the PDF)\nnew Price;")[0]->lines)->toBe([2])
        ->and(directives('new Product; /* @cordon-ignore internal_access (shown) */')[0]->rules)->toBe(['internal_access'])
        ->and(directives('new Product; # @cordon-ignore internal_access (shown)')[0]->reason)->toBe('shown');
});

it('covers the next line when it starts with a symbol', function () {
    expect(directives("// @cordon-ignore internal_access (a callable)\n\n(new Product)->run();")[0]->lines)->toBe([2, 4]);
});

it('keeps a directive without a reason, so it can be reported', function (string $comment) {
    expect(directives($comment."\nnew Product;")[0]->reason)->toBeNull();
})->with([
    'no parentheses' => '// @cordon-ignore internal_access',
    'empty parentheses' => '// @cordon-ignore internal_access ( )',
]);

it('ignores the words in docblocks, strings and other comments', function () {
    expect(directives(implode("\n", [
        '/** @cordon-ignore internal_access (a docblock is documentation) */',
        '$text = "// @cordon-ignore internal_access (a string)";',
        '// see @cordon-ignore-file, not a directive',
    ])))->toBe([]);
});

it('skips files that do not mention it', function () {
    expect(IgnoreDirectives::parse('<?php new Product;'))->toBe([]);
});

it('covers the whole statement that follows it, over several lines', function () {
    expect(directives(implode("\n", [
        '// @cordon-ignore internal_access (the form shows the product)',
        'function show(',
        '    int $id,',
        '    Product $product,',
        ') {',
        '    new Price;',
        '}',
    ]))[0]->lines)->toBe([2, 3, 4, 5, 6]);
});

it('allows parentheses inside the reason', function () {
    expect(directives("// @cordon-ignore internal_access (the PDF (and the email) show it)\nnew Product;")[0]->reason)->toBe('the PDF (and the email) show it');
});

it('keeps a directive that names no rule, so it can be reported', function () {
    expect(directives("// @cordon-ignore (no rule)\nnew Product;")[0]->rules)->toBe([]);
});

it('does not mistake the text of inline HTML or a heredoc for a directive', function () {
    expect(IgnoreDirectives::parse("<?php\n\$text = <<<TEXT\n@cordon-ignore internal_access (a heredoc)\nTEXT;\n?>\n@cordon-ignore internal_access (inline HTML)\n"))->toBe([]);
});

it('finds a directive after a comment that is not one', function () {
    $found = directives("// just a note\n// @cordon-ignore internal_access (the PDF shows it)\nnew Product;");

    expect($found)->toHaveCount(1)
        ->and($found[0]->line)->toBe(3);
});

it('lists each line once and in order when the comment shares its line with the code', function () {
    expect(directives("/* @cordon-ignore internal_access (a callable) */ function show(\n    int \$id,\n) {\n}")[0]->lines)->toBe([2, 3, 4]);
});

it('drops empty rule names without leaving gaps in the list', function () {
    expect(directives("// @cordon-ignore internal_access,, undeclared_dependency, (both)\nnew Product;")[0]->rules)->toBe(['internal_access', 'undeclared_dependency']);
});

it('treats an indented comment on its own line as covering the statement below', function () {
    expect(directives("    // @cordon-ignore internal_access (indented)\n    new Product;")[0]->lines)->toBe([2, 3]);
});

it('treats a comment right after a statement, with no space, as a trailing comment', function () {
    expect(directives("new Product;// @cordon-ignore internal_access (no space)\nnew Price;")[0]->lines)->toBe([2]);
});

it('treats a comment after a name in the middle of a statement as a trailing comment', function () {
    expect(directives("new Product // @cordon-ignore internal_access (the name)\n;")[0]->lines)->toBe([2]);
});

it('treats a comment on the line of the opening tag as a trailing comment', function () {
    expect(IgnoreDirectives::parse("<?php // @cordon-ignore internal_access (the tag line)\nnew Product;")[0]->lines)->toBe([1]);
});

it('treats a comment that follows a multi-line block comment as being on its own line', function () {
    expect(directives("/* a note\n over two lines */ // @cordon-ignore internal_access (below)\nnew Product;")[0]->lines)->toBe([3, 4]);
});

it('covers the line where a multi-line block comment ends when a symbol follows it', function () {
    expect(directives("/* @cordon-ignore internal_access (a callable)\n */(new Product)->run();")[0]->lines)->toBe([2, 3]);
});

it('starts at the first token of the statement even when a later token is a symbol', function () {
    expect(directives("// @cordon-ignore internal_access (split)\n\$product\n    = new Product;")[0]->lines)->toBe([2, 3, 4]);
});

it('skips the comments and docblocks between the directive and the statement', function () {
    expect(directives("// @cordon-ignore internal_access (a note below)\n// the reason continues here\nnew Product;")[0]->lines)->toBe([2, 4])
        ->and(directives("// @cordon-ignore internal_access (a docblock below)\n/** @var Product \$product */\n\$product = new Product;")[0]->lines)->toBe([2, 4]);
});

it('ends the statement at its semicolon, not at the end of the file', function () {
    expect(directives("// @cordon-ignore internal_access (one line)\nnew Product;\nnew Price;\nnew Stock;")[0]->lines)->toBe([2, 3]);
});

it('ends a declaration at its opening brace', function () {
    expect(directives("// @cordon-ignore internal_access (a signature)\nfunction show(Product \$product)\n{\n    new Price;\n}")[0]->lines)->toBe([2, 3, 4]);
});

it('keeps going through a closure inside parentheses', function () {
    expect(directives(implode("\n", [
        '// @cordon-ignore internal_access (the closure builds products)',
        '$all = array_map(function ($id) {',
        '    return new Product($id);',
        '}, $ids);',
        'next();',
    ]))[0]->lines)->toBe([2, 3, 4, 5]);
});

it('keeps going through a closure inside brackets', function () {
    expect(directives(implode("\n", [
        '// @cordon-ignore internal_access (the closure builds a product)',
        '$map = [',
        "    'a' => function () { return new Product; },",
        "    'b' => 1,",
        '];',
        'next();',
    ]))[0]->lines)->toBe([2, 3, 4, 5, 6]);
});

it('keeps going through the semicolons of a loop header', function () {
    expect(directives(implode("\n", [
        '// @cordon-ignore internal_access (the loop builds products)',
        'for (',
        '    $i = 0;',
        '    $i < 3;',
        '    $i++',
        ') {',
        '    new Product;',
        '}',
    ]))[0]->lines)->toBe([2, 3, 4, 5, 6, 7]);
});

it('covers only its own line when nothing follows it', function () {
    expect(directives('// @cordon-ignore internal_access (the end of the file)')[0]->lines)->toBe([2])
        ->and(directives("// @cordon-ignore internal_access (blank lines and comments)\n\n// the end\n")[0]->lines)->toBe([2]);
});

it('covers the code up to the end of the file when the statement is not terminated', function () {
    expect(directives("// @cordon-ignore internal_access (no semicolon)\nnew Product")[0]->lines)->toBe([2, 3]);
});
