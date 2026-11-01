<?php

namespace App\Modules\Settings\Providers;

use App\Modules\ModuleServiceProvider;

class SettingsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
