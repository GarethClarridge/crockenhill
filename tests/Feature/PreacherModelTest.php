<?php

namespace Tests\Feature;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreacherModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_preacher_can_be_created(): void
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

    public function test_preacher_route_key_is_slug(): void
    {
        $preacher = Preacher::factory()->create();

        $this->assertEquals('slug', $preacher->getRouteKeyName());
    }

    public function test_preacher_has_sermons_relation(): void
    {
        $preacher = Preacher::factory()->create();
        Sermon::factory()->count(3)->create(['preacher_id' => $preacher->id]);

        $this->assertCount(3, $preacher->sermons);
    }

    public function test_preacher_has_aliases_relation(): void
    {
        $preacher = Preacher::factory()->create();
        PreacherAlias::create(['preacher_id' => $preacher->id, 'alias' => 'john']);
        PreacherAlias::create(['preacher_id' => $preacher->id, 'alias' => 'johnny']);

        $this->assertCount(2, $preacher->fresh()->aliases);
    }

    public function test_active_scope_filters_inactive_preachers(): void
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

    public function test_preacher_aliases_cascade_delete_with_preacher(): void
    {
        $preacher = Preacher::factory()->create();
        PreacherAlias::create(['preacher_id' => $preacher->id, 'alias' => 'test alias']);

        $preacherId = $preacher->id;
        $preacher->delete();

        $this->assertDatabaseMissing('preacher_aliases', ['preacher_id' => $preacherId]);
    }

    public function test_sermon_preacher_id_set_null_on_preacher_delete(): void
    {
        $preacher = Preacher::factory()->create();
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);

        $preacher->delete();

        $this->assertNull($sermon->fresh()->preacher_id);
    }

    public function test_sermon_belongs_to_preacher_via_profile_relation(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Mark Jones']);
        $sermon = Sermon::factory()->create([
            'preacher' => 'Mark Jones',
            'preacher_id' => $preacher->id,
            'preacher_source' => PreacherSource::MANUAL->value,
        ]);

        $this->assertEquals('Mark Jones', $sermon->preacherProfile->name);
        $this->assertEquals(PreacherSource::MANUAL, $sermon->preacher_source);
    }

    public function test_needs_preacher_review_scope(): void
    {
        $first = Sermon::factory()->create(['needs_preacher_review' => true]);
        $second = Sermon::factory()->create(['needs_preacher_review' => true]);
        $third = Sermon::factory()->create(['needs_preacher_review' => false]);

        $needsReview = Sermon::needsPreacherReview()
            ->whereIn('id', [$first->id, $second->id, $third->id])
            ->get();

        $this->assertCount(2, $needsReview);
    }

    public function test_preacher_url_accessor_uses_profile_slug(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Dr Smith', 'slug' => 'dr-smith']);
        $sermon = Sermon::factory()->create([
            'preacher' => 'dr smith',
            'preacher_id' => $preacher->id,
        ]);

        $this->assertStringContainsString('dr-smith', $sermon->preacher_url);
    }

    public function test_preacher_url_accessor_falls_back_to_legacy_slug(): void
    {
        $sermon = Sermon::factory()->create([
            'preacher' => 'John Doe',
            'preacher_id' => null,
        ]);

        $this->assertStringContainsString('john-doe', $sermon->preacher_url);
    }
}
