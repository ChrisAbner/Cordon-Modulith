<?php

declare(strict_types=1);

namespace Cordon\Documentation;

use Cordon\Analysis\Snapshot;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;

/**
 * Module events with the places that publish and listen to them.
 *
 * A class is an event when it belongs to a module and either lives under an
 * "Events" namespace of that module or is dispatched or listened to
 * explicitly (event(new X), Event::dispatch, Event::listen). X::dispatch()
 * and handle(X $x) only count for classes that are already events, because
 * jobs use the same methods.
 */
final readonly class EventInventory
{
    /**
     * @param  list<EventEntry>  $events
     */
    private function __construct(public array $events) {}

    public static function build(Snapshot $snapshot, EventExtractor $extractor = new EventExtractor): self
    {
        $modules = $snapshot->context->modules;
        $usages = [];

        foreach ($snapshot->analyses as $analysis) {
            if ($analysis->error === null) {
                array_push($usages, ...$extractor->extract($analysis->file));
            }
        }

        $events = [];

        foreach ($usages as $usage) {
            $module = $modules->forClass($usage->event);

            if ($module !== null && ($usage->explicit || self::inEventsNamespace($usage->event, $module))) {
                $events[strtolower($usage->event)] ??= $usage->event;
            }
        }

        foreach ($snapshot->analyses as $analysis) {
            foreach ($analysis->declarations as $declaration) {
                $module = $modules->forClass($declaration->fqcn);

                if ($module !== null && self::inEventsNamespace($declaration->fqcn, $module)) {
                    $events[strtolower($declaration->fqcn)] ??= $declaration->fqcn;
                }
            }
        }

        ksort($events);
        $entries = [];

        foreach ($events as $key => $fqcn) {
            $matching = array_values(array_filter($usages, static fn (EventUsage $usage): bool => strtolower($usage->event) === $key));

            $entries[] = new EventEntry(
                $fqcn,
                $modules->forClass($fqcn)->name ?? '',
                self::withModules($modules, array_values(array_filter($matching, static fn (EventUsage $u): bool => $u->kind === EventUsage::DISPATCH))),
                self::withModules($modules, array_values(array_filter($matching, static fn (EventUsage $u): bool => $u->kind === EventUsage::LISTEN))),
            );
        }

        return new self($entries);
    }

    /**
     * Events a module dispatches (its own or other modules').
     *
     * @return list<EventEntry>
     */
    public function publishedBy(string $module): array
    {
        return array_values(array_filter($this->events, static fn (EventEntry $entry): bool => in_array($module, array_column($entry->publishers, 'module'), true)));
    }

    /**
     * Events a module listens to.
     *
     * @return list<EventEntry>
     */
    public function listenedBy(string $module): array
    {
        return array_values(array_filter($this->events, static fn (EventEntry $entry): bool => in_array($module, array_column($entry->listeners, 'module'), true)));
    }

    /**
     * Events that belong to a module.
     *
     * @return list<EventEntry>
     */
    public function ownedBy(string $module): array
    {
        return array_values(array_filter($this->events, static fn (EventEntry $entry): bool => $entry->module === $module));
    }

    private static function inEventsNamespace(string $fqcn, Module $module): bool
    {
        $relative = $module->relativeName($fqcn);

        return str_starts_with($relative, 'Events\\') || str_contains($relative, '\\Events\\');
    }

    /**
     * @param  list<EventUsage>  $usages
     * @return list<array{module: string, class: string|null, file: string, line: int}>
     */
    private static function withModules(ModuleMap $modules, array $usages): array
    {
        $rows = [];
        $seen = [];

        foreach ($usages as $usage) {
            $key = $usage->file.'|'.$usage->sourceClass;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'module' => $modules->forPath($usage->file)->name ?? '',
                'class' => $usage->sourceClass,
                'file' => $usage->file,
                'line' => $usage->line,
            ];
        }

        return $rows;
    }
}
