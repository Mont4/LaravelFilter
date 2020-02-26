<?php

namespace Mont4\LaravelFilter\Tests;

use Mont4\LaravelFilter\Facades\LaravelFilter;
use Mont4\LaravelFilter\FilterServiceProvider;
use Orchestra\Testbench\TestCase;

class LaravelFilterTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [FilterServiceProvider::class];
    }

    public function testExample()
    {
        $this->assertEquals(1, 1);
    }
}
