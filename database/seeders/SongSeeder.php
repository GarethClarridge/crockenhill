<?php

namespace Database\Seeders;

use App\Models\Song;
use Illuminate\Database\Seeder;

class SongSeeder extends Seeder
{
  public function run()
  {
    // Create some specific hymns/songs
    $songs = [
      [
        'praise_number' => '1',
        'title' => 'Holy, Holy, Holy',
        'author' => 'Reginald Heber',
        'major_category' => 'Approaching God',
        'minor_category' => 'Adoration and thanksgiving',
        'current' => true,
        'lyrics' => "Holy, holy, holy! Lord God Almighty!\nEarly in the morning our song shall rise to thee.\nHoly, holy, holy! Merciful and mighty,\nGod in three persons, blessed Trinity!"
      ],
      [
        'praise_number' => '23',
        'title' => 'The Lord\'s My Shepherd',
        'author' => 'Scottish Psalter',
        'major_category' => 'Psalms',
        'minor_category' => 'His providence',
        'current' => true,
        'lyrics' => "The Lord's my Shepherd, I'll not want;\nHe makes me down to lie\nIn pastures green; He leadeth me\nThe quiet waters by."
      ],
      [
        'praise_number' => '100',
        'title' => 'Amazing Grace',
        'author' => 'John Newton',
        'major_category' => 'The gospel',
        'minor_category' => 'Repentance and faith',
        'current' => true,
        'lyrics' => "Amazing grace! How sweet the sound\nThat saved a wretch like me!\nI once was lost, but now am found;\nWas blind, but now I see."
      ],
    ];

    foreach ($songs as $song) {
      Song::create($song);
    }

    // Create additional random songs
    Song::factory(47)->create();
  }
}
