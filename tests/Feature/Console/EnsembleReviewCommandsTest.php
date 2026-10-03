<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Jobs\DetectServiceStructure;
use App\Models\ChurchService;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\MockServiceStructureService;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Sermon\SermonCandidateConfidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class EnsembleReviewCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Process::fake();
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.service_structure.detector', 'mock');
        Config::set('media-processing.service_structure.mode', 'primary');
        $this->out = storage_path('framework/testing/ensemble-review-'.getmypid());
        File::deleteDirectory($this->out);
    }

    protected function tearDown(): void
    {
        MockServiceStructureService::useStructure(null);
        File::deleteDirectory($this->out);

        parent::tearDown();
    }

    #[Test]
    public function the_export_lists_open_questions_and_majority_decisions_with_a_whole_passage_clip_each(): void
    {
        $log = $this->disputedRun();

        $this->artisan('structure:ensemble-export-questions', ['runs' => [$log->id], '--out' => $this->out])
            ->assertSuccessful();

        $map = json_decode((string) file_get_contents("{$this->out}/answers-map.json"), true)['items'];
        $question = $map["{$log->id}-q0"];
        $decision = $map["{$log->id}-m0"];

        $this->assertSame(['bible_reading', false], [$question['type'], $question['decided']]);
        $this->assertSame(['short_talk', true], [$decision['type'], $decision['decided']]);
        $this->assertSame(['kind' => 'choose', 'slot' => 2], $question['answers']['alt1']);
        $this->assertSame('accept', $question['answers']['either']['kind']);
        $this->assertSame('correct', $question['answers']['neither']['kind']);

        $page = (string) file_get_contents("{$this->out}/index.html");
        $this->assertStringContainsString('const QUESTIONS = [', $page);
        $this->assertStringContainsString("\"id\":\"{$log->id}-m0\"", $page);
        $this->assertStringContainsString('db.doc("rulings/" + q.id).set(body)', $page);
        $this->assertStringContainsString('Settled by majority vote', $page);

        Process::assertRan(fn ($process): bool => in_array("{$this->out}/clips/{$log->id}-q0-0.mp3", $process->command, true)
            && in_array('405', $process->command, true)
            && in_array('605', $process->command, true));
    }

    #[Test]
    public function applying_saved_answers_records_them_and_reports_what_needs_a_correction(): void
    {
        $log = $this->disputedRun();
        $this->artisan('structure:ensemble-export-questions', ['runs' => [$log->id], '--out' => $this->out])->assertSuccessful();
        $answers = "{$this->out}/answers.json";
        file_put_contents($answers, json_encode([
            ['id' => "{$log->id}-q0", 'data' => ['choice' => 'alt1', 'choice_label' => 'Version B', 'note' => '']],
            ['question_id' => "{$log->id}-m0", 'choice' => 'neither', 'note' => 'The talk ends at 6:10'],
        ]));

        $this->artisan('structure:ensemble-apply-answers', ['export' => $this->out, 'answers' => $answers])
            ->assertSuccessful();
        $this->assertSame([], $log->fresh()->processing_metadata?->raw['service_structure_ensemble_rulings'] ?? []);

        $this->artisan('structure:ensemble-apply-answers', ['export' => $this->out, 'answers' => $answers, '--execute' => true])
            ->expectsOutputToContain("{$log->id}-q0: applied (choose)")
            ->expectsOutputToContain('1 applied, 0 failed, 1 need attention.')
            ->assertFailed();

        $rulings = $log->fresh()->processing_metadata?->raw['service_structure_ensemble_rulings'];
        $this->assertCount(1, $rulings);
        $this->assertSame('Luke 15:1-10', $rulings[0]['resolution']['sections'][0]['reading_reference']);
    }

    #[Test]
    public function a_talk_edge_every_draft_agrees_on_is_asked_as_confirm_or_correct(): void
    {
        $log = $this->agreedRun(talkEnd: 400.0, prayer: true, neighbourType: 'notices');

        $this->artisan('structure:ensemble-export-questions', ['runs' => [$log->id], '--out' => $this->out])
            ->assertSuccessful();

        $check = json_decode((string) file_get_contents("{$this->out}/answers-map.json"), true)['items']["{$log->id}-q0"];
        $this->assertSame('short_talk', $check['type']);
        $this->assertSame(['alt0', 'neither', 'defer'], array_keys($check['answers']));
        $this->assertSame('choose', $check['answers']['alt0']['kind']);
        $this->assertStringContainsString('Does the talk end in the right place?', (string) file_get_contents("{$this->out}/index.html"));
    }

    /**
     * The samples measure what the queue cannot: how often a three-to-one vote is wrong, and how
     * often a cut nobody was asked about is wrong. A cut answer is recorded, never applied.
     */
    #[Test]
    public function sampled_decisions_are_asked_and_sampled_cuts_are_recorded_not_applied(): void
    {
        $disputed = $this->disputedRun();
        $quiet = $this->agreedRun(talkEnd: 400.0, prayer: false);

        $this->artisan('structure:ensemble-export-questions', [
            'runs' => [$disputed->id, $quiet->id],
            '--out' => $this->out,
            '--sample-decided' => 1,
            '--sample-cuts' => 1,
            '--seed' => 7,
        ])->assertSuccessful();

        $export = json_decode((string) file_get_contents("{$this->out}/answers-map.json"), true);
        $this->assertSame(['seed' => 7, 'decided' => 1, 'cuts' => 1], $export['samples']);
        $this->assertTrue($export['items']["{$disputed->id}-m0"]['sampled']);
        $this->assertSame('sample_cut', $export['items']["{$quiet->id}-c0"]['type']);
        $this->assertStringContainsString('Sampled majority decision', (string) file_get_contents("{$this->out}/index.html"));

        $answers = "{$this->out}/answers.json";
        file_put_contents($answers, json_encode([
            ['question_id' => "{$quiet->id}-c0", 'choice' => 'wrong', 'note' => 'The cut starts in the prayer'],
        ]));

        $this->artisan('structure:ensemble-apply-answers', ['export' => $this->out, 'answers' => $answers, '--execute' => true])
            ->expectsOutputToContain('Sampled cuts: 1 heard, 0 right, 1 wrong')
            ->expectsOutputToContain("Run {$quiet->id}: sampled cut judged wrong")
            ->assertSuccessful();

        $recorded = json_decode((string) file_get_contents("{$this->out}/sample-results.json"), true)['samples'];
        $this->assertSame([['id' => "{$quiet->id}-c0", 'run' => $quiet->id, 'answer' => 'sample_wrong', 'note' => 'The cut starts in the prayer']], $recorded);
        $this->assertSame([], $quiet->fresh()->processing_metadata?->raw['service_structure_ensemble_rulings'] ?? []);
    }

    #[Test]
    public function the_batch_report_counts_questions_answers_and_review_cost(): void
    {
        $log = $this->disputedRun();
        $this->artisan('structure:ensemble-export-questions', ['runs' => [$log->id], '--out' => $this->out])->assertSuccessful();
        $answers = "{$this->out}/answers.json";
        file_put_contents($answers, json_encode([['question_id' => "{$log->id}-q0", 'choice' => 'alt1', 'note' => '']]));
        $this->artisan('structure:ensemble-apply-answers', ['export' => $this->out, 'answers' => $answers, '--execute' => true])->assertSuccessful();
        $reportPath = "{$this->out}/batch-report.json";

        $this->artisan('structure:ensemble-batch-report', ['runs' => [$log->id], '--export' => [$this->out], '--report' => $reportPath])
            ->expectsOutputToContain('Open questions (per run)')
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($reportPath), true);
        $this->assertSame(1, $report['runs']);
        $this->assertSame(0, $report['open_questions']);
        $this->assertSame(1, $report['majority_decisions']);
        $this->assertSame(['choose' => 1], $report['answers_by_kind']);
        $this->assertSame([1, 0], [$report['corrections'], $report['confirmations']]);
        $this->assertSame(1, $report['applied']);
        $this->assertSame(1, $report['review_minutes_estimate']);
    }

    /** Four identical drafts, optionally followed by another speech section. */
    private function agreedRun(float $talkEnd, bool $prayer, string $neighbourType = 'prayer'): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'church_service_id' => ChurchService::factory()->create()->id,
        ]);
        $this->storeInputs($log);
        $draw = ServiceStructure::fromSections(array_values(array_filter([
            $this->section(['type' => 'welcome', 'start_time' => 0.0, 'end_time' => 120.0]),
            $this->section(['type' => 'short_talk', 'start_time' => 130.0, 'end_time' => $talkEnd]),
            $prayer ? $this->section(['type' => $neighbourType, 'start_time' => $talkEnd, 'end_time' => 415.0]) : null,
            $this->section(['type' => 'bible_reading', 'start_time' => 420.0, 'end_time' => 590.0, 'reading_reference' => 'Luke 15:1-10']),
            $this->section(['type' => 'sermon', 'start_time' => 600.0, 'end_time' => 2200.0, 'sermon_reference' => 'Luke 15:1-10']),
            $this->section(['type' => 'song', 'start_time' => 2210.0, 'end_time' => 2400.0]),
        ])), model: 'mock');
        MockServiceStructureService::useStructureSequence($draw, $draw, $draw, $draw);

        (new DetectServiceStructure($log))->handle(
            app(ServiceStructureInterface::class),
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );

        return $log->fresh();
    }

    private function disputedRun(): MediaProcessingLog
    {
        User::factory()->admin()->create();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'church_service_id' => ChurchService::factory()->create()->id,
        ]);
        $this->storeInputs($log);

        // The reading's reference splits two to two (a question); one draft ends the talk 70 s
        // early, which three outvote (a majority decision).
        $draw = fn (string $reading, float $talkEnd): ServiceStructure => ServiceStructure::fromSections([
            $this->section(['type' => 'welcome', 'start_time' => 0.0, 'end_time' => 120.0]),
            $this->section(['type' => 'short_talk', 'start_time' => 130.0, 'end_time' => $talkEnd]),
            $this->section(['type' => 'bible_reading', 'start_time' => 420.0, 'end_time' => 590.0, 'reading_reference' => $reading]),
            $this->section(['type' => 'sermon', 'start_time' => 600.0, 'end_time' => 2200.0, 'sermon_reference' => 'Luke 15:1-10']),
            $this->section(['type' => 'song', 'start_time' => 2210.0, 'end_time' => 2400.0]),
        ], model: 'mock');
        MockServiceStructureService::useStructureSequence(
            $draw('Psalm 23', 400.0),
            $draw('Psalm 23', 400.0),
            $draw('Luke 15:1-10', 400.0),
            $draw('Luke 15:1-10', 330.0),
        );

        (new DetectServiceStructure($log))->handle(
            app(ServiceStructureInterface::class),
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );

        return $log->fresh();
    }

    private function storeInputs(MediaProcessingLog $log): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 120.0, 'text' => 'Good morning everyone and a very warm welcome.'],
            ['start' => 130.0, 'end' => 330.0, 'text' => 'A word for the children.'],
            ['start' => 330.0, 'end' => 400.0, 'text' => 'Before they go out.'],
            ['start' => 420.0, 'end' => 590.0, 'text' => 'Our reading is from Luke chapter fifteen.'],
            ['start' => 600.0, 'end' => 2200.0, 'text' => 'Please turn with me to our passage.'],
            ['start' => 2210.0, 'end' => 2400.0, 'text' => 'Praise my soul the King of heaven.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK);
        $transcriptPath = "temp/service_transcript_{$log->processing_id}.json";
        Storage::disk('local')->put($transcriptPath, (string) json_encode($transcript));
        $log->putServiceTranscriptPath($transcriptPath);

        $timelinePath = "temp/audio_timeline_{$log->processing_id}.classes.json";
        Storage::disk('local')->put($timelinePath, AudioTimelineFixture::json([], 2430.0));
        $audioPath = "service-audio/{$log->processing_id}.mp3";
        Storage::disk('local')->put($audioPath, 'audio');
        $log->forceFill([
            'audio_timeline_path' => $timelinePath,
            'processing_metadata' => [
                ...($log->fresh()->processing_metadata?->toArray() ?? []),
                ServiceArtifactStorage::METADATA_KEY => [['kind' => 'audio', 'disk' => 'local', 'path' => $audioPath]],
            ],
        ])->save();

        foreach ([[0.0, 430.0], [430.0, 1500.0], [1500.0, 2430.0]] as $index => [$start, $end]) {
            LivestreamSegment::factory()->create([
                'media_processing_log_id' => $log->id,
                'segment_index' => $index,
                'segment_order' => $index,
                'start_time' => $start,
                'end_time' => $end,
                'duration' => $end - $start,
                'classification' => 'speech',
            ]);
        }
    }

    /** @param  array<string, mixed>  $fields */
    private function section(array $fields): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray(['confidence' => 0.95, ...$fields]);
        assert($section instanceof ServiceStructureSection);

        return $section;
    }
}
