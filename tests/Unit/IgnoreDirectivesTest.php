<?php

use Cordon\Analysis\IgnoreDirective;
use Cordon\Analysis\IgnoreDirectives;

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
