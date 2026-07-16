<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
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
