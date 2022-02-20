<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class LoginTest extends TestCase
{
  /**
   * A basic test example.
   *
   * @return void
   */
  public function testLoginRedirect()
  {
    $response = $this->call('GET', '/members');

    $this->assertEquals(302, $response->getStatusCode());
  }

  public function testUserLoginForm()
  {
  $this->visit('/church/members/login')
       ->type('email@crockenhill.org', 'email')
       ->type('password', 'password');
  }

  public function testUserLogin()
  {
    Auth::loginUsingId(2);

    $this->visit('/church/members')
          ->see('Welcome to the members');

    Auth::logout();
  }
}
