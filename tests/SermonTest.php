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



    public function testCreateandDestroy()
    {
      Auth::loginUsingId(2);

      $this->visit('/sermons')
        //Index page exists
        ->see('The sermons in the morning and evening services are recorded every week')
        ->click('Upload a new sermon')
        //Upload page exists
        ->seePageIs('/sermons/create')
        //Upload form works
        ->attach('/home/gareth/Projects/crockenhill/public/media/sermons/614b.mp3', 'file')
        ->type('testsermon', 'title')
        ->type('testpreacher', 'preacher')
        ->type('testseries', 'series')
        ->type('testreference', 'reference')
        ->press('Save')
        //Upload successful
        ->see('successfully uploaded!')
        ->click('testsermon')
        //Can view sermon
        ->see('testsermon')
        //Can edit sermon
        ->click('Edit this sermon')
        ->see('Edit this sermon')
        ->type('testsermon', 'title')
        ->type('testpreacher2', 'preacher')
        ->type('testseries', 'series')
        ->type('testreference', 'reference')
        ->press('Save')
        ->see('updated')
        //Can delete sermon
        ->press('Delete this sermon')
        ->see('successfully deleted!');

      Auth::logout();
    }
    public function testAll()
    {
      $this->visit('/sermons')
        ->click('Find older sermons')
        ->seePageIs('/sermons/all');
    }

    public function testUpdate()
    {

    }
}
