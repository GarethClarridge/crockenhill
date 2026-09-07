<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateSermonTranscriptFromServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
    }

    #[Test]
    public function it_stores_the_sermon_slice_of_the_full_service_transcript(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_id' => $sermon->id,
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);
        $serviceTranscriptPath = 'temp/service_transcript_'.$log->processing_id.'.json';
        Storage::disk('local')->put($serviceTranscriptPath, json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 90.0, 'end' => 110.0, 'text' => 'Welcome.'],
            ['start' => 110.0, 'end' => 190.0, 'text' => 'The sermon text.'],
            ['start' => 190.0, 'end' => 210.0, 'text' => 'Closing prayer.'],
        ], 300.0, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $log->putServiceTranscriptPath($serviceTranscriptPath);

        app()->call([new CreateSermonTranscriptFromService($log), 'handle']);

        $log->refresh();
        $sermon->refresh();

        $this->assertSame('transcripts/sermon_'.$sermon->id.'.md', $log->transcript_file_path);
        $this->assertSame($log->transcript_file_path, $sermon->transcript_file_path);
        Storage::disk('local')->assertExists((string) $sermon->transcript_file_path);
        $this->assertSame(
            'Welcome. The sermon text. Closing prayer.',
            Storage::disk('local')->get((string) $sermon->transcript_file_path),
        );
    }

    #[Test]
    public function it_omits_the_material_between_the_extracted_spans(): void
    {
        [$sermon, $log] = $this->runWithServiceTranscript(
            cues: [
                ['start' => 1990.0, 'end' => 2005.0, 'text' => 'Notices before the reading.'],
                ['start' => 2010.0, 'end' => 2095.0, 'text' => 'The preached reading.'],
                ['start' => 2110.0, 'end' => 2330.0, 'text' => 'An intervening hymn, sung twice.'],
                ['start' => 2340.0, 'end' => 3990.0, 'text' => 'The sermon itself.'],
            ],
            sermonStartTime: 2005.65,
            sermonEndTime: 3999.99,
            segments: [
                ['start_time' => 2005.65, 'end_time' => 2099.0],
                ['start_time' => 2337.0, 'end_time' => 3999.99],
            ],
        );

        $this->assertSame(
            'The preached reading. The sermon itself.',
            Storage::disk('local')->get((string) $sermon->refresh()->transcript_file_path),
        );
    }

    #[Test]
    public function it_uses_the_recorded_span_rather_than_the_outer_bounds_for_a_single_span_plan(): void
    {
        [$sermon] = $this->runWithServiceTranscript(
            cues: [
                ['start' => 90.0, 'end' => 110.0, 'text' => 'Welcome.'],
                ['start' => 130.0, 'end' => 190.0, 'text' => 'The sermon text.'],
                ['start' => 190.0, 'end' => 210.0, 'text' => 'Closing prayer.'],
            ],
            sermonStartTime: 100.0,
            sermonEndTime: 200.0,
            segments: [['start_time' => 120.0, 'end_time' => 195.0]],
        );

        $this->assertSame(
            'The sermon text. Closing prayer.',
            Storage::disk('local')->get((string) $sermon->refresh()->transcript_file_path),
        );
    }

    #[Test]
    public function it_falls_back_to_the_recorded_bounds_when_the_plan_holds_no_usable_spans(): void
    {
        [$sermon] = $this->runWithServiceTranscript(
            cues: [
                ['start' => 90.0, 'end' => 110.0, 'text' => 'Welcome.'],
                ['start' => 110.0, 'end' => 190.0, 'text' => 'The sermon text.'],
            ],
            sermonStartTime: 100.0,
            sermonEndTime: 200.0,
            segments: [['start_time' => 300.0, 'end_time' => 300.0]],
        );

        $this->assertSame(
            'Welcome. The sermon text.',
            Storage::disk('local')->get((string) $sermon->refresh()->transcript_file_path),
        );
    }

    #[Test]
    public function it_fails_when_the_extracted_spans_contain_no_speech(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains no sermon text');

        $this->runWithServiceTranscript(
            cues: [
                ['start' => 90.0, 'end' => 110.0, 'text' => 'Welcome.'],
            ],
            sermonStartTime: 100.0,
            sermonEndTime: 200.0,
            segments: [['start_time' => 2000.0, 'end_time' => 2100.0]],
        );
    }

    /**
     * Sermon 1148, 2023-04-30, run 1229: a 1,857-second span with 1,793 seconds
     * of it unobservable. The 81 words that survived are a closing prayer, which
     * is non-empty — so the old check passed it and the run completed clean.
     */
    #[Test]
    public function it_fails_when_the_extracted_span_is_almost_entirely_unobservable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no transcript evidence for 96.5%');

        $this->runWithServiceTranscript(
            cues: [
                ['start' => 2143.0, 'end' => 2210.0, 'text' => 'We thank you, Lord, that you have preserved us thus far.'],
            ],
            sermonStartTime: 2143.07,
            sermonEndTime: 4000.62,
            unobservableWindows: [
                ['start' => 1152.24, 'end' => 1336.24, 'reason' => 'retranscription_failed'],
                ['start' => 1899.24, 'end' => 3936.24, 'reason' => 'retranscription_failed'],
            ],
        );
    }

    /**
     * Sermon 1135, 2023-07-30: 24.5% blind, and it reads perfectly at both ends
     * while its window hides the sermon's opening eight minutes. Nothing in the
     * saved text reveals the loss, so only the recorded window can raise it.
     */
    #[Test]
    public function it_flags_the_sermon_section_when_a_material_part_of_the_span_is_unobservable(): void
    {
        [, $log] = $this->runWithServiceTranscript(
            cues: [
                ['start' => 2650.0, 'end' => 3500.0, 'text' => 'In his commentary on Ephesians, Boyce tells the story.'],
                ['start' => 3500.0, 'end' => 4090.0, 'text' => 'We ask this in Jesus name. Amen.'],
            ],
            sermonStartTime: 2177.34,
            sermonEndTime: 4093.87,
            unobservableWindows: [['start' => 2082.0, 'end' => 2647.0, 'reason' => 'retranscription_failed']],
            withSermonSection: true,
        );

        $section = $log->serviceSections()->firstOrFail();

        $this->assertTrue($section->needs_manual_review);
        $this->assertContains(
            CreateSermonTranscriptFromService::FLAG_EVIDENCE_INCOMPLETE,
            $section->metadata?->toArray()['review_flags'] ?? [],
        );
        $this->assertEqualsWithDelta(
            0.2451,
            $section->metadata?->toArray()['sermon_evidence_unobservable_fraction'] ?? 0.0,
            0.001,
        );
    }

    /**
     * Sermon 942, 2026-04-12: 5.1% blind and carrying 3,898 words. Brief blind
     * intervals are ordinary, and holding them would be the false-positive that
     * makes the gate useless.
     */
    #[Test]
    public function it_completes_without_a_flag_when_the_unobservable_part_is_immaterial(): void
    {
        [, $log] = $this->runWithServiceTranscript(
            cues: [['start' => 1000.0, 'end' => 2990.0, 'text' => 'A complete sermon.']],
            sermonStartTime: 1000.0,
            sermonEndTime: 3002.0,
            unobservableWindows: [['start' => 1200.0, 'end' => 1302.0, 'reason' => 'retranscription_failed']],
            withSermonSection: true,
        );

        $section = $log->serviceSections()->firstOrFail();

        $this->assertFalse($section->needs_manual_review);
        $this->assertNotContains(
            CreateSermonTranscriptFromService::FLAG_EVIDENCE_INCOMPLETE,
            $section->metadata?->toArray()['review_flags'] ?? [],
        );
    }

    /**
     * Sermon 1220, 2025-02-16: its extraction plan routed *around* the blind
     * interval with two spans, so only 0.59 seconds of window falls inside the
     * delivered media. What the listener never receives cannot be missing from it.
     */
    #[Test]
    public function it_ignores_an_unobservable_window_lying_between_the_extracted_spans(): void
    {
        [$sermon] = $this->runWithServiceTranscript(
            cues: [
                ['start' => 1780.0, 'end' => 1849.0, 'text' => 'Jesus heals ten men with leprosy.'],
                ['start' => 2085.0, 'end' => 3489.0, 'text' => 'The purpose of Jesus life was to die.'],
            ],
            sermonStartTime: 1778.99,
            sermonEndTime: 3489.96,
            segments: [
                ['start_time' => 1778.99, 'end_time' => 1850.0],
                ['start_time' => 2080.99, 'end_time' => 3489.96],
            ],
            unobservableWindows: [['start' => 1859.66, 'end' => 2081.58, 'reason' => 'retranscription_failed']],
            withSermonSection: true,
        );

        $this->assertSame(
            'Jesus heals ten men with leprosy. The purpose of Jesus life was to die.',
            Storage::disk('local')->get((string) $sermon->refresh()->transcript_file_path),
        );
    }

    /**
     * A valid sermon followed by long silence: the window sits wholly beyond the
     * span, so it says nothing about the delivered media.
     */
    #[Test]
    public function it_ignores_an_unobservable_window_beyond_the_end_of_the_span(): void
    {
        [, $log] = $this->runWithServiceTranscript(
            cues: [['start' => 100.0, 'end' => 1000.0, 'text' => 'A complete sermon.']],
            sermonStartTime: 100.0,
            sermonEndTime: 1000.0,
            unobservableWindows: [['start' => 1000.0, 'end' => 4200.0, 'reason' => 'retranscription_failed']],
            withSermonSection: true,
        );

        $this->assertFalse($log->serviceSections()->firstOrFail()->needs_manual_review);
    }

    /**
     * Windows are sorted but never merged, so two that overlap must not spend
     * their shared seconds twice and push the fraction past a threshold — or
     * past one at all.
     */
    #[Test]
    public function it_counts_overlapping_unobservable_windows_once(): void
    {
        [, $log] = $this->runWithServiceTranscript(
            cues: [['start' => 100.0, 'end' => 1000.0, 'text' => 'A complete sermon.']],
            sermonStartTime: 0.0,
            sermonEndTime: 1000.0,
            unobservableWindows: [
                ['start' => 100.0, 'end' => 250.0, 'reason' => 'retranscription_failed'],
                ['start' => 200.0, 'end' => 320.0, 'reason' => 'retranscription_failed'],
            ],
            withSermonSection: true,
        );

        $section = $log->serviceSections()->firstOrFail();

        // The union spans 100-320, so 220 seconds of 1,000 — not the 270 the two
        // windows sum to, which would overstate the loss by more than a tenth.
        $this->assertEqualsWithDelta(
            0.22,
            $section->metadata?->toArray()['sermon_evidence_unobservable_fraction'] ?? 0.0,
            0.001,
        );
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start_time: float, end_time: float}>|null  $segments
     * @param  list<array{start: float, end: float, reason: string}>  $unobservableWindows
     * @return array{0: Sermon, 1: MediaProcessingLog}
     */
    private function runWithServiceTranscript(
        array $cues,
        float $sermonStartTime,
        float $sermonEndTime,
        ?array $segments = null,
        array $unobservableWindows = [],
        bool $withSermonSection = false,
    ): array {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_id' => $sermon->id,
            'sermon_start_time' => $sermonStartTime,
            'sermon_end_time' => $sermonEndTime,
        ]);

        if ($segments !== null) {
            $metadata = $log->processing_metadata?->toArray() ?? [];
            $metadata['sermon_extraction_plan'] = [
                'source' => 'service_sections',
                'mode' => count($segments) > 1 ? 'concat_spans' : 'single_span',
                'segments' => $segments,
            ];
            $log->forceFill(['processing_metadata' => $metadata])->save();
            $log->refresh();
        }

        if ($withSermonSection) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => ServiceSectionType::Sermon,
                'start_time' => $sermonStartTime,
                'end_time' => $sermonEndTime,
                'needs_manual_review' => false,
                'metadata' => ['review_flags' => []],
            ]);
        }

        $serviceTranscriptPath = 'temp/service_transcript_'.$log->processing_id.'.json';
        Storage::disk('local')->put($serviceTranscriptPath, json_encode(ChurchServiceTranscript::fromCues(
            $cues,
            4200.0,
            ChurchServiceTranscript::SOURCE_MOCK,
            $unobservableWindows,
        )->toArray(), JSON_THROW_ON_ERROR));
        $log->putServiceTranscriptPath($serviceTranscriptPath);

        app()->call([new CreateSermonTranscriptFromService($log), 'handle']);

        return [$sermon, $log];
    }
}
