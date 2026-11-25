<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Contracts\DependencyExtractor;

/**
 * Asks the wrapped extractor only for the files whose content changed since
 * the cache was written. The key is the sha1 of the content, so touching or
 * checking out a file does not invalidate it.
 *
 * @internal
 */
final class CachedExtractor implements DependencyExtractor
{
    private bool $enabled = true;

    public function __construct(
        private readonly DependencyExtractor $extractor,
        private readonly AnalysisCache $cache,
    ) {}

    public function extract(string $file): FileAnalysis
    {
        if (! $this->enabled) {
            return $this->extractor->extract($file);
        }

        $code = @file_get_contents($file);

        // Nothing to hash: the wrapped extractor reports the unreadable file.
        if ($code === false) {
            return $this->extractor->extract($file);
        }

        $hash = sha1($code);

        if (($cached = $this->cache->get($file, $hash)) !== null) {
            return $cached;
        }

        $analysis = $this->extractor->extract($file);
        $this->cache->put($file, $hash, $analysis);

        return $analysis;
    }

    /**
     * Stops reading and writing the cache (--no-cache). The files are extracted as without it.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Writes the cache. Call it once the analysis is done.
     *
     * @param  bool  $keepUnused  Keep what earlier runs cached for files this one did not analyse. See AnalysisCache::save().
     */
    public function save(bool $keepUnused = false): void
    {
        if ($this->enabled) {
            $this->cache->save($keepUnused);
        }
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->enabled ? $this->cache->warnings() : [];
    }
}
