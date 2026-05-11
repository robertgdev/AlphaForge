<?php

use App\Providers\AlphaForgeServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AlphaForgeServiceProvider::class,
];
