<?php

declare(strict_types=1);

namespace Cordon\Module;

/**
 * Recognises when the detected "modules" are the layers of a standard
 * Laravel app (app/Http, app/Models, app/Providers...) rather than modules
 * split by feature, which is a setup Cordon is not meant for.
 *
 * @internal
 */
final class LayeredLayout
{
    /**
     * Directory names of a Laravel app split by layer.
     */
    private const LAYERS = [
        'Actions', 'Casts', 'Console', 'Events', 'Exceptions', 'Helpers', 'Http', 'Jobs', 'Listeners',
        'Mail', 'Models', 'Notifications', 'Observers', 'Policies', 'Providers', 'Rules', 'Services',
        'Traits', 'View',
    ];

    /**
     * One module may be called like a layer (a Notifications module, say);
     * two or more mean the resolver is most likely pointed at app/.
     */
    private const MINIMUM = 2;

    /**
     * The module names that are layers, when there are enough of them to
     * suggest a layered app; otherwise none.
     *
     * @return list<string>
     */
    public static function layersIn(ModuleMap $modules): array
    {
        $layers = [];

        foreach ($modules as $module) {
            if (in_array($module->name, self::LAYERS, true)) {
                $layers[] = $module->name;
            }
        }

        return count($layers) >= self::MINIMUM ? $layers : [];
    }
}
