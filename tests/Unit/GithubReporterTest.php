<?php

use Cordon\Analysis\Result;
use Cordon\Module\ModuleMap;
use Cordon\Reporters\GithubReporter;

it('annotates the files it skipped because they do not parse', function () {
    $result = new Result(
        new ModuleMap([]),
        [],
        2,
        0,
        ['/app/app/Modules/Billing/Broken.php' => 'Syntax error, unexpected EOF on line 4'],
        0,
        '/app',
    );

    expect((new GithubReporter)->render($result))->toBe(
        '::warning file=app/Modules/Billing/Broken.php,line=1,title=Cordon%3A parse error::Cordon skipped this file (parse error: Syntax error, unexpected EOF on line 4). Its references were not checked.'."\n"
        .'Cordon: 0 violation(s), 0 suppressed by the baseline, 1 file(s) skipped because they do not parse.'."\n",
    );
});

it('keeps the summary unchanged when every file parses', function () {
    $result = new Result(new ModuleMap([]), [], 2, 0, [], 0, '/app');

    expect((new GithubReporter)->render($result))->toBe('Cordon: 0 violation(s), 0 suppressed by the baseline.'."\n");
});
