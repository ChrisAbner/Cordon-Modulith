<?php

namespace Modules\Payments\Providers;

use Illuminate\Support\ServiceProvider;

class PaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path('Payments', 'database/migrations'));
    }
}
