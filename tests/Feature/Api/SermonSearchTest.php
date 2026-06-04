<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sermon::query()->delete();
    }

    #[Test]
    public function it_escapes_percent_wildcard_in_search(): void
    {
        Sermon::factory()->create(['title' => '100% Correct']);
        Sermon::factory()->create(['title' => 'Sermon 1']);
        Sermon::factory()->create(['title' => 'Sermon 2']);

        // Searching for '%' should only return '100% Correct' if escaped properly.
        // If not escaped, it would return all sermons because '%' is a wildcard.
        $response = $this->getJson('/api/sermons?search=%');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('100% Correct', $data[0]['title']);
    }

    #[Test]
    public function it_escapes_underscore_wildcard_in_search(): void
    {
        Sermon::factory()->create(['title' => 'Sermon_Special']);
        Sermon::factory()->create(['title' => 'Sermon A']);
        Sermon::factory()->create(['title' => 'Sermon B']);

        // Searching for '_' should only return 'Sermon_Special' if escaped.
        // If not escaped, it would match any single character (so 'Sermon A' and 'Sermon B' too).
        $response = $this->getJson('/api/sermons?search=_');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('Sermon_Special', $data[0]['title']);
    }

    #[Test]
    public function it_escapes_backslash_in_search(): void
    {
        Sermon::factory()->create(['title' => 'Path\To\Sermon']);

        $response = $this->getJson('/api/sermons?search=\\');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('Path\To\Sermon', $data[0]['title']);
    }

    #[Test]
    public function it_still_performs_normal_searches_correctly(): void
    {
        Sermon::factory()->create(['title' => 'The Grace of God']);
        Sermon::factory()->create(['title' => 'The Peace of God']);

        $response = $this->getJson('/api/sermons?search=Grace');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('The Grace of God', $data[0]['title']);
    }
}
