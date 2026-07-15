<?php

declare(strict_types=1);

namespace Tests\Integration\Presenters;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonViewPresenterTest extends TestCase
{
    use RefreshDatabase;

    private SermonViewPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2025-03-15 12:00:00'));

        Storage::fake('public');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('thumbnail-generation.storage.disk', 'public');
        Config::set('media-processing.storage.transcript_disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'public');

        $this->presenter = app(SermonViewPresenter::class);
    }

    #[Test]
    public function duration_iso8601_returns_null_when_duration_is_null_or_zero(): void
    {
        $sermonNull = Sermon::factory()->make(['duration' => null]);
        $sermonZero = Sermon::factory()->make(['duration' => 0]);

        $this->assertNull($this->presenter->durationIso8601($sermonNull));
        $this->assertNull($this->presenter->durationIso8601($sermonZero));
    }

    #[Test]
    public function duration_iso8601_formats_correctly(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 2700]); // 45 minutes

        $this->assertSame('PT45M', $this->presenter->durationIso8601($sermon));
    }

    #[Test]
    public function plain_text_outline_returns_null_when_points_are_missing(): void
    {
        $sermon = Sermon::factory()->make(['points' => null]);

        $this->assertNull($this->presenter->plainTextOutline($sermon));
    }

    #[Test]
    public function plain_text_outline_formats_structured_points(): void
    {
        $sermon = Sermon::factory()->make([
            'points' => [
                [
                    'point' => 'First point',
                    'sub_points' => ['First sub point'],
                ],
                'Second point',
            ],
        ]);

        $this->assertSame(
            "1. First point\n   - First sub point\n2. Second point",
            $this->presenter->plainTextOutline($sermon),
        );
    }

    #[Test]
    public function service_label_uses_the_sermon_service_enum_label(): void
    {
        $sermon = Sermon::factory()->make(['service' => SermonService::Morning]);

        $this->assertSame('Morning', $this->presenter->serviceLabel($sermon));
    }

    #[Test]
    public function preacher_image_url_returns_null_when_no_profile_or_image(): void
    {
        $sermon = Sermon::factory()->make(['preacher_id' => null, 'preacher' => 'John Doe']);

        $this->assertNull($this->presenter->preacherImageUrl($sermon));
    }

    #[Test]
    public function preacher_image_url_returns_url_from_profile(): void
    {
        $preacher = Preacher::factory()->create([
            'image_path' => 'preachers/john.jpg',
        ]);
        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
        ]);
        $sermon->load('preacherProfile');

        $url = $this->presenter->preacherImageUrl($sermon);
        $this->assertStringContainsString('/storage/preachers/john.jpg', $url ?? '');
    }

    #[Test]
    public function it_presents_explicit_media_and_link_data(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Test Preacher',
            'slug' => 'test-preacher',
        ]);

        Storage::disk('public')->put('sermons/test.mp3', 'audio');
        Storage::disk('public')->put('sermons/test.mp4', 'video');
        Storage::disk('public')->put('thumbnails/test.jpg', 'thumb');
        Storage::disk('public')->put('transcripts/test.md', 'Transcript body');

        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));

        $sermon = Sermon::factory()->create([
            'slug' => 'presented-sermon',
            'date' => now()->toDateString(),
            'preacher' => 'Test Preacher',
            'preacher_id' => $preacher->id,
            'audio_file_path' => 'sermons/test.mp3',
            'video_file_path' => 'sermons/test.mp4',
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'thumbnails/test.jpg'],
            'transcript_file_path' => 'transcripts/test.md',
            'duration' => 3600,
        ]);

        $sermon->load('preacherProfile');

        $presented = $this->presenter->present($sermon);

        $this->assertStringContainsString('/storage/sermons/test.mp3', $presented['audio_url'] ?? '');
        $this->assertStringContainsString('?v=', $presented['audio_url'] ?? '');
        $this->assertSame('http://localhost/christ/sermons/2026/02/presented-sermon', $presented['canonical_url']);
        $this->assertStringContainsString('/storage/thumbnails/test.jpg', $presented['card_thumbnail_url'] ?? '');
        $this->assertSame('http://localhost/christ/sermons/preachers/test-preacher', $presented['preacher_url']);
        $this->assertSame('http://localhost/christ/sermons/presented-sermon', $presented['public_url']);
        $this->assertStringContainsString('/storage/thumbnails/test.jpg', $presented['thumbnail_url'] ?? '');
        $this->assertStringContainsString('?v=', $presented['thumbnail_url'] ?? '');
        $this->assertSame('Transcript body', $presented['transcript']);
        $this->assertStringContainsString('/storage/sermons/test.mp4', $presented['video_url'] ?? '');
        $this->assertSame('PT1H', $presented['duration_iso8601']);
    }

    #[Test]
    public function it_exposes_plain_thumbnail_url_separately_from_card_thumbnail_url(): void
    {
        Storage::disk('public')->put('thumbnails/plain.jpg', 'plain');
        Storage::disk('public')->put('thumbnails/card.jpg', 'card');

        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'thumbnails/overlay.jpg',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/plain.jpg',
                'card_thumbnail_path' => 'thumbnails/card.jpg',
            ],
        ]);

        $this->assertStringContainsString('/storage/thumbnails/plain.jpg', $this->presenter->plainThumbnailUrl($sermon) ?? '');
        $this->assertStringContainsString('/storage/thumbnails/card.jpg', $this->presenter->cardThumbnailUrl($sermon) ?? '');
    }

    #[Test]
    public function plain_url_falls_back_to_primary_when_metadata_unselected_but_card_url_returns_null(): void
    {
        Storage::disk('public')->put('thumbnails/primary.jpg', 'primary');

        Sermon::factory()->create([
            'slug' => 'slimmed-query-sermon',
            'thumbnail_file_path' => 'thumbnails/primary.jpg',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/plain.jpg',
                'card_thumbnail_path' => 'thumbnails/card.jpg',
            ],
        ]);

        $slimmed = Sermon::query()
            ->select(['id', 'slug', 'thumbnail_file_path', 'date', 'content_type', 'video_quality_status', 'video_visibility_override'])
            ->where('slug', 'slimmed-query-sermon')
            ->first();

        $this->assertNotNull($slimmed);
        $this->assertStringContainsString('/storage/thumbnails/primary.jpg', $this->presenter->plainThumbnailUrl($slimmed) ?? '');
        $this->assertNull($this->presenter->cardThumbnailUrl($slimmed));
    }

    #[Test]
    public function it_returns_null_optional_media_and_fallback_preacher_url_when_missing(): void
    {
        $sermon = Sermon::factory()->create([
            'preacher' => 'John Doe',
            'preacher_id' => null,
            'audio_file_path' => null,
            'video_file_path' => null,
            'thumbnail_file_path' => null,
            'transcript_file_path' => null,
        ]);

        $presented = $this->presenter->present($sermon);

        $this->assertNull($presented['audio_url']);
        $this->assertNull($presented['card_thumbnail_url']);
        $this->assertSame('http://localhost/christ/sermons/preachers/john-doe', $presented['preacher_url']);
        $this->assertNull($presented['thumbnail_url']);
        $this->assertNull($presented['transcript']);
        $this->assertNull($presented['video_url']);
    }

    #[Test]
    public function it_hides_rejected_video_urls_and_video_generated_thumbnails(): void
    {
        Storage::disk('public')->put('sermons/test.mp4', 'video');
        Storage::disk('public')->put('thumbnails/test.jpg', 'thumb');

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/test.mp4',
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/test.jpg',
                'video_duration' => 1800.0,
                'selected_thumbnail_candidate_id' => 'candidate-1',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 300.0,
                        'score' => 0.9,
                        'plain_path' => 'thumbnails/test.jpg',
                    ],
                ],
            ],
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        $presented = $this->presenter->present($sermon);

        $this->assertNull($presented['video_url']);
        $this->assertNull($presented['thumbnail_url']);
        $this->assertNull($presented['card_thumbnail_url']);
    }

    #[Test]
    public function it_keeps_non_video_generated_thumbnails_for_rejected_videos(): void
    {
        Storage::disk('public')->put('sermons/test.mp4', 'video');
        Storage::disk('public')->put('thumbnails/fallback.jpg', 'thumb');

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/test.mp4',
            'thumbnail_file_path' => 'thumbnails/fallback.jpg',
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'thumbnails/fallback.jpg'],
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        $presented = $this->presenter->present($sermon);

        $this->assertNull($presented['video_url']);
        $this->assertStringContainsString('/storage/thumbnails/fallback.jpg', $presented['thumbnail_url'] ?? '');
        $this->assertStringContainsString('/storage/thumbnails/fallback.jpg', $presented['card_thumbnail_url'] ?? '');
    }

    #[Test]
    public function it_allows_force_show_to_expose_a_rejected_video(): void
    {
        Storage::disk('public')->put('sermons/test.mp4', 'video');

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/test.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceShow,
        ]);

        $presented = $this->presenter->present($sermon);

        $this->assertStringContainsString('/storage/sermons/test.mp4', $presented['video_url'] ?? '');
    }

    #[Test]
    public function it_uses_guarded_routes_for_private_childrens_talk_media(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'private-childrens-talk',
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/audio/private-childrens-talk.mp3',
            'video_file_path' => 'private/sermons/video/private-childrens-talk.mp4',
            'thumbnail_file_path' => 'private/thumbnails/private-childrens-talk.jpg',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'private/thumbnails/private-childrens-talk-plain.jpg',
                'card_thumbnail_path' => 'private/thumbnails/private-childrens-talk-card.jpg',
            ],
        ]);

        $presented = $this->presenter->present($sermon);

        $this->assertSame(route('sermons.audio', ['sermon' => $sermon->slug]), $presented['audio_url']);
        $this->assertSame(route('sermons.video', ['sermon' => $sermon->slug]), $presented['video_url']);
        $this->assertSame(route('sermons.thumbnail', ['sermon' => $sermon->slug]), $presented['thumbnail_url']);
        $this->assertSame(route('sermons.thumbnail.card', ['sermon' => $sermon->slug]), $presented['card_thumbnail_url']);
        $this->assertSame(route('sermons.thumbnail.plain', ['sermon' => $sermon->slug]), $this->presenter->plainThumbnailUrl($sermon));
    }

    #[Test]
    public function formatted_duration_returns_null_when_duration_is_null(): void
    {
        $sermon = Sermon::factory()->make(['duration' => null]);

        $this->assertNull($this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_returns_null_when_duration_is_zero(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 0]);

        $this->assertNull($this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_returns_null_when_duration_is_negative(): void
    {
        $sermon = Sermon::factory()->make(['duration' => -60]);

        $this->assertNull($this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_formats_minutes_only(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 1800]); // 30 minutes

        $this->assertSame('30m', $this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_formats_hours_and_minutes(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 5400]); // 1h 30m

        $this->assertSame('1h 30m', $this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_handles_exactly_one_hour(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 3600]); // 1h 0m

        $this->assertSame('1h 0m', $this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_handles_sub_minute_duration(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 45]); // 0m 45s → 0m

        $this->assertSame('0m', $this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function image_alt_includes_title_and_preacher_name(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'Test Sermon Title',
            'preacher' => 'John Smith',
            'preacher_id' => null,
        ]);

        $this->assertSame(
            'Sermon: Test Sermon Title by John Smith',
            $this->presenter->imageAlt($sermon),
        );
    }

    #[Test]
    public function image_alt_omits_preacher_suffix_when_preacher_is_missing(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'Anonymous Sermon',
            'preacher' => '',
            'preacher_id' => null,
        ]);

        $this->assertSame('Sermon: Anonymous Sermon', $this->presenter->imageAlt($sermon));
    }

    #[Test]
    public function childrens_talk_image_alt_uses_childrens_corner_prefix(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'The Lost Sheep',
            'preacher' => 'Jane Doe',
            'preacher_id' => null,
        ]);

        $this->assertSame(
            "Children's Corner: The Lost Sheep by Jane Doe",
            $this->presenter->childrensTalkImageAlt($sermon),
        );
    }

    #[Test]
    public function meta_description_returns_explicit_attribute_when_set(): void
    {
        $sermon = Sermon::factory()->make([
            'meta_description' => 'Custom meta description.',
        ]);

        $this->assertSame('Custom meta description.', $this->presenter->metaDescription($sermon));
    }

    #[Test]
    public function meta_description_generates_default_from_title_preacher_and_date(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'The Prodigal Son',
            'preacher' => 'John Smith',
            'date' => now()->subDay(),
            'service' => null,
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertStringContainsString("Listen to 'The Prodigal Son' by John Smith", $description);
        $this->assertStringContainsString('preached at Crockenhill Baptist Church on March 14, 2025', $description);
    }

    #[Test]
    public function meta_description_includes_scripture_reference_and_series(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'T',
            'preacher' => 'P',
            'date' => now()->subDay(),
            'reference' => 'Luke 15:11-32',
            'series' => 'Parables of Jesus',
            'service' => null,
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertStringContainsString(' - Luke 15:11-32', $description);
        $this->assertStringContainsString('(Part of the Parables of Jesus series)', $description);
    }

    #[Test]
    public function meta_description_includes_summary_when_enabled_and_strips_tags(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'T',
            'preacher' => 'P',
            'date' => now()->subDay(),
            'reference' => null,
            'series' => null,
            'service' => null,
            'show_summary' => true,
            'summary' => '<p>This is a <strong>great</strong> sermon summary.</p>',
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertStringContainsString('This is a great sermon summary.', $description);
        $this->assertStringNotContainsString('<p>', $description);
    }

    #[Test]
    public function meta_description_ignores_summary_when_disabled(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'The Prodigal Son',
            'preacher' => 'John Smith',
            'date' => now()->subDay(),
            'reference' => null,
            'series' => null,
            'show_summary' => false,
            'summary' => 'This summary should be ignored.',
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertStringNotContainsString('This summary should be ignored.', $description);
    }

    #[Test]
    public function meta_description_enforces_limit_with_truncation(): void
    {
        $longTitle = str_repeat('Very Long Sermon Title ', 10);
        $sermon = Sermon::factory()->make([
            'title' => $longTitle,
            'preacher' => 'John Smith',
            'date' => now()->subDay(),
            'reference' => null,
            'series' => null,
        ]);

        $description = $this->presenter->metaDescription($sermon);

        // Str::limit(s, 155) appends '...' making it 158 chars if limit is reached.
        $this->assertLessThanOrEqual(158, strlen($description));
        $this->assertStringEndsWith('...', $description);
    }

    #[Test]
    public function meta_description_truncates_summary_to_fit(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'The Prodigal Son',
            'preacher' => 'John Smith',
            'date' => now()->subDay(),
            'reference' => null,
            'series' => null,
            'show_summary' => true,
            'summary' => str_repeat('This is a very long summary that should definitely be truncated to ensure the total length remains within expected limits. ', 10),
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertLessThanOrEqual(158, strlen($description));
        $this->assertStringContainsString("Listen to 'The Prodigal Son' by John Smith", $description);
        $this->assertStringEndsWith('...', $description);
    }

    #[Test]
    public function display_preacher_name_uses_loaded_relation(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Dr. John Smith',
            'slug' => 'dr-john-smith',
        ]);

        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);
        $sermon->load('preacherProfile');

        $name = $this->presenter->displayPreacherName($sermon);
        $this->assertSame('Dr. John Smith', $name);
    }

    #[Test]
    public function display_reference_uses_loaded_relation(): void
    {
        $passage = ScripturePassage::factory()->create([
            'display_reference' => 'Romans 3:23-28',
            'normalized_reference' => 'Romans 3:23-28',
        ]);

        $sermon = Sermon::factory()->create([
            'scripture_passage_id' => $passage->id,
            'reference' => 'Romans',
        ]);
        $sermon->load('scripturePassage');

        $ref = $this->presenter->displayReference($sermon);
        $this->assertSame('Romans 3:23-28', $ref);
    }

    #[Test]
    public function preacher_image_url_returns_value_when_loaded(): void
    {
        $preacher = Preacher::factory()->create([
            'image_path' => 'preachers/test-image.jpg',
        ]);

        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
        ]);
        $sermon->load('preacherProfile');

        $url = $this->presenter->preacherImageUrl($sermon);
        $this->assertNotNull($url);
        $this->assertStringContainsString('test-image.jpg', $url);
    }

    #[Test]
    public function display_preacher_name_prefers_loaded_relation_over_string_fallback(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Dr. Jane Smith']);
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);
        DB::table('sermons')->where('id', $sermon->id)->update(['preacher' => 'Legacy Name']);

        $this->presenter->clearInternalCaches();
        $first = $this->presenter->displayPreacherName($sermon->fresh());
        $this->assertSame('Legacy Name', $first);

        $loaded = $sermon->fresh();
        $loaded->load('preacherProfile');
        $second = $this->presenter->displayPreacherName($loaded);

        $this->assertSame('Dr. Jane Smith', $second);
    }

    #[Test]
    public function preacher_image_url_prefers_loaded_relation_over_cached_unloaded_null(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Pastor Jane',
            'image_path' => 'preachers/jane.jpg',
        ]);
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);

        $this->presenter->clearInternalCaches();
        $this->assertNull($this->presenter->preacherImageUrl($sermon->fresh()));

        $loaded = $sermon->fresh();
        $loaded->load('preacherProfile');

        $url = $this->presenter->preacherImageUrl($loaded);

        $this->assertNotNull($url);
        $this->assertStringContainsString('preachers/jane.jpg', $url);
    }

    #[Test]
    public function display_reference_prefers_loaded_relation_over_string_fallback(): void
    {
        $passage = ScripturePassage::factory()->create([
            'display_reference' => 'Romans 3:23-28',
            'normalized_reference' => 'Romans 3:23-28',
        ]);
        $sermon = Sermon::factory()->create([
            'scripture_passage_id' => $passage->id,
            'reference' => 'Romans',
        ]);
        DB::table('sermons')->where('id', $sermon->id)->update(['reference' => 'Romans']);

        $this->presenter->clearInternalCaches();
        $first = $this->presenter->displayReference($sermon->fresh());
        $this->assertSame('Romans', $first);

        $loaded = $sermon->fresh();
        $loaded->load('scripturePassage');

        $second = $this->presenter->displayReference($loaded);

        $this->assertSame('Romans 3:23-28', $second);
    }

    #[Test]
    public function preacher_image_url_reflects_relation_when_loaded_after_first_call(): void
    {
        $preacher = Preacher::factory()->create(['image_path' => 'preachers/jane.jpg']);
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);

        // Without relation: no way to determine the image URL, so returns null
        $this->presenter->clearInternalCaches();
        $first = $this->presenter->preacherImageUrl($sermon->fresh());
        $this->assertNull($first);

        // Load the relation on the same presenter instance — must now return the image URL
        $loaded = $sermon->fresh();
        $loaded->load('preacherProfile');
        $second = $this->presenter->preacherImageUrl($loaded);

        $this->assertNotNull($second);
        $this->assertStringContainsString('jane.jpg', $second);
    }

    #[Test]
    public function clear_internal_caches_causes_recomputation_on_next_call(): void
    {
        // Use a sermon with a known duration so the null-caching path is exercised
        $sermon = Sermon::factory()->create([
            'duration' => null,
            'reference' => 'Romans 8:28',
            'audio_file_path' => null,
        ]);

        // Prime all caches — null is a valid cached result here
        $this->assertNull($this->presenter->formattedDuration($sermon));
        $this->assertNull($this->presenter->durationIso8601($sermon));
        $this->assertNull($this->presenter->audioUrl($sermon));
        $this->assertSame('Romans 8:28', $this->presenter->displayReference($sermon));

        // Now mutate the model directly (bypassing cache) and clear caches
        $sermon->duration = 3600; // 1 hour
        $sermon->reference = 'John 3:16';

        $this->presenter->clearInternalCaches();

        // Re-computation must reflect the mutated values, not the old cached nulls
        $this->assertSame('1h 0m', $this->presenter->formattedDuration($sermon));
        $this->assertSame('PT1H', $this->presenter->durationIso8601($sermon));
        $this->assertSame('John 3:16', $this->presenter->displayReference($sermon));
    }

    #[Test]
    public function pre_warm_for_admin_list_populates_caches_behaviorally(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Original Name']);
        $passage = ScripturePassage::factory()->create(['display_reference' => 'Original Ref']);

        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'scripture_passage_id' => $passage->id,
            'date' => now()->toDateString(),
            'service' => SermonService::Morning,
        ]);
        $sermon->load(['preacherProfile', 'scripturePassage']);

        // 1. Pre-warm the presenter with the sermon collection
        $this->presenter->preWarmForAdminList(collect([$sermon]));

        // 2. Mutate related data in memory. If the presenter cached these during
        // pre-warm, it will NOT consult the profile/passage relations again.
        $sermon->preacherProfile->name = 'New Name';
        $sermon->scripturePassage->display_reference = 'New Ref';

        // 3. Subsequent calls must hit the cache and return the original (pre-warmed) values.
        // This confirms pre-warm did its job without inspecting private properties.
        $this->assertSame('Original Name', $this->presenter->displayPreacherName($sermon));
        $this->assertSame('Original Ref', $this->presenter->displayReference($sermon));

        // These don't depend on external mutable state, but we check them to ensure
        // pre-warm didn't break basic presentation.
        $this->assertSame('March 15, 2025', $this->presenter->formattedDates($sermon)['human']);
        $this->assertSame('Morning', $this->presenter->serviceLabel($sermon));

        // 4. Clear caches and verify it now picks up the mutated values.
        $this->presenter->clearInternalCaches();
        $this->assertSame('New Name', $this->presenter->displayPreacherName($sermon));
        $this->assertSame('New Ref', $this->presenter->displayReference($sermon));
    }
}
