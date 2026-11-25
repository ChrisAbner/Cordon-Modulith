<?php

declare(strict_types=1);

namespace Cordon\Support;

use RuntimeException;

/**
 * The files that changed since a git reference, for cordon:verify --since and
 * cordon:affected. Git runs with an argument list, never through a shell, and
 * a reference that looks like an option is refused before git sees it.
 *
 * @internal
 */
final class GitChanges
{
    /**
     * The files that differ from $ref, relative to $root with forward slashes:
     * what was committed since $ref forked from HEAD (`git diff ref...HEAD`),
     * what is staged, what is modified and what is not tracked yet. A file
     * that was deleted is listed, and so are both names of a rename.
     *
     * @return list<string>
     *
     * @throws RuntimeException When $root is not in a git repository or $ref does not exist.
     */
    public static function changedFiles(string $root, string $ref): array
    {
        $failure = new RuntimeException(sprintf('Cannot compare with [%s]: not a git repository or the reference does not exist.', $ref));

        // "-x" would be read as an option, and so would "--output=file".
        if ($ref === '' || $ref[0] === '-' || str_contains($ref, "\0")) {
            throw $failure;
        }

        // The listing flags: -z for names with spaces or accents, --no-renames to see both names of a rename.
        $diff = ['diff', '--name-only', '-z', '--no-renames', '--relative', '--diff-filter=ACMRD'];
        $files = [];

        foreach ([
            [...$diff, $ref.'...HEAD', '--'],
            [...$diff, '--'],
            [...$diff, '--cached', '--'],
            ['ls-files', '--others', '--exclude-standard', '-z'],
        ] as $command) {
            $output = self::git($root, $command);

            if ($output === null) {
                throw $failure;
            }

            foreach (explode("\0", $output) as $file) {
                if ($file !== '') {
                    $files[$file] = true;
                }
            }
        }

        $files = array_map('strval', array_keys($files));
        sort($files);

        return $files;
    }

    /**
     * The standard output of a git command, or null when it fails or git cannot run.
     *
     * @param  list<string>  $arguments
     */
    private static function git(string $root, array $arguments): ?string
    {
        if (! is_dir($root)) {
            return null;
        }

        $process = @proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);

        if (! is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && $output !== false ? $output : null;
    }
}
