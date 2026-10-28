<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HealthCheckServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    AppServiceProvider::class,
    HealthCheckServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
];
