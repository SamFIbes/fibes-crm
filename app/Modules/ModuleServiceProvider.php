<?php

namespace App\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

abstract class ModuleServiceProvider extends ServiceProvider
{
    abstract protected function modulePath(): string;

    public function boot(): void
    {
        $routes = $this->modulePath().'/routes.php';

        if (is_file($routes)) {
            Route::middleware('web')->group($routes);
        }
    }
}
