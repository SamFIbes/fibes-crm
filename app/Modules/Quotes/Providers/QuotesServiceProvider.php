<?php

namespace App\Modules\Quotes\Providers;

use App\Modules\ModuleServiceProvider;

class QuotesServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
