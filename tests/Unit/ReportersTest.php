<?php

use Cordon\Analysis\ModuleFilter;
use Cordon\Analysis\Result;
use Cordon\Analysis\Violation;
use Cordon\Module\ModuleMap;
use Cordon\Reporters\CheckstyleReporter;
use Cordon\Reporters\GitlabReporter;
use Cordon\Reporters\JunitReporter;
use Cordon\Rules\Severity;

/**
 * The namespace app with its listener cycle, Billing's internal accesses as errors or warnings.
 */
function report_result(string $internalAccess = 'error'): Result
{
    return analyse_namespace_app(rules: ['internal_access' => $internalAccess], listeners: ['cycles' => true]);
}

/**
 * @param  list<Violation>  $violations
 */
function result_of(array $violations): Result
{
    return new Result(new ModuleMap([]), $violations, 1, 1, [], 0, '/p');
}

it('writes GitLab Code Quality issues', function () {
    $issues = json_decode((new GitlabReporter)->render(report_result()), true);
    $checkout = array_values(array_filter($issues, fn (array $issue) => str_ends_with($issue['location']['path'], 'CheckoutService.php')))[0];
    $cycle = array_values(array_filter($issues, fn (array $issue) => $issue['check_name'] === 'cordon.cycles'))[0];

    expect($checkout)->toMatchArray([
        'check_name' => 'cordon.internal_access',
        'severity' => 'major',
        'location' => ['path' => 'app/Modules/Billing/Services/CheckoutService.php', 'lines' => ['begin' => 13]],
    ])
        ->and($checkout['description'])->toStartWith('Module [Billing] uses App\\Modules\\Catalog\\Models\\Product')
        ->and($checkout['fingerprint'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($cycle['location'])->toBe(['path' => 'config/cordon.php', 'lines' => ['begin' => 1]])
        ->and(array_unique(array_column(json_decode((new GitlabReporter)->render(report_result('warn')), true), 'severity')))->toContain('minor');
});

it('keeps a GitLab fingerprint when the code above moves, and tells repeated violations apart', function () {
    $at = fn (int $line) => new Violation('internal_access', 'm', '/p/app/X.php', $line, 'A', 'B', 'B\\Y');
    $fingerprints = fn (array $violations) => array_column(json_decode((new GitlabReporter)->render(result_of($violations)), true), 'fingerprint');

    expect($fingerprints([$at(13)]))->toBe($fingerprints([$at(20)]))
        ->and(array_unique($fingerprints([$at(13), $at(14)])))->toHaveCount(2);
});

it('writes JUnit with a test case per module', function () {
    $xml = new DOMDocument;
    expect($xml->loadXML((new JunitReporter)->render(report_result())))->toBeTrue();

    $suite = $xml->getElementsByTagName('testsuite')->item(0);
    $cases = [];

    foreach ($xml->getElementsByTagName('testcase') as $case) {
        $cases[$case->getAttribute('classname')] = $case->getElementsByTagName('failure')->length;
    }

    expect($suite?->getAttribute('name'))->toBe('Cordon Modulith')
        ->and($suite?->getAttribute('tests'))->toBe('3')
        ->and($suite?->getAttribute('failures'))->toBe('2')
        ->and($cases)->toBe(['Billing' => 3, 'Catalog' => 1, 'Shared' => 0])
        ->and($xml->getElementsByTagName('failure')->item(0)?->getAttribute('type'))->toBe('internal_access');
});

it('puts warnings in the JUnit output without failing a test case', function () {
    $xml = new DOMDocument;
    $xml->loadXML((new JunitReporter)->render(report_result('warn')));

    expect($xml->getElementsByTagName('system-out')->item(0)?->textContent)->toContain('[warn] [internal_access]');
});

it('writes Checkstyle with one file element per file', function () {
    $xml = new DOMDocument;
    expect($xml->loadXML((new CheckstyleReporter)->render(report_result('warn'))))->toBeTrue();

    $errors = [];

    foreach ($xml->getElementsByTagName('file') as $file) {
        foreach ($file->getElementsByTagName('error') as $error) {
            $errors[] = $file->getAttribute('name').':'.$error->getAttribute('line').':'.$error->getAttribute('severity').':'.$error->getAttribute('source');
        }
    }

    expect($errors)->toContain('app/Modules/Billing/Services/CheckoutService.php:13:warning:cordon.internal_access')
        ->toContain('config/cordon.php:1:error:cordon.cycles');
});

it('escapes the messages in XML', function () {
    $violation = new Violation('internal_access', 'a < b & "c"', '/p/app/X.php', 1, 'A', 'B', 'B\\Y', Severity::Error);

    foreach ([new JunitReporter, new CheckstyleReporter] as $reporter) {
        expect((new DOMDocument)->loadXML($reporter->render(result_of([$violation]))))->toBeTrue();
    }
});

/**
 * The JUnit test cases of a report: module => number of failures.
 *
 * @return array<string, int>
 */
function junit_cases(Result $result): array
{
    $xml = new DOMDocument;
    $xml->loadXML((new JunitReporter)->render($result));
    $cases = [];

    foreach ($xml->getElementsByTagName('testcase') as $case) {
        $cases[$case->getAttribute('classname')] = $case->getElementsByTagName('failure')->length;
    }

    return $cases;
}

it('writes JUnit test cases only for the modules that were checked', function () {
    expect(junit_cases(ModuleFilter::apply(report_result(), ['Shared'])))->toBe(['Shared' => 0])
        ->and(junit_cases(ModuleFilter::apply(report_result(), ['Catalog'])))->toHaveKeys(['Catalog'])->not->toHaveKey('Shared')
        ->and(junit_cases(ModuleFilter::applyPaths(report_result(), [fixture_path('namespace-app/app/Modules/Shared')])))->toBe(['Shared' => 0]);
});

it('reports the files that do not parse in every CI format', function () {
    $result = new Result(new ModuleMap([]), [], 1, 0, ['/p/app/Broken.php' => 'Syntax error, unexpected EOF'], 0, '/p');
    $issue = json_decode((new GitlabReporter)->render($result), true)[0];
    $checkstyle = new DOMDocument;
    $checkstyle->loadXML((new CheckstyleReporter)->render($result));

    expect($issue)->toMatchArray(['check_name' => 'cordon.parse_error', 'severity' => 'minor', 'location' => ['path' => 'app/Broken.php', 'lines' => ['begin' => 1]]])
        ->and($issue['description'])->toContain('Syntax error, unexpected EOF')
        ->and((new JunitReporter)->render($result))->toContain('[parse error] app/Broken.php: Syntax error, unexpected EOF')
        ->and($checkstyle->getElementsByTagName('file')->item(0)?->getAttribute('name'))->toBe('app/Broken.php')
        ->and($checkstyle->getElementsByTagName('error')->item(0)?->getAttribute('source'))->toBe('cordon.parse_error');
});
