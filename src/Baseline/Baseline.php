<?php

declare(strict_types=1);

namespace Cordon\Baseline;

use Cordon\Analysis\Violation;
use Cordon\Support\Paths;
use RuntimeException;

/**
 * Known violations that are tolerated, so existing projects can adopt Cordon
 * and only fail on new violations.
 */
final readonly class Baseline
{
    public const VERSION = 1;

    /**
     * @param  array<string, array{rule: string, file: string|null, target: string|null, count: int}>  $entries
     */
    private function __construct(private array $entries) {}

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
        $entries = [];

        foreach (is_array($data) ? ($data['violations'] ?? []) : [] as $entry) {
            if (! is_array($entry) || ! isset($entry['rule'])) {
                continue;
            }

            $rule = (string) $entry['rule'];
            $path = isset($entry['file']) ? (string) $entry['file'] : null;
            $target = isset($entry['target']) ? (string) $entry['target'] : null;

            $entries[implode('|', [$rule, $path ?? '', $target ?? ''])] = [
                'rule' => $rule,
                'file' => $path,
                'target' => $target,
                'count' => max(1, (int) ($entry['count'] ?? 1)),
            ];
        }

        return new self($entries);
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
