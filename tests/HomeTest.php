<?php

namespace Tests;

class HomeTest extends TestCase
{

  /**
   * A basic functional test example.
   *
   * @return void
   */
  public function testHomePageExists()
  {
    $response = $this->call('GET', '/');

    $this->assertEquals(200, $response->getStatusCode());
  }
}
