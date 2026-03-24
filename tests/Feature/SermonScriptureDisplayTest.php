<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScripturePassage;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SermonScriptureDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_sermon_page_shows_passage_html_when_linked(): void
    {
        $passage = ScripturePassage::factory()->create([
            'html_content' => '<p><sup>16</sup>For God so loved the world.</p>',
            'copyright' => 'NIV Copyright 2011',
            'fums_token' => 'abc-fums-token',
        ]);

        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16',
            'scripture_passage_id' => $passage->id,
        ]);

        $response = $this->followingRedirects()->get(route('sermons.show', $sermon->slug));

        $response->assertStatus(200);
        $response->assertSee('For God so loved the world', false);
        $response->assertSee('NIV Copyright 2011', false);
        $response->assertSee('data-fums-token="abc-fums-token"', false);
    }

    public function test_sermon_page_shows_only_raw_reference_when_no_passage_linked(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => 'Romans 8:28',
            'scripture_passage_id' => null,
        ]);

        $response = $this->followingRedirects()->get(route('sermons.show', $sermon->slug));

        $response->assertStatus(200);
        $response->assertSee('Romans 8:28', false);
        $response->assertDontSee('data-fums-token', false);
        $response->assertDontSee('scripture-passage', false);
    }

    public function test_sermon_page_exposes_fums_token_on_scripture_container(): void
    {
        $passage = ScripturePassage::factory()->create([
            'fums_token' => 'unique-token-xyz',
        ]);

        $sermon = Sermon::factory()->create([
            'scripture_passage_id' => $passage->id,
        ]);

        $response = $this->followingRedirects()->get(route('sermons.show', $sermon->slug));

        $response->assertStatus(200);
        $response->assertSee('data-fums-token="unique-token-xyz"', false);
    }
}
