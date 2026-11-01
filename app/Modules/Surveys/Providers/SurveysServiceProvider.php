<?php

namespace App\Modules\Surveys\Providers;

use App\Modules\ModuleServiceProvider;

class SurveysServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
