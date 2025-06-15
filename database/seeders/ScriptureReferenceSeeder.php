<?php

namespace Database\Seeders;

use App\Models\ScriptureReference;
use Illuminate\Database\Seeder;

class ScriptureReferenceSeeder extends Seeder
{
  public function run()
  {
    // Create specific scripture references for existing songs
    $references = [
      ['reference' => 'Rev 4:8', 'song_id' => 1], // Holy, Holy, Holy
      ['reference' => 'Isa 6:3', 'song_id' => 1],
      ['reference' => 'Psa 23:1', 'song_id' => 2], // The Lord's My Shepherd
      ['reference' => 'Psa 23:2', 'song_id' => 2],
      ['reference' => 'Eph 2:8-9', 'song_id' => 3], // Amazing Grace
      ['reference' => '1Pe 2:25', 'song_id' => 3],
    ];

    foreach ($references as $reference) {
      ScriptureReference::create($reference);
    }

    ScriptureReference::factory(25)->create();
  }
}
