<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricRerunDiffCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = 'rerun-diff-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists(storage_path('app/private/'.$this->directory));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/'.$this->directory));

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_snapshot_bound_to_its_membership(): void
    {
        $run = $this->processingRun();
        $this->section($run, ServiceSectionType::Sermon, 600, 2400);

        $this->artisan('historic-import:rerun-snapshot', ['runs' => [$run->id, $run->id], '--output' => $this->path('before.json')])
            ->expectsOutputToContain('Captured 1 run(s)')
            ->assertSuccessful();

        $snapshot = HistoricRerunSnapshot::fromFile(storage_path('app/private/'.$this->path('before.json')));

        self::assertSame([$run->id], $snapshot->membership);
        self::assertCount(1, $snapshot->runs[$run->id]['sections']);
        self::assertSame('0600', substr(sprintf('%o', fileperms(storage_path('app/private/'.$this->path('before.json')))), -4));
    }

    #[Test]
    public function it_refuses_to_overwrite_a_snapshot(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-snapshot', ['runs' => [$run->id], '--output' => $this->path('before.json')])
            ->expectsOutputToContain('created once')
            ->assertFailed();
    }

    #[Test]
    public function it_refuses_a_run_that_does_not_exist(): void
    {
        $this->artisan('historic-import:rerun-snapshot', ['runs' => [999_999], '--output' => $this->path('before.json')])
            ->expectsOutputToContain('run #999999 not found')
            ->assertFailed();

        self::assertFileDoesNotExist(storage_path('app/private/'.$this->path('before.json')));
    }

    #[Test]
    public function it_reports_nothing_for_an_unchanged_run(): void
    {
        $run = $this->processingRun();
        $this->section($run, ServiceSectionType::Song, 100, 300);
        $this->section($run, ServiceSectionType::Sermon, 600, 2400);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->expectsOutputToContain('0 of 1 run(s) changed; 0 need attention')
            ->assertSuccessful();

        self::assertSame([], $this->report()['runs'][(string) $run->id]['changes']);
    }

    /**
     * Sync renumbers every section after an insert. Pairing by order would compare each
     * shifted section with its neighbour and report every one as changed.
     */
    #[Test]
    public function it_pairs_sections_by_overlap_so_an_insert_reads_as_one_addition(): void
    {
        $run = $this->processingRun();
        $this->section($run, ServiceSectionType::Song, 100, 300, order: 1);
        $reading = $this->section($run, ServiceSectionType::BibleReading, 400, 550, order: 2);
        $sermon = $this->section($run, ServiceSectionType::Sermon, 600, 2400, order: 3);
        $this->snapshot([$run->id]);

        $sermon->update(['section_order' => 4]);
        $reading->update(['section_order' => 3]);
        $this->section($run, ServiceSectionType::Prayer, 320, 390, order: 2);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->assertSuccessful();

        $changes = $this->report()['runs'][(string) $run->id]['changes'];

        self::assertSame(['section_added'], array_column($changes, 'kind'));
        self::assertStringContainsString('prayer 320.0–390.0', $changes[0]['section']);
    }

    #[Test]
    public function it_reports_retyping_rebinding_and_span_moves_on_a_paired_section(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Other, 100, 400);
        $this->snapshot([$run->id]);

        $section->update(['section_type' => ServiceSectionType::ShortTalk, 'end_time' => 380, 'duration' => 280, 'title' => 'Augustine of Hippo']);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->assertSuccessful();

        $kinds = array_column($this->report()['runs'][(string) $run->id]['changes'], 'kind');

        self::assertContains('section_retyped', $kinds);
        self::assertContains('section_retitled', $kinds);
        self::assertContains('section_span_moved', $kinds);
    }

    #[Test]
    public function it_ignores_boundary_snapping_below_half_a_second(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Sermon, 600, 2400);
        $this->snapshot([$run->id]);

        $section->update(['end_time' => 2400.3, 'duration' => 1800.3]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('0 of 1 run(s) changed')
            ->assertSuccessful();
    }

    #[Test]
    public function it_needs_attention_when_a_live_hold_is_gone(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300, holds: [$this->hold('Looped text')]);
        $this->snapshot([$run->id]);

        $section->update(['metadata' => ['review_flags' => ['content_defect_hold']]]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->expectsOutputToContain('live loop_screen hold on')
            ->assertFailed();

        self::assertSame(['hold_dropped'], array_column($this->report()['runs'][(string) $run->id]['changes'], 'kind'));
    }

    #[Test]
    public function it_reports_a_hold_cleared_by_its_check_without_needing_attention(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300, holds: [$this->hold('Looped text')]);
        $this->snapshot([$run->id]);

        $section->update(['metadata' => ['content_holds' => [[...$this->hold('Looped text'), 'cleared_at' => now()->toIso8601String()]], 'review_flags' => ['content_defect_hold']]]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->assertSuccessful();

        self::assertSame(['hold_cleared'], array_column($this->report()['runs'][(string) $run->id]['changes'], 'kind'));
    }

    /**
     * A hold follows its content, so a re-detection that splits or moves a section may
     * leave it on a different row. That is containment kept, not lost.
     */
    #[Test]
    public function it_follows_a_hold_carried_to_another_section(): void
    {
        $run = $this->processingRun();
        $first = $this->section($run, ServiceSectionType::Song, 100, 300, holds: [$this->hold('Looped text')]);
        $second = $this->section($run, ServiceSectionType::Song, 400, 600);
        $this->snapshot([$run->id]);

        $first->update(['metadata' => []]);
        $second->update(['metadata' => ['content_holds' => [$this->hold('Looped text')]]]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->assertSuccessful();

        $kinds = array_column($this->report()['runs'][(string) $run->id]['changes'], 'kind');

        self::assertContains('hold_carried', $kinds);
        self::assertNotContains('hold_dropped', $kinds);
    }

    #[Test]
    public function it_needs_attention_when_a_section_leaves_review_or_loses_its_media(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Sermon, 600, 2400);
        $section->update(['needs_manual_review' => true, 'extracted_video_path' => 'sections/1.mp4', 'extracted_at' => now()]);
        $this->snapshot([$run->id]);

        $section->update(['needs_manual_review' => false, 'extracted_video_path' => null, 'extracted_at' => null]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('left review')
            ->expectsOutputToContain('lost its extracted media')
            ->assertFailed();
    }

    /**
     * A detection round cuts nothing (plan §4.0), so its sections lose their old clips and the
     * review a clip decides has not been made yet. Those are pending until the frozen commit's
     * extraction, whose diff applies the full checks; they do not fail a round.
     */
    #[Test]
    public function a_detection_round_leaves_media_custody_pending_rather_than_failing(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300);
        $section->update(['needs_manual_review' => true, 'extracted_video_path' => 'sections/1.mp4', 'extracted_at' => now()]);
        $this->snapshot([$run->id]);

        $section->update(['needs_manual_review' => false, 'extracted_video_path' => null, 'extracted_at' => null]);
        $this->deferMedia($run);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->expectsOutputToContain('have media work pending')
            ->assertSuccessful();

        $result = $this->report()['runs'][(string) $run->id];

        self::assertSame([], $result['attention']);
        self::assertContains('Song 100.0–300.0 → Song 100.0–300.0 lost its extracted media', array_map(static fn (string $item): string => str_replace('§'.$section->id.' song', 'Song', $item), $result['pending']));
    }

    /**
     * Deferring media defers only what media decides. A hold is content, and losing one fails a
     * round as it fails any re-run.
     */
    #[Test]
    public function a_detection_round_still_needs_attention_when_a_live_hold_is_gone(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Sermon, 600, 2400, holds: [$this->hold('Saved sermon text repeats a loop')]);
        $this->snapshot([$run->id]);

        $section->update(['metadata' => []]);
        $this->deferMedia($run);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('hold on')
            ->assertFailed();
    }

    /**
     * The round records the plan extraction would cut on its stamp; `sermon_extraction_plan`
     * still describes the media that exists, so the round's plan is what the diff compares.
     */
    #[Test]
    public function a_detection_round_is_compared_on_the_plan_it_would_cut(): void
    {
        $run = $this->processingRun();
        $run->update(['processing_metadata' => ['sermon_extraction_plan' => ['mode' => 'single_span', 'segments' => [['start_time' => 600.0, 'end_time' => 2400.0]]]]]);
        $this->snapshot([$run->id]);

        $this->deferMedia($run, ['mode' => 'single_span', 'segments' => [['start_time' => 640.0, 'end_time' => 2400.0]]]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->assertSuccessful();

        $kinds = array_column($this->report()['runs'][(string) $run->id]['changes'], 'kind');

        self::assertContains('run_extraction_plan_changed', $kinds);
        self::assertContains('sermon_span_moved', $kinds);
    }

    /**
     * A round never runs publication, so a section that became published during one is not
     * pending anything: it is a real change of custody.
     */
    #[Test]
    public function a_detection_round_still_needs_attention_when_a_section_becomes_published(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::ShortTalk, 100, 300);
        $this->snapshot([$run->id]);

        $section->forceFill([
            'publication_status' => 'published',
            'published_sermon_id' => Sermon::factory()->create()->id,
            'published_at' => now(),
            'extracted_video_path' => 'sections/1.mp4',
            'extracted_audio_path' => 'sections/1.mp3',
            'extracted_at' => now(),
        ])->save();
        $this->deferMedia($run);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('became public')
            ->assertFailed();
    }

    /**
     * Song review is a publication state, not `needs_manual_review`: only the boundary-evidence
     * backfill ever set the flag on a song. A song whose flag drops while it still waits for
     * approval has not left review (15 of canary 1's 23 reports).
     */
    #[Test]
    public function a_song_still_pending_approval_has_not_left_review(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300);
        $section->update(['needs_manual_review' => true, 'publication_status' => 'pending_approval']);
        $this->snapshot([$run->id]);

        $section->update(['needs_manual_review' => false]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->doesntExpectOutputToContain('left manual review')
            ->assertSuccessful();
    }

    #[Test]
    public function a_section_that_leaves_approval_still_leaves_review(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300);
        $section->update(['publication_status' => 'pending_approval', 'extracted_video_path' => 'sections/1.mp4', 'extracted_audio_path' => 'sections/1.mp3', 'extracted_at' => now()]);
        $this->snapshot([$run->id]);

        $section->update(['publication_status' => 'approved']);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('left review')
            ->assertFailed();
    }

    /**
     * The historic path publishes a song's clip into quarantine: the section reads `published`
     * while its video stays out of public view, pending §4.5. That is a change, not a custody loss.
     */
    #[Test]
    public function a_song_published_into_quarantine_is_a_change_not_an_exposure(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300);
        $this->snapshot([$run->id]);

        $section->forceFill(['publication_status' => 'published', 'published_at' => now(), 'extracted_video_path' => 'sections/1.mp4', 'extracted_audio_path' => 'sections/1.mp3', 'extracted_at' => now()])->save();
        SongVideo::factory()->quarantined()->create(['service_section_id' => $section->id]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->doesntExpectOutputToContain('became public')
            ->assertSuccessful();

        self::assertContains('section_published_into_quarantine', array_column($this->report()['runs'][(string) $run->id]['changes'], 'kind'));
    }

    #[Test]
    public function a_song_video_that_becomes_public_needs_attention(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300);
        $video = SongVideo::factory()->quarantined()->create(['service_section_id' => $section->id]);
        $this->snapshot([$run->id]);

        $video->update(['publication_state' => 'published']);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('became public')
            ->assertFailed();
    }

    #[Test]
    public function a_sermon_that_becomes_public_needs_attention(): void
    {
        $run = $this->processingRun();
        $run->sermon?->update(['publication_state' => 'quarantined']);
        $this->snapshot([$run->id]);

        $run->sermon?->update(['publication_state' => 'published']);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('became public')
            ->assertFailed();
    }

    /**
     * Extraction parks a run whose sermon is held: containment working, with a named repair
     * (`sermons:re-extract --held-section`). It is pending, not a failed re-run.
     */
    #[Test]
    public function a_run_parked_for_its_held_sermon_is_pending_not_failed(): void
    {
        $run = $this->processingRun();
        $this->section($run, ServiceSectionType::Sermon, 600, 2400, holds: [$this->hold('H10b listening: the stored transcript is wrong in this window')]);
        $this->snapshot([$run->id]);

        $run->update([
            'status' => 'failed',
            'current_step' => 'manual_review_required',
            'processing_metadata' => ['manual_review' => ['status' => 'required', 'reason_code' => 'sermon_section_content_held']],
        ]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->expectsOutputToContain('parked for its held sermon')
            ->assertSuccessful();

        self::assertSame([], $this->report()['runs'][(string) $run->id]['attention']);
    }

    /**
     * Tier C cuts with the render deferred (plan §4.0, "cut now, render later"): the videos
     * exist at source bitrate and release refuses them until `rerun-render` lands. That is
     * pending, and it clears once the run is rendered.
     */
    #[Test]
    public function a_run_whose_render_is_deferred_is_pending_until_it_is_rendered(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);
        $run->putCorpusRerunStamp([
            'grounds' => 'corpus_rerun',
            'git_commit' => str_repeat('a', 40),
            'media' => 'extracted',
            'render' => 'deferred',
        ]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->expectsOutputToContain('render deferred')
            ->assertSuccessful();

        $diff = $this->report()['runs'][(string) $run->id];
        self::assertSame([], $diff['attention']);
        self::assertCount(1, array_filter($diff['pending'], static fn (string $line): bool => str_contains($line, 'historic-import:rerun-render')));

        $run->amendLatestCorpusRerunStamp(['render' => 'rendered']);

        // Reports are written once, so the rendered diff gets its own.
        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff-rendered.json')])
            ->assertSuccessful();

        $rendered = json_decode((string) file_get_contents(storage_path('app/private/'.$this->path('diff-rendered.json'))), true);
        self::assertSame([], $rendered['runs'][(string) $run->id]['pending']);
    }

    #[Test]
    public function it_reports_a_song_review_verdict_that_changed(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300);
        $section->update(['metadata' => ['song_publication_review' => ['reasons' => [['kind' => 'short_song_clip']]]]]);
        $this->snapshot([$run->id]);

        $section->update(['metadata' => ['song_publication_review' => ['reasons' => [['kind' => 'song_identity_unverified']]]]]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json'), '--output' => $this->path('diff.json')])
            ->assertSuccessful();

        $changes = array_values(array_filter(
            $this->report()['runs'][(string) $run->id]['changes'],
            static fn (array $change): bool => $change['kind'] === 'section_song_review_changed',
        ));

        self::assertSame([['short_song_clip'], ['song_identity_unverified']], [$changes[0]['before'], $changes[0]['after']]);
    }

    #[Test]
    public function it_needs_attention_when_a_run_is_left_unfinished(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);

        $run->update(['status' => 'failed', 'current_step' => 'extract_sermon']);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('run is failed after the re-run')
            ->assertFailed();
    }

    /**
     * Canary 3 re-detected run 936, a failed sibling took its service in reconciliation, and the
     * diff reported the supersession as a plain change: every later round then refused the run.
     */
    #[Test]
    public function it_needs_attention_when_a_run_is_superseded_by_the_re_run(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);

        $run->update(['superseded_at' => now()]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('run was superseded by the re-run')
            ->assertFailed();
    }

    /**
     * Enrichment is queued, not awaited, so nothing noticed when sermons 908–915 never got
     * their passage. Every batch's diff is the reconciliation: a reference without a passage
     * needs attention whether or not the re-run changed it.
     */
    #[Test]
    public function it_needs_attention_for_a_sermon_reference_with_no_linked_passage(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'Titus 3:1-8', 'scripture_passage_id' => null]);
        $run = $this->processingRun();
        $run->update(['sermon_id' => $sermon->id]);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain(sprintf('sermon %d names Titus 3:1-8 but no passage is linked', $sermon->id))
            ->assertFailed();
    }

    #[Test]
    public function it_does_not_flag_a_sermon_with_no_reference(): void
    {
        $sermon = Sermon::factory()->create(['reference' => null, 'scripture_passage_id' => null]);
        $run = $this->processingRun();
        $run->update(['sermon_id' => $sermon->id]);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('0 need attention')
            ->assertSuccessful();
    }

    #[Test]
    public function it_does_not_flag_a_run_that_was_already_unfinished(): void
    {
        $run = $this->processingRun();
        $run->update(['status' => 'failed', 'current_step' => 'manual_review_required']);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('0 of 1 run(s) changed; 0 need attention')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_a_snapshot_whose_membership_was_edited(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);

        $path = storage_path('app/private/'.$this->path('before.json'));
        $data = json_decode((string) file_get_contents($path), true);
        $data['membership'][] = 1;
        chmod($path, 0600);
        file_put_contents($path, json_encode($data));

        $this->artisan('historic-import:rerun-diff', ['snapshot' => $this->path('before.json')])
            ->expectsOutputToContain('membership does not match its recorded hash')
            ->assertFailed();
    }

    private function processingRun(): MediaProcessingLog
    {
        // A linked sermon, as every historic sermon but 908–915 is; the unlinked case has its own test.
        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_id' => Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => ScripturePassage::factory()])->id,
            'sermon_start_time' => 600.0,
            'sermon_end_time' => 2400.0,
        ]);
    }

    /**
     * Stamp the run as a finished detection round that deferred its media.
     *
     * @param  array<string, mixed>|null  $plan
     */
    private function deferMedia(MediaProcessingLog $run, ?array $plan = null): void
    {
        $run->putCorpusRerunStamp([
            'grounds' => 'corpus_rerun',
            'git_commit' => str_repeat('a', 40),
            'media' => 'deferred',
            'dispatched_at' => '2026-09-24T19:00:00+00:00',
            'media_recorded_at' => '2026-09-24T19:30:00+00:00',
            'deferred_extraction_plan' => $plan ?? $run->fresh()?->processing_metadata?->toArray()['sermon_extraction_plan'] ?? null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $holds
     */
    private function section(MediaProcessingLog $run, ServiceSectionType $type, float $start, float $end, ?int $order = null, array $holds = []): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => $type,
            'section_order' => $order ?? (int) $start,
            'title' => ucfirst($type->value),
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'metadata' => $holds === [] ? [] : ['content_holds' => $holds, 'review_flags' => ['content_defect_hold']],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hold(string $reason): array
    {
        return [
            'reason' => $reason,
            'evidence' => 'test',
            'found_by' => ContentHoldCheck::LoopScreen->value,
            'start_time' => 100,
            'end_time' => 300,
            'held_at' => '2026-09-24T00:00:00+00:00',
        ];
    }

    /**
     * @param  list<int>  $runIds
     */
    private function snapshot(array $runIds): void
    {
        $this->artisan('historic-import:rerun-snapshot', ['runs' => $runIds, '--output' => $this->path('before.json')])
            ->assertSuccessful();
    }

    private function path(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        return json_decode((string) file_get_contents(storage_path('app/private/'.$this->path('diff.json'))), true);
    }
}
