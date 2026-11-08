<?php

declare(strict_types=1);

namespace Cordon\Support;

use DirectoryIterator;

/**
 * @internal
 */
final class Paths
{
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    public static function join(string $base, string $path): string
    {
        if (self::isAbsolute($path)) {
            return rtrim($path, '/\\');
        }

        return rtrim($base, '/\\').DIRECTORY_SEPARATOR.trim($path, '/\\');
    }

    /**
     * A forward-slash path relative to $basePath: with ../ when the file is
     * outside it (a package of a monorepo), so baseline keys and CI paths do
     * not depend on the machine. A file on another Windows drive keeps its
     * normalized absolute path.
     */
    public static function relative(?string $basePath, ?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $file = str_replace('\\', '/', $file);

        if ($basePath === null) {
            return $file;
        }

        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';

        if (str_starts_with($file, $base)) {
            return substr($file, strlen($base));
        }

        if (! self::isAbsolute($file) || self::drive($file) !== self::drive($base)) {
            return $file;
        }

        $parts = static fn (string $path): array => array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        $from = $parts($base);
        $to = $parts($file);
        $common = 0;

        while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
            $common++;
        }

        return str_repeat('../', count($from) - $common).implode('/', array_slice($to, $common));
    }

    /**
     * The lowercased Windows drive letter of a path, or '' for any other path.
     */
    private static function drive(string $path): string
    {
        return preg_match('~^([A-Za-z]):~', $path, $match) === 1 ? strtolower($match[1]) : '';
    }

    /**
     * Direct, non-hidden sub-directories of $path, sorted.
     *
     * @return list<string>
     */
    public static function childDirectories(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $directories = [];

        foreach (new DirectoryIterator($path) as $item) {
            if ($item->isDot() || ! $item->isDir() || str_starts_with($item->getFilename(), '.')) {
                continue;
            }

            $directories[] = $item->getPathname();
        }

        sort($directories);

        return $directories;
    }
}
