<?php

namespace App\Modules\Crm\Providers;

use App\Modules\ModuleServiceProvider;

class CrmServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
