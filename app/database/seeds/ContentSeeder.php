<?php
 
use App\Models\Sermon;
use App\Models\Page;
 
class ContentSeeder extends Seeder {
 
    public function run()
    {
        Eloquent::unguard();
        DB::table('sermons')->delete();
        DB::table('pages')->delete();
 
        Sermon::create(array(
            'title'   => 'First post',
            'slug'    => 'first-post',
            'body'    => 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
            'user_id' => 1,
            'preacher' => 'Mark Drury',
            'date' => '17/01/14',
            'scripture' => 'John 1:1-14',
            'filename' => '600a',
        ));
 
        Page::create(array(
            'title'   => 'About us',
            'slug'    => 'about-us',
            'body'    => 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
            'user_id' => 1,
        ));
    }
 
}
