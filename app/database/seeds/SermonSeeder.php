<?php
 
class SermonSeeder extends Seeder {
 
    public function run()
    {
        DB::table('sermons')->delete();
 
        Sermon::create(array(
            'title'     => 'The Title of the Sermon',
            'slug'      => '600a',
            'preacher'  => 'Mark Drury',
            'date'      => 2014-02-04,
            'service'   => 'morning',
            'scripture' => 'John 1:1-14',
            'body'      => 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
            'series'    => 'John\'s Gospel',
        ));
 
    }
 
}
