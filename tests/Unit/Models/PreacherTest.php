<?php

namespace Tests\Unit\Models;

use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_casts_is_active_to_boolean(): void
    {
        $preacher = Preacher::factory()->create(['is_active' => 1]);
        $this->assertTrue($preacher->is_active);

        $preacher->is_active = 0;
        $preacher->save();
        $this->assertFalse($preacher->fresh()->is_active);
    }

    #[Test]
    public function it_has_sermons_relationship(): void
    {
        $preacher = Preacher::factory()->create();
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);

        $this->assertTrue($preacher->sermons->contains($sermon));
        $this->assertInstanceOf(Sermon::class, $preacher->sermons->first());
    }

    #[Test]
    public function it_has_aliases_relationship(): void
    {
        $preacher = Preacher::factory()->create();
        $alias = PreacherAlias::create([
            'preacher_id' => $preacher->id,
            'alias' => 'Alternative Name',
        ]);

        $this->assertTrue($preacher->aliases->contains($alias));
        $this->assertInstanceOf(PreacherAlias::class, $preacher->aliases->first());
    }

    #[Test]
    public function it_has_speaker_profiles_relationship(): void
    {
        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);

        $this->assertTrue($preacher->speakerProfiles->contains($profile));
        $this->assertInstanceOf(SpeakerProfile::class, $preacher->speakerProfiles->first());
    }

    #[Test]
    public function it_has_an_active_scope(): void
    {
        Preacher::query()->delete();

        $activePreacher = Preacher::factory()->create(['is_active' => true]);
        $inactivePreacher = Preacher::factory()->inactive()->create();

        $activePreachers = Preacher::active()->get();

        $this->assertCount(1, $activePreachers);
        $this->assertTrue($activePreachers->contains($activePreacher));
        $this->assertFalse($activePreachers->contains($inactivePreacher));
    }

    #[Test]
    public function it_returns_preachers_for_admin_list_and_caches_result(): void
    {
        Cache::forget('admin_preacher_list');
        Preacher::query()->delete();

        $preacherA = Preacher::factory()->create(['name' => 'Zack', 'is_active' => true]);
        $preacherB = Preacher::factory()->create(['name' => 'Adam', 'is_active' => true]);
        Preacher::factory()->inactive()->create(['name' => 'Inactive']);

        $list = Preacher::getForAdminList();

        $this->assertCount(2, $list);
        $this->assertEquals(['Adam', 'Zack'], $list->values()->all());
        $this->assertEquals($preacherB->id, $list->keys()[0]);
        $this->assertEquals($preacherA->id, $list->keys()[1]);

        $this->assertTrue(Cache::has('admin_preacher_list'));
    }

    #[Test]
    public function it_returns_preachers_for_public_list_with_sermon_counts_and_caches_result(): void
    {
        Cache::forget('public_preacher_list');
        Preacher::query()->delete();
        Sermon::query()->delete();

        $preacherA = Preacher::factory()->create(['name' => 'Preacher A', 'is_active' => true]);
        $preacherB = Preacher::factory()->create(['name' => 'Preacher B', 'is_active' => true]);

        // 2 sermons for A
        Sermon::factory()->count(2)->create([
            'preacher_id' => $preacherA->id,
            'content_type' => SermonContentType::Sermon,
        ]);
        // 1 sermon for B
        Sermon::factory()->create([
            'preacher_id' => $preacherB->id,
            'content_type' => SermonContentType::Sermon,
        ]);
        // 1 children's talk for B (should NOT count based on whereSermon scope)
        Sermon::factory()->create([
            'preacher_id' => $preacherB->id,
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $list = Preacher::getForPublicList();

        $this->assertCount(2, $list);
        $this->assertEquals('Preacher A', $list->first()->name);
        $this->assertEquals(2, $list->first()->sermons_count);
        $this->assertEquals('Preacher B', $list->last()->name);
        $this->assertEquals(1, $list->last()->sermons_count);

        $this->assertTrue(Cache::has('public_preacher_list'));
    }

    #[Test]
    public function it_generates_sitemap_tag(): void
    {
        $updatedAt = Carbon::now()->subDay();
        $preacher = Preacher::factory()->create([
            'slug' => 'test-preacher',
            'updated_at' => $updatedAt,
        ]);

        $tag = $preacher->toSitemapTag();

        $this->assertInstanceOf(\Spatie\Sitemap\Tags\Url::class, $tag);
        $this->assertStringContainsString('/christ/sermons/preachers/test-preacher', $tag->url);
        $this->assertEquals(0.6, $tag->priority);
        $this->assertEquals('monthly', $tag->changeFrequency);
        $this->assertEquals($updatedAt->timestamp, $tag->lastModificationDate->timestamp);
    }
}
