<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonContentType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditSermonAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Storage::fake('public');
        Storage::fake('local');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.transcript_disk' => 'public',
            'thumbnail-generation.storage.disk' => 'public',
        ]);
    }

    #[Test]
    public function it_passes_when_every_referenced_asset_exists_on_its_expected_disk(): void
    {
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/audit.mp3',
            'video_file_path' => 'sermons/video/audit.mp4',
            'transcript_file_path' => 'transcripts/audit.txt',
            'thumbnail_file_path' => 'sermons/thumbnails/audit.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/audit-plain.webp',
                'card_thumbnail_path' => 'sermons/thumbnails/audit-card.webp',
                'overlay_thumbnail_path' => 'sermons/thumbnails/audit-overlay.webp',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.9,
                        'plain_path' => 'sermons/thumbnails/candidates/audit-1-plain.webp',
                        'card_path' => 'sermons/thumbnails/candidates/audit-1-card.webp',
                        'overlay_path' => 'sermons/thumbnails/candidates/audit-1-overlay.webp',
                    ],
                ],
            ],
        ]);

        foreach ([
            'sermons/audit.mp3',
            'sermons/video/audit.mp4',
            'transcripts/audit.txt',
            'sermons/thumbnails/audit.webp',
            'sermons/thumbnails/audit-plain.webp',
            'sermons/thumbnails/audit-card.webp',
            'sermons/thumbnails/audit-overlay.webp',
            'sermons/thumbnails/candidates/audit-1-plain.webp',
            'sermons/thumbnails/candidates/audit-1-card.webp',
            'sermons/thumbnails/candidates/audit-1-overlay.webp',
        ] as $path) {
            Storage::disk('public')->put($path, 'asset');
        }

        $this->artisan('audit:sermon-assets')
            ->expectsOutputToContain('Sermon asset audit is clean')
            ->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['audio']['present']);
        $this->assertSame(1, $report['kinds']['candidate_overlay']['present']);
        $this->assertSame(0, array_sum(array_column($report['kinds'], 'missing')));
    }

    #[Test]
    public function it_fails_on_a_missing_asset_without_printing_its_path(): void
    {
        Sermon::factory()->create(['audio_file_path' => 'sermons/never-uploaded.mp3']);

        $this->artisan('audit:sermon-assets')
            ->doesntExpectOutputToContain('sermons/never-uploaded.mp3')
            ->expectsOutputToContain('Sermon asset audit found problems')
            ->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['audio']['missing']);
        $this->assertArrayNotHasKey('findings', $report);
    }

    #[Test]
    public function it_lists_sermon_ids_but_never_paths_with_the_details_option(): void
    {
        $sermon = Sermon::factory()->create(['audio_file_path' => 'sermons/never-uploaded.mp3']);

        $this->artisan('audit:sermon-assets --details')
            ->doesntExpectOutputToContain('sermons/never-uploaded.mp3')
            ->expectsOutputToContain((string) $sermon->id)
            ->assertFailed();

        $report = $this->jsonReport('--details');

        $this->assertSame(
            [['sermon_id' => $sermon->id, 'kind' => 'audio', 'issue' => 'missing']],
            $report['findings'],
        );
    }

    /**
     * A children's talk on the ordinary sermon disk is now the correct state, not
     * a fault. The audit used to report it as a failure, which after WP3b would
     * have flagged the entire archive.
     */
    #[Test]
    public function it_passes_for_a_childrens_talk_asset_on_the_ordinary_sermon_disk(): void
    {
        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/talk.mp3',
        ]);

        Storage::disk('public')->put('sermons/talk.mp3', 'asset');

        $this->artisan('audit:sermon-assets')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['audio']['present']);
        $this->assertSame(0, $report['kinds']['audio']['private_referenced']);
    }

    /**
     * Nothing writes `private/` sermon paths any more, but a legacy row must still
     * be audited against the local disk rather than reported as missing from the
     * sermon disk.
     */
    #[Test]
    public function it_still_audits_a_legacy_private_path_against_the_local_disk(): void
    {
        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/talk.mp3',
            'transcript_file_path' => 'private/transcripts/talk.txt',
        ]);

        Storage::disk('local')->put('private/sermons/talk.mp3', 'asset');
        Storage::disk('local')->put('private/transcripts/talk.txt', 'asset');

        $this->artisan('audit:sermon-assets')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['audio']['present']);
        $this->assertSame(1, $report['kinds']['transcript']['present']);
        $this->assertSame(1, $report['kinds']['audio']['private_referenced']);
    }

    #[Test]
    public function it_audits_thumbnail_candidates_individually(): void
    {
        Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.9,
                        'plain_path' => 'sermons/thumbnails/candidates/present.webp',
                        'card_path' => 'sermons/thumbnails/candidates/absent.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/candidates/present.webp', 'asset');

        $this->artisan('audit:sermon-assets')->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['candidate_plain']['present']);
        $this->assertSame(1, $report['kinds']['candidate_card']['missing']);
        $this->assertSame(0, $report['kinds']['candidate_overlay']['referenced']);
    }

    #[Test]
    public function it_separates_private_asset_counts_from_public_ones(): void
    {
        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/lost-talk.mp3',
        ]);

        Sermon::factory()->create(['audio_file_path' => 'sermons/lost-sermon.mp3']);

        $this->artisan('audit:sermon-assets')->assertFailed();

        $report = $this->jsonReport();

        // Both are missing, but only the private one is destroyed by a deploy —
        // the split is what makes that distinguishable in a CI log.
        $this->assertSame(2, $report['kinds']['audio']['missing']);
        $this->assertSame(1, $report['kinds']['audio']['private_referenced']);
        $this->assertSame(1, $report['kinds']['audio']['private_missing']);
    }

    #[Test]
    public function it_counts_childrens_talks_per_talk_rather_than_per_asset(): void
    {
        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/intact.mp3',
            'transcript_file_path' => 'private/transcripts/intact.txt',
        ]);

        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/half-lost.mp3',
            'transcript_file_path' => 'private/transcripts/half-lost.txt',
        ]);

        // A talk that never made it into private storage still counts towards
        // the total, but has nothing for the migration to move.
        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/never-privatised.mp3',
        ]);

        Storage::disk('local')->put('private/sermons/intact.mp3', 'asset');
        Storage::disk('local')->put('private/transcripts/intact.txt', 'asset');
        Storage::disk('local')->put('private/sermons/half-lost.mp3', 'asset');
        Storage::disk('public')->put('sermons/never-privatised.mp3', 'asset');

        $this->artisan('audit:sermon-assets')->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(3, $report['childrens_talks']['total']);
        $this->assertSame(2, $report['childrens_talks']['with_private_assets']);
        $this->assertSame(1, $report['childrens_talks']['with_missing_private_assets']);
    }

    #[Test]
    public function it_partitions_talks_with_missing_assets_by_whether_the_source_recording_survives(): void
    {
        $recoverable = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/recoverable.mp3',
        ]);
        MediaProcessingLog::factory()->livestream()->create([
            'sermon_id' => $recoverable->id,
            'source_file_path' => 'livestream/temp/survivor.mp4',
        ]);

        $lost = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/lost.mp3',
        ]);
        MediaProcessingLog::factory()->livestream()->create([
            'sermon_id' => $lost->id,
            'source_file_path' => 'livestream/temp/wiped-by-deploy.mp4',
        ]);

        // No processing run at all — a historic or manually created talk.
        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/orphan.mp3',
        ]);

        Storage::disk('local')->put('livestream/temp/survivor.mp4', 'source');

        $this->artisan('audit:sermon-assets')->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(3, $report['childrens_talks']['with_missing_private_assets']);
        $this->assertSame(1, $report['childrens_talks']['missing_and_source_media_present']);
        $this->assertSame(1, $report['childrens_talks']['missing_and_source_media_gone']);
        $this->assertSame(1, $report['childrens_talks']['missing_and_no_source_reference']);
    }

    #[Test]
    public function it_reaches_the_source_recording_through_the_publishing_service_section(): void
    {
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/via-section.mp3',
        ]);

        // sermon_id is not set on the log, so the only route to the source
        // recording is the section that published this talk.
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_id' => null,
            'source_file_path' => 'livestream/temp/via-section.mp4',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'published_sermon_id' => $sermon->id,
        ]);

        Storage::disk('local')->put('livestream/temp/via-section.mp4', 'source');

        $this->artisan('audit:sermon-assets')->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['childrens_talks']['missing_and_source_media_present']);
        $this->assertSame(0, $report['childrens_talks']['missing_and_no_source_reference']);
    }

    /**
     * The exact fault this reporting exists for: the thumbnail disk was
     * repointed at Spaces without the objects being moved, so the bytes sat on
     * `public` under the key the database already recorded. Before this, the
     * audit could only say "missing", and telling the two apart took a manual
     * probe of an individual key.
     */
    #[Test]
    public function it_reports_an_asset_that_survives_on_another_disk_as_stranded_rather_than_missing(): void
    {
        config(['thumbnail-generation.storage.disk' => 'do_spaces']);
        Storage::fake('do_spaces');

        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/repointed.webp']);

        // Written before the repointing, and never moved with it.
        Storage::disk('public')->put('sermons/thumbnails/repointed.webp', 'asset');

        $this->artisan('audit:sermon-assets')
            ->expectsOutputToContain('Stranded assets exist on another disk')
            ->doesntExpectOutputToContain('sermons/thumbnails/repointed.webp')
            ->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['thumbnail']['stranded']);
        $this->assertSame(0, $report['kinds']['thumbnail']['missing']);
        $this->assertSame(0, $report['kinds']['thumbnail']['present']);
    }

    #[Test]
    public function it_names_the_disk_an_asset_is_stranded_on_without_printing_its_path(): void
    {
        config(['thumbnail-generation.storage.disk' => 'do_spaces']);
        Storage::fake('do_spaces');

        $sermon = Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/repointed.webp']);
        Storage::disk('public')->put('sermons/thumbnails/repointed.webp', 'asset');

        $this->artisan('audit:sermon-assets --details')
            ->doesntExpectOutputToContain('sermons/thumbnails/repointed.webp')
            ->assertFailed();

        $report = $this->jsonReport('--details');

        $this->assertSame(
            [['sermon_id' => $sermon->id, 'kind' => 'thumbnail', 'issue' => 'stranded on public']],
            $report['findings'],
        );
    }

    /**
     * An asset absent from every disk is a real loss, and must not be softened
     * into `stranded` by a probe that happens to find nothing.
     */
    #[Test]
    public function it_still_reports_an_asset_absent_from_every_disk_as_missing(): void
    {
        config(['media-processing.storage.transcript_disk' => 'do_spaces']);
        Storage::fake('do_spaces');

        Sermon::factory()->create(['transcript_file_path' => 'transcripts/sermon_39.md']);

        $this->artisan('audit:sermon-assets')
            ->doesntExpectOutputToContain('Stranded assets exist on another disk')
            ->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['transcript']['missing']);
        $this->assertSame(0, $report['kinds']['transcript']['stranded']);
    }

    /**
     * Stranded is still broken from a visitor's perspective — every reader
     * resolves the configured disk, so the page 404s whatever else survives.
     */
    #[Test]
    public function it_keeps_a_stranded_asset_in_the_failure_condition(): void
    {
        config(['thumbnail-generation.storage.disk' => 'do_spaces']);
        Storage::fake('do_spaces');

        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/repointed.webp']);
        Storage::disk('public')->put('sermons/thumbnails/repointed.webp', 'asset');

        $this->artisan('audit:sermon-assets')
            ->doesntExpectOutputToContain('Sermon asset audit is clean')
            ->assertFailed();
    }

    /**
     * The four outcomes are a partition of every referenced asset. Without this,
     * a future asset kind could be counted in neither column and quietly vanish
     * from the audit's arithmetic — which is how a whole family of losses stays
     * invisible.
     */
    #[Test]
    public function every_referenced_asset_falls_into_exactly_one_outcome(): void
    {
        config(['thumbnail-generation.storage.disk' => 'do_spaces']);
        Storage::fake('do_spaces');

        Sermon::factory()->create([
            'audio_file_path' => 'sermons/present.mp3',
            'transcript_file_path' => 'transcripts/gone.md',
            'thumbnail_file_path' => 'sermons/thumbnails/stranded.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/on-spaces.webp',
                'card_thumbnail_path' => 'sermons/thumbnails/also-gone.webp',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.9,
                        'plain_path' => 'sermons/thumbnails/candidates/stranded.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/present.mp3', 'asset');
        Storage::disk('public')->put('sermons/thumbnails/stranded.webp', 'asset');
        Storage::disk('public')->put('sermons/thumbnails/candidates/stranded.webp', 'asset');
        Storage::disk('do_spaces')->put('sermons/thumbnails/on-spaces.webp', 'asset');

        $report = $this->jsonReport();

        foreach ($report['kinds'] as $kind => $counts) {
            $this->assertSame(
                $counts['referenced'],
                $counts['present'] + $counts['missing'] + $counts['stranded'] + $counts['check_errors'],
                "Outcome counts do not partition the referenced assets for [{$kind}].",
            );
        }

        // And the fixture really did exercise all three non-error outcomes.
        $this->assertSame(2, array_sum(array_column($report['kinds'], 'present')));
        $this->assertSame(2, array_sum(array_column($report['kinds'], 'missing')));
        $this->assertSame(2, array_sum(array_column($report['kinds'], 'stranded')));
    }

    /** @return array{kinds: array<string, array<string, int>>, childrens_talks: array<string, int>, findings?: list<array{sermon_id: int, kind: string, issue: string}>} */
    private function jsonReport(string ...$options): array
    {
        Artisan::call(implode(' ', ['audit:sermon-assets --json', ...$options]));

        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report);

        return $report;
    }
}
