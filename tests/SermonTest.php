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

    public function testAll()
    {
      $this->visit('/sermons')
        ->click('Find older sermons')
        ->seePageIs('/sermons/all');
    }

    public function testCreate()
    {
      Auth::loginUsingId(2);

      $this->visit('/sermons')
        ->click('Upload a new sermon')
        ->seePageIs('/sermons/create')
        ->attach('/home/gareth/Projects/crockenhill/public/media/sermons/614b.mp3', 'file')
        ->type('1 Corinthians', 'series')
        ->type('1 Cor 9:1-10', 'reference')
        ->press('Save')
        ->see('successfully uploaded!');

      Auth::logout();
    }

    public function testSermon()
    {
      $this->visit('sermons/2015/10/justification-by-faith')
        ->see('Justification by faith');
    }
}
