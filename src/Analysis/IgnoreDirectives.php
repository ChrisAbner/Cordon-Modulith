<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * Reads the @cordon-ignore comments of a PHP file. Docblocks and strings
 * are not directives; a comment must start with @cordon-ignore.
 *
 * @internal
 */
final class IgnoreDirectives
{
    private const PATTERN = '~^@cordon-ignore\s+(?<rules>[^()\s][^()]*?)\s*(?:\((?<reason>[^()]*)\))?\s*$~';

    /**
     * @return list<IgnoreDirective>
     */
    public static function parse(string $code): array
    {
        if (! str_contains($code, '@cordon-ignore')) {
            return [];
        }

        $tokens = token_get_all($code);
        $directives = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_COMMENT) {
                continue;
            }

            $text = trim((string) preg_replace('~^(//|#|/\*)|\*/$~', '', trim($token[1])));

            if (preg_match(self::PATTERN, $text, $match) !== 1) {
                continue;
            }

            $reason = trim($match['reason'] ?? '');
            $lines = [$token[2]];
            $next = self::nextCodeLine($tokens, $index);

            if ($next !== null && $next !== $token[2]) {
                $lines[] = $next;
            }

            $directives[] = new IgnoreDirective(
                $token[2],
                array_values(array_filter(array_map('trim', explode(',', $match['rules'])), static fn (string $rule): bool => $rule !== '')),
                $reason === '' ? null : $reason,
                $lines,
            );
        }

        return $directives;
    }

    /**
     * @param  list<IgnoreDirective>  $directives
     */
    public static function covering(array $directives, string $rule, ?int $line): ?IgnoreDirective
    {
        foreach ($directives as $directive) {
            if ($directive->covers($rule, $line)) {
                return $directive;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextCodeLine(array $tokens, int $index): ?int
    {
        $comment = $tokens[$index];
        $line = is_array($comment) ? $comment[2] + substr_count($comment[1], "\n") : null;

        foreach (array_slice($tokens, $index + 1) as $token) {
            // A single-character token, such as "(", has no line of its own.
            if (! is_array($token)) {
                return $line;
            }

            if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $token[2];
            }

            $line = $token[2] + substr_count($token[1], "\n");
        }

        return null;
    }
}
