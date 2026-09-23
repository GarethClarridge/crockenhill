<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ContentHoldRechecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecheckContentHoldsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSCRIPT_PATH = 'service-transcripts/run.json';

    private ServiceSection $sermon;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('media-processing.storage.transcript_disk', 'local');
        Storage::disk('local')->put(self::TRANSCRIPT_PATH, json_encode(['cues' => [
            ['start' => 0.0, 'end' => 10.0, 'text' => 'Let us turn to the gospel of Mark this morning.'],
            ['start' => 10.0, 'end' => 20.0, 'text' => 'They had no buildings, no money and no influence.'],
        ]], JSON_THROW_ON_ERROR));

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_metadata' => ['service_transcript_path' => self::TRANSCRIPT_PATH],
        ]);
        $this->sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon,
            'start_time' => 0.0,
            'end_time' => 200.0,
            'duration' => 200.0,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);
        app(HoldSectionForContentReview::class)($this->sermon, 'Saved text loops', 'register', ContentHoldCheck::LoopScreen);

        // Found on the transcript before it was re-decoded.
        $metadata = $this->sermon->refresh()->metadata?->toArray() ?? [];
        $metadata[HoldSectionForContentReview::METADATA_KEY][0]['transcript_sha256'] = ContentHoldRechecker::SUPERSEDED;
        $this->sermon->forceFill(['metadata' => $metadata])->save();
    }

    #[Test]
    public function a_dry_run_reports_what_would_clear_and_writes_nothing(): void
    {
        $this->artisan('service:recheck-content-holds')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        self::assertTrue($this->sermon->refresh()->needs_manual_review);
    }

    #[Test]
    public function it_clears_the_holds_whose_check_now_passes(): void
    {
        $this->artisan('service:recheck-content-holds', ['--execute' => true])
            ->expectsOutputToContain('Cleared 1 hold record(s); 0 still found by their check.')
            ->assertSuccessful();

        self::assertFalse($this->sermon->refresh()->needs_manual_review);
    }
}
