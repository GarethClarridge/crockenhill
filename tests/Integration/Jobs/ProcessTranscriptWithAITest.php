<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Enums\SermonTitleProvenance;
use App\Jobs\ProcessTranscriptWithAI;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Public\SermonRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class ProcessTranscriptWithAITest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private string $sampleTranscript = 'Today we look at John chapter 10, where Jesus describes himself as the Good Shepherd. This is a rich passage full of meaning for believers. Jesus says he knows his sheep and his sheep know him, and he lays down his life for the sheep. The shepherd imagery would have been very familiar to Jesus\'s audience in first century Palestine.';

    private function createAnalysis(string $title = 'The Good Shepherd'): SermonAnalysis
    {
        return SermonAnalysis::create(
            title: $title,
            series: 'Gospel of John',
            reference: 'John 10:1-18',
            points: ['Jesus is the Good Shepherd', 'He knows His sheep'],
            summary: 'A sermon about Jesus as the Good Shepherd.',
            transcript: $this->sampleTranscript
        );
    }

    #[Test]
    public function it_has_correct_retry_configuration(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->make();
        $job = new ProcessTranscriptWithAI($log);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(600, $job->timeout);
        $this->assertEquals([120, 300, 600], $job->backoff());
    }

    #[Test]
    public function it_processes_transcript_and_updates_sermon(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Untitled Sermon',
            'reference' => null,
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $analysis = $this->createAnalysis('The Good Shepherd');

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($analysis);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('The Good Shepherd', $sermon->title);
        $this->assertSame(SermonTitleProvenance::AiAnalysis, $sermon->title_provenance);
        $this->assertEquals('John 10:1-18', $sermon->reference);
        $this->assertEquals('A sermon about Jesus as the Good Shepherd.', $sermon->summary);

        $log->refresh();
        $this->assertNotNull($log->ai_analysis);
        $this->assertFalse($log->is_degraded_completion);
        $this->assertSame(1, $log->attempt_count);
    }

    /**
     * The pilot's real shape: a filename title carrying the backup suffix the
     * archive stamps on a recovered upload, with a good analysis title waiting.
     * All fifteen sermons kept the filename because the placeholder rules did
     * not know this shape.
     */
    #[Test]
    public function it_replaces_a_backup_suffixed_filename_title(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Sunday 22Nd October 2023 [Youtube Backup]',
            'slug' => 'sunday-22nd-october-2023-youtube-backup',
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->method('analyzeSermon')->willReturn($this->createAnalysis('Jesus is enough'));

        Log::shouldReceive('info')->atLeast()->once();

        (new ProcessTranscriptWithAI($log))->handle(
            $mockService,
            $this->app->make(SermonRepository::class),
        );

        $sermon->refresh();
        $this->assertSame('Jesus is enough', $sermon->title);
        $this->assertSame('jesus-is-enough', $sermon->slug);
        $this->assertSame(SermonTitleProvenance::AiAnalysis, $sermon->title_provenance);
    }

    #[Test]
    public function it_replaces_known_generated_canary_filename_titles(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $cases = [
            [
                'title' => 'Sunday 23 January 2022 101',
                'slug' => 'sunday-23-january-2022-101',
                'analysis_title' => 'Praying for our daily needs',
            ],
            [
                'title' => 'Carols By Candlelight 19 December 2021',
                'slug' => 'carols-by-candlelight-19-december-2021',
                'analysis_title' => 'God’s indescribable gift',
            ],
        ];

        $sermons = [];
        $logs = [];

        foreach ($cases as $case) {
            $sermon = Sermon::factory()->create([
                'title' => $case['title'],
                'slug' => $case['slug'],
                'title_provenance' => SermonTitleProvenance::Generated,
            ]);
            $sermons[] = $sermon;
            $logs[] = MediaProcessingLog::factory()->audio()->processing()->create([
                'sermon_id' => $sermon->id,
                'transcript_file_path' => 'transcripts/1/transcript.txt',
            ]);
        }

        $analysisService = $this->createMock(SermonAnalysisInterface::class);
        $analysisService->expects($this->exactly(2))
            ->method('analyzeSermon')
            ->willReturnOnConsecutiveCalls(
                $this->createAnalysis($cases[0]['analysis_title']),
                $this->createAnalysis($cases[1]['analysis_title']),
            );

        Log::shouldReceive('info')->atLeast()->once();

        foreach ($logs as $log) {
            (new ProcessTranscriptWithAI($log))->handle(
                $analysisService,
                $this->app->make(SermonRepository::class),
            );
        }

        foreach ($cases as $index => $case) {
            $sermons[$index]->refresh();

            $this->assertSame($case['analysis_title'], $sermons[$index]->title);
            $this->assertSame(SermonTitleProvenance::AiAnalysis, $sermons[$index]->title_provenance);
        }
    }

    #[Test]
    public function it_does_not_replace_a_curated_date_title_that_matches_the_legacy_recogniser(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Sunday 3Rd May 2026',
            'slug' => 'sunday-3rd-may-2026',
            'title_provenance' => SermonTitleProvenance::Curated,
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $analysisService = $this->createMock(SermonAnalysisInterface::class);
        $analysisService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($this->createAnalysis('A Proper Sermon Title'));

        Log::shouldReceive('info')->atLeast()->once();

        (new ProcessTranscriptWithAI($log))->handle(
            $analysisService,
            $this->app->make(SermonRepository::class),
        );

        $sermon->refresh();
        $this->assertSame('Sunday 3Rd May 2026', $sermon->title);
        $this->assertSame('sunday-3rd-may-2026', $sermon->slug);
        $this->assertSame(SermonTitleProvenance::Curated, $sermon->title_provenance);
    }

    #[Test]
    public function it_leaves_a_curated_title_and_slug_alone(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'The shepherd who lays down his life',
            'slug' => 'shepherd-lays-down-his-life',
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->method('analyzeSermon')->willReturn($this->createAnalysis('The Good Shepherd'));

        Log::shouldReceive('info')->atLeast()->once();

        (new ProcessTranscriptWithAI($log))->handle(
            $mockService,
            $this->app->make(SermonRepository::class),
        );

        $sermon->refresh();
        $this->assertSame('The shepherd who lays down his life', $sermon->title);
        $this->assertSame('shepherd-lays-down-his-life', $sermon->slug);
    }

    /**
     * A historic run's series is a guess against a list the model cannot check.
     * One naming a Bible book can be checked against the sermon's own reference;
     * one that cannot is held back rather than written.
     */
    #[Test]
    public function it_applies_a_historic_series_its_reference_corroborates(): void
    {
        $log = $this->historicRun($sermon);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->method('analyzeSermon')->willReturn(SermonAnalysis::create(
            title: 'Why we sing: Christ has defeated death',
            series: 'The Book of Exodus',
            reference: 'Exodus 15:1-21',
            points: ['One'],
            summary: 'A summary.',
            transcript: $this->sampleTranscript,
        ));

        Log::shouldReceive('info')->atLeast()->once();

        (new ProcessTranscriptWithAI($log))->handle(
            $mockService,
            $this->app->make(SermonRepository::class),
        );

        $sermon->refresh();
        $this->assertSame('The Book of Exodus', $sermon->series);
        $this->assertArrayNotHasKey(
            'sermon_series_suggestion',
            $log->refresh()->processing_metadata?->toArray() ?? [],
        );
    }

    #[Test]
    public function it_holds_back_a_historic_series_nothing_corroborates(): void
    {
        $log = $this->historicRun($sermon);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->method('analyzeSermon')->willReturn(SermonAnalysis::create(
            title: 'God provides a Lamb',
            series: 'Easter: Good Friday',
            reference: 'John 19:15-30',
            points: ['One'],
            summary: 'A summary.',
            transcript: $this->sampleTranscript,
        ));

        Log::shouldReceive('info')->atLeast()->once();

        (new ProcessTranscriptWithAI($log))->handle(
            $mockService,
            $this->app->make(SermonRepository::class),
        );

        $sermon->refresh();
        $this->assertNull($sermon->series);

        $suggestion = $log->refresh()->processing_metadata?->toArray()['sermon_series_suggestion'] ?? null;

        $this->assertSame('Easter: Good Friday', $suggestion['series']);
        $this->assertSame('not_corroborated_by_reference', $suggestion['reason']);
    }

    /**
     * A live run still takes the model's series: it is naming the series the
     * church is currently preaching, which it has real context for.
     */
    #[Test]
    public function it_still_takes_an_uncorroborated_series_on_a_live_run(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create(['series' => null, 'reference' => null]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->method('analyzeSermon')->willReturn(SermonAnalysis::create(
            title: 'God provides a Lamb',
            series: 'Easter: Good Friday',
            reference: 'John 19:15-30',
            points: ['One'],
            summary: 'A summary.',
            transcript: $this->sampleTranscript,
        ));

        Log::shouldReceive('info')->atLeast()->once();

        (new ProcessTranscriptWithAI($log))->handle(
            $mockService,
            $this->app->make(SermonRepository::class),
        );

        $this->assertSame('Easter: Good Friday', $sermon->refresh()->series);
    }

    private function historicRun(?Sermon &$sermon): MediaProcessingLog
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create(['series' => null, 'reference' => null]);

        return MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
            'historic_import_operation_id' => $this->createHistoricImportOperation()->id,
        ]);
    }

    #[Test]
    public function it_reslugs_a_livestream_placeholder_sermon_from_the_ai_title(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Evening Sermon - January 16, 2022',
            'slug' => 'evening-sermon-january-16-2022',
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($this->createAnalysis('The Good Shepherd'));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('The Good Shepherd', $sermon->title);
        $this->assertEquals('the-good-shepherd', $sermon->slug);
    }

    #[Test]
    public function it_suffixes_the_reslugged_sermon_when_the_ai_title_collides(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        Sermon::factory()->create([
            'title' => 'The Good Shepherd',
            'slug' => 'the-good-shepherd',
        ]);

        $sermon = Sermon::factory()->create([
            'title' => 'Morning Sermon - January 16, 2022',
            'slug' => 'morning-sermon-january-16-2022',
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($this->createAnalysis('The Good Shepherd'));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('the-good-shepherd-1', $sermon->slug);
    }

    #[Test]
    public function it_preserves_a_hand_curated_slug_when_applying_the_ai_title(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Evening Sermon - January 16, 2022',
            'slug' => 'a-slug-the-office-chose',
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($this->createAnalysis('The Good Shepherd'));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('The Good Shepherd', $sermon->title);
        $this->assertEquals('a-slug-the-office-chose', $sermon->slug);
    }

    #[Test]
    public function it_leaves_the_slug_alone_when_the_title_is_already_curated(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Draw Near By The Blood Of Jesus',
            'slug' => 'draw-near-by-the-blood-of-jesus',
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($this->createAnalysis('The Good Shepherd'));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('Draw Near By The Blood Of Jesus', $sermon->title);
        $this->assertEquals('draw-near-by-the-blood-of-jesus', $sermon->slug);
        $this->assertNull($sermon->title_provenance);
    }

    #[Test]
    public function it_uses_fallback_when_transcript_path_is_empty(): void
    {
        Storage::fake();

        $sermon = Sermon::factory()->create(['title' => 'Untitled']);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => null,
            'original_filename' => 'sermon-2026-01-15.mp3',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        // analyzeSermon should NOT be called when transcript path is null
        // (it throws before getting there, then uses fallback)
        $mockService->expects($this->never())
            ->method('analyzeSermon');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        // Falls back to a simple analysis without throwing
        $log->refresh();
        $this->assertEquals('ai_analysis_fallback', $log->current_step);
        $this->assertTrue($log->is_degraded_completion);
    }

    #[Test]
    public function it_uses_fallback_analysis_when_ai_service_fails(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create(['title' => 'Untitled']);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
            'original_filename' => 'sermon-2026-01-15.mp3',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willThrowException(new \Exception('AI service unavailable'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        // Should not throw - fallback was used
        $log->refresh();
        $this->assertNotNull($log->ai_analysis);
        $this->assertEquals('ai_analysis_fallback', $log->current_step);
        $this->assertTrue($log->is_degraded_completion);
    }

    #[Test]
    public function the_fallback_preserves_a_curated_title_when_ai_analysis_fails(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Draw Near By The Blood Of Jesus',
            'slug' => 'draw-near-by-the-blood-of-jesus',
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
            'original_filename' => 'sermon-2026-01-15.mp3',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willThrowException(new \Exception('AI service unavailable'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('Draw Near By The Blood Of Jesus', $sermon->title);
        $this->assertEquals('draw-near-by-the-blood-of-jesus', $sermon->slug);
    }

    #[Test]
    public function the_fallback_keeps_a_placeholder_title_rather_than_downgrading_to_a_junk_filename(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Evening Sermon - January 16, 2022',
            'slug' => 'evening-sermon-january-16-2022',
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
            'original_filename' => '17-55.mkv',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willThrowException(new \Exception('AI service unavailable'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('Evening Sermon - January 16, 2022', $sermon->title);
        $this->assertEquals('evening-sermon-january-16-2022', $sermon->slug);
    }

    #[Test]
    public function the_fallback_still_upgrades_a_placeholder_title_from_a_usable_filename(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Evening Sermon - January 16, 2022',
            'slug' => 'evening-sermon-january-16-2022',
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
            'original_filename' => 'The Good Shepherd.mp3',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willThrowException(new \Exception('AI service unavailable'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('The Good Shepherd', $sermon->title);
        $this->assertEquals('the-good-shepherd', $sermon->slug);
    }

    #[Test]
    public function the_fallback_does_not_wipe_an_existing_summary_and_points(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Draw Near By The Blood Of Jesus',
            'summary' => 'A careful walk through Hebrews 10.',
            'points' => [['point' => 'The new and living way', 'sub_points' => []]],
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
            'original_filename' => 'sermon-2026-01-15.mp3',
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willThrowException(new \Exception('AI service unavailable'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('A careful walk through Hebrews 10.', $sermon->summary);
        $this->assertEquals([['point' => 'The new and living way', 'sub_points' => []]], $sermon->points);
    }

    #[Test]
    public function it_preserves_id3_title_over_ai_title_when_valid(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create(['title' => 'Untitled']);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
            'processing_metadata' => [
                'id3_metadata' => [
                    'title' => 'ID3 Sermon Title',
                    'series' => null,
                    'reference' => null,
                ],
            ],
        ]);

        $analysis = $this->createAnalysis('AI Generated Title');

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($analysis);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        // ID3 title should be preserved, not overwritten by AI
        $this->assertEquals('Untitled', $sermon->title);
    }

    #[Test]
    public function it_uses_ai_title_when_id3_title_looks_like_filename(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create(['title' => 'Untitled']);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
            'processing_metadata' => [
                'id3_metadata' => [
                    'title' => 'sermon_2026-01-15.mp3', // looks like a filename
                    'series' => null,
                    'reference' => null,
                ],
            ],
        ]);

        $analysis = $this->createAnalysis('A Proper Sermon Title');

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($analysis);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('A Proper Sermon Title', $sermon->title);
        $this->assertSame(SermonTitleProvenance::AiAnalysis, $sermon->title_provenance);
    }

    #[Test]
    public function it_replaces_bare_time_titles_with_the_ai_title(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create(['title' => '18 08']);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $analysis = $this->createAnalysis('A Proper Sermon Title');

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($analysis);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('A Proper Sermon Title', $sermon->title);
    }

    #[Test]
    public function it_replaces_date_only_titles_with_the_ai_title(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        // A livestream named "Sunday 3rd May 2026.mp4" title-cases into a
        // date-only placeholder — not a curated title, so the AI title wins
        // (every sermon in the 2026-07-10 test-set-2 batch kept its filename).
        $sermon = Sermon::factory()->create([
            'title' => 'Sunday 3Rd May 2026',
            'title_provenance' => null,
        ]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $analysis = $this->createAnalysis('A Proper Sermon Title');

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($analysis);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        $this->assertEquals('A Proper Sermon Title', $sermon->title);
        $this->assertSame(SermonTitleProvenance::AiAnalysis, $sermon->title_provenance);
    }

    #[Test]
    public function it_passes_processing_id_through_to_analysis_service(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create(['title' => 'Untitled Sermon']);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
        ]);

        $analysis = $this->createAnalysis();

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->with(
                $this->isString(),
                $this->isArray(),
                $this->equalTo($log->processing_id),
            )
            ->willReturn($analysis);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));
    }

    #[Test]
    public function failed_method_marks_processing_log_as_failed(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        Log::shouldReceive('error')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->failed(new \Exception('Permanent AI failure'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
    }

    #[Test]
    public function it_updates_sermon_summary_and_points_regardless_of_id3(): void
    {
        Storage::fake();
        Storage::put('transcripts/1/transcript.txt', $this->sampleTranscript);

        $sermon = Sermon::factory()->create(['summary' => null, 'points' => null]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'transcript_file_path' => 'transcripts/1/transcript.txt',
            'processing_metadata' => [
                'id3_metadata' => [
                    'title' => 'ID3 Title',
                    'series' => 'ID3 Series',
                    'reference' => 'Romans 8:28',
                ],
            ],
        ]);

        $analysis = $this->createAnalysis('AI Title');

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($analysis);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ProcessTranscriptWithAI($log);
        $job->handle($mockService, $this->app->make(SermonRepository::class));

        $sermon->refresh();
        // Summary and points always updated from AI
        $this->assertEquals('A sermon about Jesus as the Good Shepherd.', $sermon->summary);
        $this->assertNotEmpty($sermon->points);
    }
}
