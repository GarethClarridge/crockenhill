<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
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
    public function testBasicTest() // Kept original method name, standard is camelCase though
    {
        $response = $this->get('/');

        $response->assertStatus(200); // Homepage should return 200 OK
    }
}
