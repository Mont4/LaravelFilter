<?php

namespace Mont4\LaravelFilter\Facades;

use Illuminate\Support\Facades\Facade;

class LaravelFilter extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-filter';
    }
}
