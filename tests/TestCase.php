<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up Mockery to prevent class redeclaration issues
        if (class_exists('Mockery')) {
            Mockery::close();
        }
    }
}
