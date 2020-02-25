<?php

namespace Mont4\LaravelFilter\Tests;

use Mont4\LaravelFilter\Facades\LaravelFilter;
use Mont4\LaravelFilter\ServiceProvider;
use Orchestra\Testbench\TestCase;

class LaravelFilterTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return [
            'laravel-filter' => LaravelFilter::class,
        ];
    }

    public function testExample()
    {
        $this->assertEquals(1, 1);
    }
}
