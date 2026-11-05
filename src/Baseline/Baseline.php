<?php

declare(strict_types=1);

namespace Cordon\Baseline;

use Cordon\Analysis\Violation;
use Cordon\Rules\CycleRule;
use Cordon\Support\Paths;
use RuntimeException;

/**
 * Known violations that are tolerated, so existing projects can adopt Cordon
 * and only fail on new violations.
 */
final readonly class Baseline
{
    /**
     * Version 2 records cycles per dependency ("A -> B") instead of one entry per cycle.
     */
    public const VERSION = 2;

    /**
     * @param  array<string, array{rule: string, file: string|null, target: string|null, count: int}>  $entries
     * @param  list<string>  $warnings
     */
    private function __construct(private array $entries, private array $warnings = []) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  list<Violation>  $violations
     */
    public static function fromViolations(array $violations, ?string $basePath = null): self
    {
        $entries = [];

        foreach ($violations as $violation) {
            $key = $violation->baselineKey($basePath);

            if (isset($entries[$key])) {
                $entries[$key]['count']++;

                continue;
            }

            $entries[$key] = [
                'rule' => $violation->rule,
                'file' => Paths::relative($basePath, $violation->file),
                'target' => $violation->target,
                'count' => 1,
            ];
        }

        ksort($entries);

        return new self($entries);
    }

    public static function load(string $file): self
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read the Cordon baseline [%s].', $file));
        }

        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $version = is_array($data) && isset($data['version']) && is_numeric($data['version']) ? (int) $data['version'] : 1;
        $entries = [];
        $outdatedCycles = 0;

        foreach (is_array($data) ? ($data['violations'] ?? []) : [] as $entry) {
            if (! is_array($entry) || ! isset($entry['rule'])) {
                continue;
            }

            $rule = (string) $entry['rule'];
            $path = isset($entry['file']) ? (string) $entry['file'] : null;
            $target = isset($entry['target']) ? (string) $entry['target'] : null;

            if ($version < 2 && $rule === CycleRule::ID) {
                $outdatedCycles++;
            }

            $entries[implode('|', [$rule, $path ?? '', $target ?? ''])] = [
                'rule' => $rule,
                'file' => $path,
                'target' => $target,
                'count' => max(1, (int) ($entry['count'] ?? 1)),
            ];
        }

        $warnings = [];

        if ($outdatedCycles > 0) {
            $warnings[] = sprintf(
                'The baseline has %d cycles %s from Cordon 0.1, which reported one violation per cycle. Cycles are now reported per dependency, so %s no longer %s. Regenerate the baseline with php artisan cordon:verify --generate-baseline.',
                $outdatedCycles,
                $outdatedCycles === 1 ? 'entry' : 'entries',
                $outdatedCycles === 1 ? 'that entry' : 'those entries',
                $outdatedCycles === 1 ? 'matches' : 'match',
            );
        }

        return new self($entries, $warnings);
    }

    /**
     * Problems found while loading the file, such as entries an older version wrote.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function save(string $file): void
    {
        $json = json_encode(
            ['version' => self::VERSION, 'violations' => array_values($this->entries)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if (@file_put_contents($file, $json.PHP_EOL) === false) {
            throw new RuntimeException(sprintf('Unable to write the Cordon baseline [%s].', $file));
        }
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Entries that record more occurrences than $violations contain, usually
     * because those violations were fixed.
     *
     * @param  list<Violation>  $violations
     */
    public function staleEntries(array $violations, ?string $basePath = null): int
    {
        $found = $this->occurrences($violations, $basePath);

        return count(array_filter(
            $this->entries,
            static fn (array $entry, string $key): bool => ($found[$key] ?? 0) < $entry['count'],
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * The baseline without the occurrences $violations no longer contain.
     * It only removes entries and lowers counts: a violation that is not
     * recorded yet is never added.
     *
     * @param  list<Violation>  $violations
     */
    public function prune(array $violations, ?string $basePath = null): self
    {
        $found = $this->occurrences($violations, $basePath);
        $entries = [];

        foreach ($this->entries as $key => $entry) {
            $count = min($entry['count'], $found[$key] ?? 0);

            if ($count > 0) {
                $entry['count'] = $count;
                $entries[$key] = $entry;
            }
        }

        return new self($entries);
    }

    /**
     * @param  list<Violation>  $violations
     * @return array<string, int>
     */
    private function occurrences(array $violations, ?string $basePath): array
    {
        $found = [];

        foreach ($violations as $violation) {
            $key = $violation->baselineKey($basePath);
            $found[$key] = ($found[$key] ?? 0) + 1;
        }

        return $found;
    }

    /**
     * @param  list<Violation>  $violations
     * @return array{0: list<Violation>, 1: int} Remaining violations and the number suppressed.
     */
    public function filter(array $violations, ?string $basePath = null): array
    {
        $remaining = array_map(static fn (array $entry): int => $entry['count'], $this->entries);
        $kept = [];
        $suppressed = 0;

        foreach ($violations as $violation) {
            $key = $violation->baselineKey($basePath);

            if (($remaining[$key] ?? 0) > 0) {
                $remaining[$key]--;
                $suppressed++;

                continue;
            }

            $kept[] = $violation;
        }

        return [$kept, $suppressed];
    }
}
