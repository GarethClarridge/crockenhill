<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test; // Added import

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    #[Test] // Added Test attribute
    public function testBasicTest()
    {
        $this->assertTrue(true);
    }
}
