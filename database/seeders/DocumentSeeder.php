<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Document; // Assuming Document model exists in App\Models

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
