<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Presenters\SermonArchiveSeoPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonArchiveSeoPresenterTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_resolves_preacher_name_from_public_list()
    {
        $preacher = Preacher::factory()->create(['name' => 'Active Preacher', 'is_active' => true]);
        $presenter = new SermonArchiveSeoPresenter;

        $filters = ['book' => null, 'chapter' => null, 'preacherId' => $preacher->id, 'series' => null];

        $this->assertEquals('Active Preacher | Sermons', $presenter->title($filters));
        $this->assertEquals('Browse sermons from Crockenhill Baptist Church by Active Preacher.', $presenter->description($filters));
    }

    #[Test]
    public function it_resolves_preacher_name_from_database_fallback_for_inactive_preachers()
    {
        $preacher = Preacher::factory()->create(['name' => 'Inactive Preacher', 'is_active' => false]);
        $presenter = new SermonArchiveSeoPresenter;

        $filters = ['book' => null, 'chapter' => null, 'preacherId' => $preacher->id, 'series' => null];

        $this->assertEquals('Inactive Preacher | Sermons', $presenter->title($filters));
        $this->assertEquals('Browse sermons from Crockenhill Baptist Church by Inactive Preacher.', $presenter->description($filters));
    }

    #[Test]
    public function it_memoizes_preacher_lookups()
    {
        $preacher = Preacher::factory()->create(['name' => 'Memo Test', 'is_active' => true]);
        $presenter = new SermonArchiveSeoPresenter;
        $filters = ['book' => null, 'chapter' => null, 'preacherId' => $preacher->id, 'series' => null];

        // First call populates memo
        $presenter->title($filters);

        // Change the preacher name in DB - memoized version should still be used if we weren't using the cached list,
        // but here it proves it doesn't re-query.
        $preacher->update(['name' => 'Changed Name']);

        $this->assertEquals('Memo Test | Sermons', $presenter->title($filters));
    }
}
