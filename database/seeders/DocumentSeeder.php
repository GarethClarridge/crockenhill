<?php

namespace Database\Seeders;

class DocumentSeeder extends Seeder {

    public function run()
    {
        DB::table('documents')->delete();

        Document::create(array(
            'title'     => 'AGM 2015 Agenda',
            'type'      => 'meeting',
            'filename'  => 'agm-2015-agenda',
            'filetype'  => 'pdf',
            'owner'     => 'Laurie Everest',
        ));

        Document::create(array(
            'title'     => 'AGM 2014 Minutes',
            'type'      => 'meeting',
            'filename'  => 'agm-2014-minutes',
            'filetype'  => 'pdf',
            'owner'     => 'Laurie Everest',
        ));

    }

}
