<?php

namespace Mont4\LaravelFilter;

class FilterServiceProvider extends \Illuminate\Support\ServiceProvider
{
    const CONFIG_PATH = __DIR__ . '/../config/filter.php';

    public function boot()
    {
        $this->publishes([
            self::CONFIG_PATH => config_path('filter.php'),
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
