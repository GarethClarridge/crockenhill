<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonContentType;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Sermon\SermonExposurePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSlugViolatingSermons;
use Tests\TestCase;

class SermonExposurePolicyTest extends TestCase
{
    use CreatesSlugViolatingSermons;
    use RefreshDatabase;

    private SermonExposurePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SermonExposurePolicy;
    }

    #[Test]
    public function childrens_talks_are_public_returns_config_value(): void
    {
        Config::set('church.sermons.childrens_talks.public', true);
        $this->assertTrue($this->policy->childrensTalksArePublic());

        Config::set('church.sermons.childrens_talks.public', false);
        $this->assertFalse($this->policy->childrensTalksArePublic());
    }

    #[Test]
    public function can_access_childrens_corner_logic(): void
    {
        $verifiedAdmin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $verifiedUser = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $unverifiedUser = User::factory()->create(['is_admin' => false, 'email_verified_at' => null]);

        // When public is true, everyone can access
        Config::set('church.sermons.childrens_talks.public', true);
        $this->assertTrue($this->policy->canAccessChildrensCorner(null));
        $this->assertTrue($this->policy->canAccessChildrensCorner($verifiedUser));
        $this->assertTrue($this->policy->canAccessChildrensCorner($unverifiedUser));
        $this->assertTrue($this->policy->canAccessChildrensCorner($verifiedAdmin));

        // When public is false, only authenticated + verified users can access
        Config::set('church.sermons.childrens_talks.public', false);
        $this->assertFalse($this->policy->canAccessChildrensCorner(null));
        $this->assertTrue($this->policy->canAccessChildrensCorner($verifiedUser));
        $this->assertFalse($this->policy->canAccessChildrensCorner($unverifiedUser));
        $this->assertTrue($this->policy->canAccessChildrensCorner($verifiedAdmin));
    }

    #[Test]
    public function should_redirect_generic_sermon_route_logic(): void
    {
        $sermon = Sermon::factory()->create(['content_type' => SermonContentType::Sermon]);
        $childrensTalk = Sermon::factory()->create(['content_type' => SermonContentType::ChildrensTalk]);

        Config::set('church.sermons.childrens_talks.public', true);
        $this->assertFalse($this->policy->shouldRedirectGenericSermonRoute($sermon));
        $this->assertTrue($this->policy->shouldRedirectGenericSermonRoute($childrensTalk));

        Config::set('church.sermons.childrens_talks.public', false);
        $this->assertFalse($this->policy->shouldRedirectGenericSermonRoute($sermon));
        $this->assertFalse($this->policy->shouldRedirectGenericSermonRoute($childrensTalk));
    }

    #[Test]
    public function should_expose_on_sermon_api_only_for_sermons(): void
    {
        $sermon = Sermon::factory()->create(['content_type' => SermonContentType::Sermon]);
        $childrensTalk = Sermon::factory()->create(['content_type' => SermonContentType::ChildrensTalk]);

        $this->assertTrue($this->policy->shouldExposeOnSermonApi($sermon));
        $this->assertFalse($this->policy->shouldExposeOnSermonApi($childrensTalk));
    }

    #[Test]
    public function should_include_in_sitemap_logic(): void
    {
        $sermon = Sermon::factory()->create(['content_type' => SermonContentType::Sermon]);
        $childrensTalk = Sermon::factory()->create(['content_type' => SermonContentType::ChildrensTalk]);

        // Sermons are always included
        Config::set('church.sermons.childrens_talks.public', true);
        $this->assertTrue($this->policy->shouldIncludeInSitemap($sermon));
        $this->assertTrue($this->policy->shouldIncludeInSitemap($childrensTalk));

        Config::set('church.sermons.childrens_talks.public', false);
        $this->assertTrue($this->policy->shouldIncludeInSitemap($sermon));
        $this->assertFalse($this->policy->shouldIncludeInSitemap($childrensTalk));
    }

    #[Test]
    public function public_route_name_returns_correct_name(): void
    {
        $sermon = Sermon::factory()->create(['content_type' => SermonContentType::Sermon]);
        $childrensTalk = Sermon::factory()->create(['content_type' => SermonContentType::ChildrensTalk]);

        $this->assertSame('sermons.show', $this->policy->publicRouteName($sermon));
        $this->assertSame('childrens-corner.show', $this->policy->publicRouteName($childrensTalk));
    }

    #[Test]
    public function public_route_parameters_returns_slug(): void
    {
        $sermon = Sermon::factory()->create(['slug' => 'test-slug']);
        $this->assertSame(['sermon' => 'test-slug'], $this->policy->publicRouteParameters($sermon));
    }

    #[Test]
    public function public_url_returns_correct_route(): void
    {
        $sermon = Sermon::factory()->create(['slug' => 'sermon-slug', 'content_type' => SermonContentType::Sermon]);
        $childrensTalk = Sermon::factory()->create(['slug' => 'talk-slug', 'content_type' => SermonContentType::ChildrensTalk]);

        $this->assertSame(route('sermons.show', ['sermon' => 'sermon-slug']), $this->policy->publicUrl($sermon));
        $this->assertSame(route('childrens-corner.show', ['sermon' => 'talk-slug']), $this->policy->publicUrl($childrensTalk));
    }

    #[Test]
    public function canonical_url_logic(): void
    {
        $date = Carbon::create(2025, 5, 20);
        $sermon = Sermon::factory()->create([
            'slug' => 'sermon-slug',
            'content_type' => SermonContentType::Sermon,
            'date' => $date,
        ]);
        $childrensTalk = Sermon::factory()->create([
            'slug' => 'talk-slug',
            'content_type' => SermonContentType::ChildrensTalk,
            'date' => $date,
        ]);

        // Children's talk canonical is just its public URL
        $this->assertSame($this->policy->publicUrl($childrensTalk), $this->policy->canonicalUrl($childrensTalk));

        // Sermon canonical follows specific year/month/slug format
        $expectedSermonCanonical = url('/christ/sermons/2025/05/sermon-slug');
        $this->assertSame($expectedSermonCanonical, $this->policy->canonicalUrl($sermon));
    }

    #[Test]
    public function canonical_url_is_empty_for_a_sermon_without_a_slug(): void
    {
        // Production carries legacy sermons with a blank slug: the slug-format CHECK
        // constraint was added inside a try/catch that silently skips when existing
        // data already violates it, so the bad row survives constraint-free. The
        // dated route is keyed on the slug, so building it would throw
        // UrlGenerationException; the policy returns empty instead, matching
        // publicUrl()'s contract.
        $sermon = $this->createSermonWithBlankSlug();

        $this->assertSame('', $this->policy->canonicalUrl($sermon));
    }

    #[Test]
    public function should_expose_video_hides_rejected_videos_by_default(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        $this->assertFalse($this->policy->shouldExposeVideo($sermon));
    }

    #[Test]
    public function force_show_can_expose_a_rejected_video(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceShow,
        ]);

        $this->assertTrue($this->policy->shouldExposeVideo($sermon));
    }

    #[Test]
    public function force_hide_can_hide_an_approved_video(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Approved,
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceHide,
        ]);

        $this->assertFalse($this->policy->shouldExposeVideo($sermon));
    }

    #[Test]
    public function unassessed_videos_remain_visible_during_backfill_window(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
        ]);

        $this->assertTrue($this->policy->shouldExposeVideo($sermon));
    }

    #[Test]
    public function needs_review_videos_follow_hide_needs_review_configuration(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::NeedsReview,
        ]);

        Config::set('media-processing.video_quality.hide_needs_review', false);
        $this->assertTrue($this->policy->shouldExposeVideo($sermon));

        Config::set('media-processing.video_quality.hide_needs_review', true);
        $this->assertFalse($this->policy->shouldExposeVideo($sermon));
    }

    #[Test]
    public function rejected_videos_keep_non_video_generated_thumbnails(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'thumbnail_file_path' => 'thumbnails/fallback.jpg',
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'thumbnails/fallback.jpg'],
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        $this->assertFalse($this->policy->shouldExposeVideo($sermon));
        $this->assertTrue($this->policy->shouldExposeThumbnail($sermon));
    }

    #[Test]
    public function rejected_videos_hide_video_generated_thumbnails(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'thumbnail_file_path' => 'thumbnails/generated.jpg',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/generated.jpg',
                'video_duration' => 1800.0,
                'selected_thumbnail_candidate_id' => 'candidate-1',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 420.0,
                        'score' => 0.9,
                        'plain_path' => 'thumbnails/generated.jpg',
                    ],
                ],
            ],
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        $this->assertFalse($this->policy->shouldExposeVideo($sermon));
        $this->assertFalse($this->policy->shouldExposeThumbnail($sermon));
    }
}
