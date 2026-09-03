<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\SpeakerIdentificationInterface;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\SpeakerProfile;
use App\Support\MediaAssetPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RedetectChildrensTalkSpeakersCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The stale class the command exists for: a flag raised in July 2026 because no speaker
     * profiles were active, still held after profiles arrived, because nothing re-asks.
     */
    #[Test]
    public function it_withdraws_a_flag_left_by_a_condition_that_has_since_stopped_being_true(): void
    {
        $this->mock(SpeakerIdentificationInterface::class)->shouldNotReceive('identify');
        Storage::fake(MediaAssetPath::disk());

        $section = $this->childrensTalk([
            'review_flags' => ['childrens_talk_speaker_review'],
            'review_reason' => 'childrens_talk_speaker_unconfigured',
            'childrens_talk_speaker' => ['predicted' => ['outcome' => 'no_profiles']],
        ]);

        $this->artisan('services:redetect-childrens-talk-speakers', ['--execute' => true])
            ->assertSuccessful();

        $section->refresh();

        // Audio is gone, so the honest answer is a fact about the input, not a question.
        $this->assertSame('missing_audio', $section->metadata?->toArray()['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertNotContains('childrens_talk_speaker_review', $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    /**
     * The guard that makes this command safe to run unscoped.
     *
     * An `ambiguous` row was answered by the model against audio that has since been reaped.
     * Re-running it now would resolve to `missing_audio` and disposition it — retiring a
     * genuine open question because the evidence was cleaned up. That is the laundering
     * shape the unscoped song-match recompute of 2026-09-03 nearly shipped.
     */
    #[Test]
    public function it_leaves_a_scored_open_question_alone_even_though_its_audio_is_gone(): void
    {
        $this->mock(SpeakerIdentificationInterface::class)->shouldNotReceive('identify');
        Storage::fake(MediaAssetPath::disk());

        $section = $this->childrensTalk([
            'review_flags' => ['childrens_talk_speaker_review'],
            'review_reason' => 'childrens_talk_speaker_ambiguous',
            'childrens_talk_speaker' => ['predicted' => [
                'outcome' => 'ambiguous',
                'confidence' => 0.838,
                'margin' => 0.087,
            ]],
        ]);

        $this->artisan('services:redetect-childrens-talk-speakers', ['--execute' => true])
            ->assertSuccessful();

        $section->refresh();

        $this->assertSame('ambiguous', $section->metadata?->toArray()['childrens_talk_speaker']['predicted']['outcome'] ?? null);
        $this->assertContains('childrens_talk_speaker_review', $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_writes_nothing_without_the_execute_option(): void
    {
        $this->mock(SpeakerIdentificationInterface::class)->shouldNotReceive('identify');
        Storage::fake(MediaAssetPath::disk());

        $section = $this->childrensTalk([
            'review_flags' => ['childrens_talk_speaker_review'],
            'review_reason' => 'childrens_talk_speaker_unconfigured',
            'childrens_talk_speaker' => ['predicted' => ['outcome' => 'no_profiles']],
        ]);

        $this->artisan('services:redetect-childrens-talk-speakers')->assertSuccessful();

        $section->refresh();

        $this->assertContains('childrens_talk_speaker_review', $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_skips_sections_on_superseded_runs(): void
    {
        $this->mock(SpeakerIdentificationInterface::class)->shouldNotReceive('identify');
        Storage::fake(MediaAssetPath::disk());

        $section = $this->childrensTalk([
            'review_flags' => ['childrens_talk_speaker_review'],
            'childrens_talk_speaker' => ['predicted' => ['outcome' => 'no_profiles']],
        ], supersededRun: true);

        $this->artisan('services:redetect-childrens-talk-speakers', ['--execute' => true])
            ->assertSuccessful();

        $section->refresh();

        $this->assertContains('childrens_talk_speaker_review', $section->metadata?->toArray()['review_flags'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function childrensTalk(array $metadata, bool $supersededRun = false): ServiceSection
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.min_duration' => 30,
            'media-processing.speaker_identification.provider' => 'null',
        ]);

        SpeakerProfile::factory()->create();

        $log = MediaProcessingLog::factory()->livestream()->create(
            $supersededRun ? ['superseded_at' => now()] : []
        );

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_audio_path' => 'section-publications/reaped.mp3',
            'start_time' => 0,
            'end_time' => 300,
            'needs_manual_review' => true,
            'metadata' => $metadata,
        ]);
    }
}
