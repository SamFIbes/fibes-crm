<?php

use App\Modules\Crm\Providers\CrmServiceProvider;
use App\Modules\Quotes\Providers\QuotesServiceProvider;
use App\Modules\Settings\Providers\SettingsServiceProvider;
use App\Modules\Surveys\Providers\SurveysServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CrmServiceProvider::class,
    SettingsServiceProvider::class,
    SurveysServiceProvider::class,
    QuotesServiceProvider::class,
];
