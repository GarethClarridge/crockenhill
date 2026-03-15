<?php

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSeoTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function sermon_index_has_item_list_structured_data_and_correct_title()
    {
        // Create some sermons to populate the index
        $preacher = Preacher::factory()->create(['name' => 'John Doe']);
        Sermon::factory()->count(3)->create([
            'preacher' => 'John Doe',
            'preacher_id' => $preacher->id,
            'content_type' => \App\Enums\SermonContentType::Sermon,
        ]);

        $response = $this->get(route('sermonIndex'));

        $response->assertStatus(200);
        $response->assertSee('Sermons | Crockenhill Baptist Church');
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"@type": "CreativeWork"', false);
        $response->assertSee('John Doe');
    }

    #[Test]
    public function all_sermons_page_has_item_list_structured_data_and_correct_title()
    {
        // Create some sermons to populate the index
        $preacher = Preacher::factory()->create(['name' => 'Jane Doe']);
        Sermon::factory()->count(3)->create([
            'preacher' => 'Jane Doe',
            'preacher_id' => $preacher->id,
            'content_type' => \App\Enums\SermonContentType::Sermon,
        ]);

        $response = $this->get(route('allSermons'));

        $response->assertStatus(200);
        $response->assertSee('All Sermons | Crockenhill Baptist Church');
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"@type": "CreativeWork"', false);
        $response->assertSee('Jane Doe');
    }
}
