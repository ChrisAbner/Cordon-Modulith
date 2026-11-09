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
    private const PATTERN = '~^@cordon-ignore(?=[\s(]|$)\s*(?<rules>[^()]*?)\s*(?:\((?<reason>.*)\))?\s*$~s';

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

            // After code, it is about that line; on its own line, about the statement below.
            $lines = self::followsCode($tokens, $index)
                ? [$token[2]]
                : array_values(array_unique([$token[2], ...self::nextStatementLines($tokens, $index)]));

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
     * Whether code comes before the comment on its line.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function followsCode(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if (str_contains($text, "\n")) {
                // A newline inside whitespace, or at the end of "<?php" or a // comment.
                return is_array($token) && ! in_array($token[0], [T_WHITESPACE, T_OPEN_TAG, T_COMMENT], true);
            }

            if (! is_array($token) || $token[0] !== T_WHITESPACE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lines of the statement after the comment: from its first token to
     * the first ";" or "{" outside parentheses and brackets, so a signature
     * or a call split over several lines is covered.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<int>
     */
    private static function nextStatementLines(array $tokens, int $index): array
    {
        $comment = $tokens[$index];
        $line = is_array($comment) ? $comment[2] + substr_count($comment[1], "\n") : 0;
        $first = null;
        $depth = 0;

        foreach (array_slice($tokens, $index + 1) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $line = is_array($token) ? $token[2] : $line;
            $skip = is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);

            if (! $skip) {
                $first ??= $line;

                if (in_array($text, ['(', '['], true)) {
                    $depth++;
                } elseif (in_array($text, [')', ']'], true)) {
                    $depth--;
                } elseif ($depth <= 0 && in_array($text, [';', '{'], true)) {
                    return range($first, $line);
                }
            }

            $line += substr_count($text, "\n");
        }

        return $first === null ? [] : range($first, $line);
    }
}
