<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Sitemap\Tags\Url;
use Tests\TestCase;

class PreacherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_be_created(): void
    {
        $slug = 'john-smith-feature-test';

        $preacher = Preacher::factory()->create([
            'name' => 'John Smith',
            'slug' => $slug,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('preachers', [
            'name' => 'John Smith',
            'slug' => $slug,
            'is_active' => true,
        ]);

        $this->assertEquals($slug, $preacher->getRouteKey());
    }

    #[Test]
    public function it_uses_slug_as_route_key(): void
    {
        $preacher = Preacher::factory()->create();

        $this->assertEquals('slug', $preacher->getRouteKeyName());
    }

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
    public function it_active_scope_filters_correctly_with_mixed_set(): void
    {
        $activeOne = Preacher::factory()->create(['is_active' => true]);
        $activeTwo = Preacher::factory()->create(['is_active' => true]);
        $inactive = Preacher::factory()->inactive()->create();

        $activePreachers = Preacher::active()
            ->whereIn('id', [$activeOne->id, $activeTwo->id, $inactive->id])
            ->get();

        $this->assertCount(2, $activePreachers);
        $this->assertTrue($activePreachers->every(fn ($p) => $p->is_active));
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

        Sermon::factory()->count(2)->create([
            'preacher_id' => $preacherA->id,
            'content_type' => SermonContentType::Sermon,
        ]);
        Sermon::factory()->create([
            'preacher_id' => $preacherB->id,
            'content_type' => SermonContentType::Sermon,
        ]);
        // Children's talk should NOT count towards sermon count
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

        $this->assertInstanceOf(Url::class, $tag);
        $this->assertStringContainsString('/christ/sermons/preachers/test-preacher', $tag->url);
        $this->assertEquals(0.6, $tag->priority);
        $this->assertEquals('monthly', $tag->changeFrequency);
        $this->assertEquals($updatedAt->timestamp, $tag->lastModificationDate->timestamp);
    }

    #[Test]
    public function it_cascades_alias_delete_when_preacher_deleted(): void
    {
        $preacher = Preacher::factory()->create();
        PreacherAlias::create(['preacher_id' => $preacher->id, 'alias' => 'test alias']);

        $preacherId = $preacher->id;
        $preacher->delete();

        $this->assertDatabaseMissing('preacher_aliases', ['preacher_id' => $preacherId]);
    }

    #[Test]
    public function it_sets_sermon_preacher_id_null_on_preacher_delete(): void
    {
        $preacher = Preacher::factory()->create();
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);

        $preacher->delete();

        $this->assertNull($sermon->fresh()->preacher_id);
    }

    #[Test]
    public function it_resolves_preacher_via_profile_relation(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Mark Jones']);
        $sermon = Sermon::factory()->create([
            'preacher' => 'Mark Jones',
            'preacher_id' => $preacher->id,
            'preacher_source' => PreacherSource::Manual->value,
        ]);

        $this->assertEquals('Mark Jones', $sermon->preacherProfile->name);
        $this->assertEquals(PreacherSource::Manual, $sermon->preacher_source);
    }

    #[Test]
    public function it_filters_sermons_needing_preacher_review(): void
    {
        $first = Sermon::factory()->create(['needs_preacher_review' => true]);
        $second = Sermon::factory()->create(['needs_preacher_review' => true]);
        $third = Sermon::factory()->create(['needs_preacher_review' => false]);

        $needsReview = Sermon::needsPreacherReview()
            ->whereIn('id', [$first->id, $second->id, $third->id])
            ->get();

        $this->assertCount(2, $needsReview);
    }

    #[Test]
    public function it_preacher_url_presenter_uses_profile_slug(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Dr Smith', 'slug' => 'dr-smith']);
        $sermon = Sermon::factory()->create([
            'preacher' => 'dr smith',
            'preacher_id' => $preacher->id,
        ]);

        $this->assertStringContainsString('dr-smith', app(SermonViewPresenter::class)->preacherUrl($sermon) ?? '');
    }

    #[Test]
    public function it_preacher_url_presenter_falls_back_to_legacy_slug(): void
    {
        $sermon = Sermon::factory()->create([
            'preacher' => 'John Doe',
            'preacher_id' => null,
        ]);

        $this->assertStringContainsString('john-doe', app(SermonViewPresenter::class)->preacherUrl($sermon) ?? '');
    }
}
