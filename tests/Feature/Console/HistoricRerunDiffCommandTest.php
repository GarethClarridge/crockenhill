<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
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
            ->expectsOutputToContain('left manual review')
            ->expectsOutputToContain('lost its extracted media')
            ->assertFailed();
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
        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_start_time' => 600.0,
            'sermon_end_time' => 2400.0,
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
