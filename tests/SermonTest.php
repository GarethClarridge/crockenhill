<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class SermonTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function testIndex()
    {
        $this->visit('/sermons')
          ->see('The sermons in the morning and evening services are recorded every week');
    }

    public function testAdminCanSeeButtons()
    {
        Auth::loginUsingId(2);

        $this->visit('/sermons')
          ->see('Edit');

        Auth::logout();
    }

    public function testAdminCanClickUpload()
    {
        Auth::loginUsingId(2);

        $this->visit('/sermons')
          ->click('Upload a new sermon')
          ->seePageIs('/sermons/create');

        Auth::logout();
    }


}
