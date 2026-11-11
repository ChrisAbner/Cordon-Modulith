<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Composer\InstalledVersions;
use Cordon\Support\Paths;
use JsonException;
use Throwable;

/**
 * The per-file analyses of earlier runs, in a JSON file:
 * {version: 1, fingerprint, files: {relative path: {hash, analysis}}}.
 *
 * It only ever holds what the extractor returned for a file, never rule
 * results, so a changed config does not invalidate it. A cache that cannot
 * be read or written is reported as a warning and then ignored: it can make a
 * run faster, never different.
 *
 * @internal
 */
final class AnalysisCache
{
    public const VERSION = 1;

    /** @var array<string, array{hash: string, analysis: array<array-key, mixed>}>|null Loaded entries, by relative path; null until first use. */
    private ?array $loaded = null;

    /** @var array<string, array{hash: string, analysis: array<array-key, mixed>}> What this run used or produced; this is what gets saved. */
    private array $used = [];

    /** True when the file on disk is missing, unreadable or from another fingerprint: it has to be written even if nothing changed. */
    private bool $replace = false;

    private bool $changed = false;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  string  $file  Absolute path of the cache file.
     * @param  string  $basePath  Project root: entries are keyed by path relative to it, so the cache survives a project that moves.
     * @param  string  $fingerprint  See fingerprint().
     */
    public function __construct(
        private readonly string $file,
        private readonly string $basePath,
        private readonly string $fingerprint,
    ) {}

    /**
     * What makes every cached analysis stale at once: the versions of Cordon,
     * php-parser, phpdoc-parser (docblocks) and illuminate/support (its
     * inflector names the tables of a model), the PHP version and the options
     * that change what the extractor returns. A new extractor option is added to $extractorOptions where the
     * extractor is built (CordonServiceProvider), and nowhere else.
     *
     * @param  array<string, bool|int|string|null>  $extractorOptions
     * @param  ?array{cordon: string, parser: string, phpdoc: string, php: string, support: string}  $versions  Only tests pass them.
     */
    public static function fingerprint(array $extractorOptions = [], ?array $versions = null): string
    {
        $versions ??= self::installedVersions();

        ksort($extractorOptions);

        return sha1($versions['cordon'].'|'.$versions['parser'].'|'.$versions['phpdoc'].'|'.$versions['support'].'|'.$versions['php'].'|'.json_encode($extractorOptions));
    }

    /**
     * The versions the fingerprint is built from.
     *
     * @return array{cordon: string, parser: string, phpdoc: string, php: string, support: string}
     */
    public static function installedVersions(): array
    {
        return [
            'cordon' => self::installed('chrisabner/cordon-modulith'),
            'parser' => self::installed('nikic/php-parser'),
            'phpdoc' => self::installed('phpstan/phpdoc-parser'),
            'php' => PHP_VERSION,
            'support' => self::installed('illuminate/support'),
        ];
    }

    /**
     * The analysis stored for $file, when it was made from content with this hash.
     */
    public function get(string $file, string $hash): ?FileAnalysis
    {
        $key = $this->key($file);
        $entry = $this->entries()[$key] ?? null;

        if ($entry === null || $entry['hash'] !== $hash) {
            return null;
        }

        try {
            $analysis = FileAnalysis::fromArray($entry['analysis'], $file);
        } catch (Throwable) {
            // A damaged entry is just a miss: the file is extracted again.
            return null;
        }

        $this->used[$key] = $entry;

        return $analysis;
    }

    public function put(string $file, string $hash, FileAnalysis $analysis): void
    {
        $this->used[$this->key($file)] = ['hash' => $hash, 'analysis' => $analysis->toArray()];
        $this->changed = true;
    }

    /**
     * Writes the entries this run used, so files that were deleted or renamed
     * drop out. Does nothing when that is what the file already holds.
     *
     * @param  bool  $keepUnused  Keep the stored entries this run did not use, for a run that analyses only part of the files (cordon:docs leaves the host code out): they drop out on the next full run.
     */
    public function save(bool $keepUnused = false): void
    {
        $stored = $this->entries();

        if ($keepUnused) {
            $this->used = [...$stored, ...$this->used];
        }

        // Files are extracted in the order the analyzer visits them (modules, then host paths) and stored sorted.
        ksort($this->used);
        ksort($stored);

        if (! $this->replace && ! $this->changed && array_keys($stored) === array_keys($this->used)) {
            return;
        }

        try {
            $json = json_encode(['version' => self::VERSION, 'fingerprint' => $this->fingerprint, 'files' => $this->used], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->unusable();

            return;
        }

        $directory = dirname($this->file);

        // A directory in the way of the file, or a file in the way of its directory, cannot be fixed from here.
        if (is_dir($this->file) || (file_exists($directory) && ! is_dir($directory)) || (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory))) {
            $this->unusable();

            return;
        }

        // Written next to the target and renamed over it, so a run that reads
        // the cache while another writes it sees the old file or the new one.
        $temporary = @tempnam($directory, 'cordon-cache-');

        if ($temporary === false) {
            $this->unusable();

            return;
        }

        if (@file_put_contents($temporary, $json) === false) {
            @unlink($temporary);
            $this->unusable();

            return;
        }

        @chmod($temporary, 0666 & ~umask());

        if (! @rename($temporary, $this->file)) {
            @unlink($temporary);
            $this->unusable();
        }
    }

    /**
     * Problems found with the cache file, such as "Cordon cache at X is unreadable; analysing without it."
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, array{hash: string, analysis: array<array-key, mixed>}>
     */
    private function entries(): array
    {
        return $this->loaded ??= $this->load();
    }

    /**
     * @return array<string, array{hash: string, analysis: array<array-key, mixed>}>
     */
    private function load(): array
    {
        $this->replace = true;

        if (! file_exists($this->file)) {
            return [];
        }

        $json = is_file($this->file) ? @file_get_contents($this->file) : false;
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ! is_string($data['fingerprint'] ?? null) || ! is_array($data['files'] ?? null)) {
            $this->unusable();

            return [];
        }

        // Another Cordon, parser or PHP version, or other extractor options: a normal miss, not a problem.
        if ($data['fingerprint'] !== $this->fingerprint) {
            return [];
        }

        $this->replace = false;
        $entries = [];

        foreach ($data['files'] as $key => $entry) {
            if (is_string($key) && is_array($entry) && is_string($entry['hash'] ?? null) && is_array($entry['analysis'] ?? null)) {
                $entries[$key] = ['hash' => $entry['hash'], 'analysis' => $entry['analysis']];
            }
        }

        return $entries;
    }

    private function unusable(): void
    {
        $warning = sprintf('Cordon cache at %s is unreadable; analysing without it.', $this->file);

        if (! in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    private function key(string $file): string
    {
        return (string) Paths::relative($this->basePath, $file);
    }

    private static function installed(string $package): string
    {
        if (! InstalledVersions::isInstalled($package)) {
            return 'unknown';
        }

        return (string) InstalledVersions::getPrettyVersion($package).'@'.(string) InstalledVersions::getReference($package);
    }
}
