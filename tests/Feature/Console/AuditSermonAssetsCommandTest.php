<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonContentType;
use App\Models\Sermon;
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

        // Creating children's talks dispatches MoveSermonToPrivateStorage via
        // the observer; the audit must read the rows as they stand, unmoved.
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

    #[Test]
    public function it_flags_a_childrens_talk_asset_outside_private_storage_even_when_the_file_exists(): void
    {
        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/talk.mp3',
        ]);

        Storage::disk('public')->put('sermons/talk.mp3', 'asset');

        $this->artisan('audit:sermon-assets')->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['audio']['childrens_talk_public']);
        $this->assertSame(1, $report['kinds']['audio']['present']);
    }

    #[Test]
    public function it_passes_for_a_childrens_talk_whose_assets_live_under_private_local_storage(): void
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
        $this->assertSame(0, $report['kinds']['audio']['childrens_talk_public']);
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

    /** @return array{kinds: array<string, array<string, int>>, findings?: list<array{sermon_id: int, kind: string, issue: string}>} */
    private function jsonReport(string ...$options): array
    {
        Artisan::call(implode(' ', ['audit:sermon-assets --json', ...$options]));

        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report);

        return $report;
    }
}
