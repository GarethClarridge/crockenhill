<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonEagerLoadingTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_eager_loads_scripture_passage_in_public_query(): void
    {
        $passage = ScripturePassage::factory()->create();
        Sermon::factory()->create([
            'scripture_passage_id' => $passage->id,
            'content_type' => SermonContentType::Sermon,
        ]);

        $repository = app(SermonRepository::class);
        $sermons = $repository->publicSermonQuery()->get();

        foreach ($sermons as $sermon) {
            $this->assertTrue($sermon->relationLoaded('scripturePassage'), 'scripturePassage relation should be eager loaded');
            $this->assertTrue($sermon->relationLoaded('preacherProfile'), 'preacherProfile relation should be eager loaded');
        }
    }
}
