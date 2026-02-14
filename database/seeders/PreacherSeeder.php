<?php

namespace Database\Seeders;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PreacherSeeder extends Seeder
{
    public function run(): void
    {
        $preachers = [
            'Mark Drury',
            'John Smith',
            'David Johnson',
            'Michael Brown',
            'Paul Wilson',
            'Visiting Speaker',
        ];

        foreach ($preachers as $name) {
            $preacher = Preacher::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true]
            );

            PreacherAlias::firstOrCreate(
                ['alias' => strtolower($name)],
                ['preacher_id' => $preacher->id]
            );
        }

        // Map "Guest Speaker" alias to "Visiting Speaker"
        $visitingSpeaker = Preacher::where('slug', 'visiting-speaker')->first();
        if ($visitingSpeaker) {
            PreacherAlias::firstOrCreate(
                ['alias' => 'guest speaker'],
                ['preacher_id' => $visitingSpeaker->id]
            );
        }
    }
}
