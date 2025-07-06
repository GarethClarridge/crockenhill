<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase; // Added import

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    #[Test] // Added Test attribute
    public function test_basic_test()
    {
        $this->assertTrue(true);
    }
}
