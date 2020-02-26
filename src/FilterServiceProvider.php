<?php

namespace Mont4\LaravelFilter;

use Illuminate\Support\ServiceProvider;

class FilterServiceProvider extends ServiceProvider
{
    const CONFIG_PATH = __DIR__ . '/../config/laravel_filter.php';

    public function boot()
    {
        $this->publishes([
            self::CONFIG_PATH => config_path('laravel_filter.php'),
        ], 'config');
    }

    public function register()
    {
        $this->mergeConfigFrom(
            self::CONFIG_PATH,
            'filter'
        );
    }
}
