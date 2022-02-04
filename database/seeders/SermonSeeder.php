<?php

namespace Database\Seeders;

class SermonSeeder extends Seeder {

    public function run()
    {
        DB::table('sermons')->delete();

        \Sermon::create(array(
            'date'      => 2015-01-11,
            'service'   => 'morning',
            'filename'  => '614a',
            'filetype'  => 'mp3',
            'title'     => 'Being Witnesses',
            'slug'     => 'being-witnesses',
            'reference' => 'Acts 3:11-26',
            'preacher'  => 'Mark Drury',
            'series'    => 'Acts'
        ));

        \Sermon::create(array(
            'date'      => 2015-01-11,
            'service'   => 'evening',
            'filename'  => '614b',
            'filetype'  => 'mp3',
            'title'     => 'In Adam or in Christ',
            'slug'     => 'in-adam-or-in-christ',
            'reference' => 'Ephesians 2:1-10',
            'preacher'  => 'Mark Drury'
        ));

    }

}
