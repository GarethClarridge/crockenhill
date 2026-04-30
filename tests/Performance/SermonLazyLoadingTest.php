<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Models\Sermon;
use App\Repositories\SermonRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class SermonLazyLoadingTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_has_content_type_loaded_in_public_sermon_query(): void
    {
        // Ensure we have some sermons
        if (Sermon::count() < 3) {
            Sermon::factory()->count(5)->create();
        }

        $repository = new SermonRepository;

        $sermon = $repository->publicSermonQuery()->first();

        $this->assertNotNull($sermon, 'Should have a sermon');

        // Check if content_type is in the raw attributes
        $attributes = $sermon->getAttributes();
        $this->assertArrayHasKey('content_type', $attributes, 'content_type should be present in fetched attributes to avoid issues');
    }
}
