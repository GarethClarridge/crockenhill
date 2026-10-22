<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\ServiceAuditPacket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceAuditPacketTest extends TestCase
{
    use DatabaseTransactions;

    /** @param array<string, array<string, mixed>> $mediaOutputs */
    private function auditedRun(array $mediaOutputs = [], bool $withTranscript = true): MediaProcessingLog
    {
        Storage::fake('local');
        Storage::fake('audit_outputs');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->completed()->create(['duration' => 4000]);
        if ($withTranscript) {
            $log->putServiceTranscriptPath('service-transcripts/audit.json');
            Storage::disk('local')->put('service-transcripts/audit.json', json_encode(ChurchServiceTranscript::fromCues([
                ['start' => 100.0, 'end' => 110.0, 'text' => 'Please turn to Luke 5.'],
                ['start' => 500.0, 'end' => 505.0, 'text' => 'Notices this week.'],
                ['start' => 900.0, 'end' => 910.0, 'text' => 'And so we conclude.'],
            ], 4000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        }
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $metadata['media_outputs'] = [];
        foreach ($mediaOutputs as $key => $output) {
            $output['provenance']['processing_id'] = $log->processing_id;
            $metadata['media_outputs'][$key] = $output;
        }
        $log->forceFill(['processing_metadata' => $metadata])->save();

        return $log->fresh() ?? $log;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $spans
     * @return array<string, mixed>
     */
    private function media(array $spans, string $sha = 'a'): array
    {
        return ['disk' => 'audit_outputs', 'path' => 'out.mp4', 'size' => 1, 'sha256' => str_repeat($sha, 64),
            'provenance' => ['segments' => array_map(static fn (array $span): array => ['start_time' => $span[0], 'end_time' => $span[1]], $spans)]];
    }

    /** Each executed segment is placed on the output's own clock, with the source lines it holds. */
    #[Test]
    public function it_maps_each_recorded_segment_onto_the_outputs_clock(): void
    {
        $log = $this->auditedRun(['sermon' => $this->media([[95.0, 200.0], [850.0, 920.0]])]);
        Storage::disk('audit_outputs')->put('transcripts/s.md', 'Please turn to Luke 5. And so we conclude.');
        Sermon::factory()->create(['livestream_processing_id' => $log->processing_id, 'asset_disk' => 'audit_outputs', 'transcript_file_path' => 'transcripts/s.md']);

        $packet = app(ServiceAuditPacket::class)->build($log);

        $output = $packet['delivered']['outputs'][0];
        $this->assertSame('sermon', $output['key']);
        $this->assertSame(175.0, $output['duration']);
        $this->assertSame([0.0, 105.0], [$output['segments'][0]['output_start'], $output['segments'][0]['output_end']]);
        $this->assertSame([105.0, 175.0], [$output['segments'][1]['output_start'], $output['segments'][1]['output_end']]);
        $this->assertSame(['Please turn to Luke 5.'], array_column($output['segments'][0]['first_lines'], 'text'));
        $this->assertSame(['And so we conclude.'], array_column($output['segments'][1]['last_lines'], 'text'));
        $this->assertSame(1, $output['segments'][1]['lines']);
        $this->assertSame('Please turn to Luke 5. And so we conclude.', $output['sermon']['transcript']);
        $this->assertCount(3, $packet['source']['cues']);
        $this->assertSame([], $packet['gaps']);
    }

    /** Missing evidence is named, never filled from section bounds or a new plan. */
    #[Test]
    public function it_names_missing_evidence_instead_of_predicting_it(): void
    {
        $log = $this->auditedRun([], withTranscript: false);
        Sermon::factory()->create(['livestream_processing_id' => $log->processing_id, 'asset_disk' => 'audit_outputs', 'transcript_file_path' => null]);
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Sermon, 'start_time' => 100.0, 'end_time' => 900.0]);

        $packet = app(ServiceAuditPacket::class)->build($log);

        $this->assertContains('source_transcript_missing', $packet['gaps']);
        $this->assertContains('sermon_segments_missing', $packet['gaps']);
        $this->assertSame([], $packet['delivered']['outputs'][0]['segments']);
        $this->assertNotEmpty(array_filter($packet['gaps'], static fn (string $gap): bool => str_ends_with($gap, '_transcript_missing') && str_starts_with($gap, 'sermon_')));
    }

    #[Test]
    public function it_flags_two_outputs_bound_to_the_same_file(): void
    {
        $log = $this->auditedRun();
        $first = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song, 'start_time' => 10.0, 'end_time' => 60.0]);
        $second = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song, 'start_time' => 70.0, 'end_time' => 120.0]);
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $metadata['media_outputs'] = [
            'section_'.$first->id => $this->media([[10.0, 60.0]], 'b'),
            'section_'.$second->id => $this->media([[70.0, 120.0]], 'b'),
        ];
        foreach ($metadata['media_outputs'] as &$output) {
            $output['provenance']['processing_id'] = $log->processing_id;
        }
        unset($output);
        $log->forceFill(['processing_metadata' => $metadata])->save();

        $packet = app(ServiceAuditPacket::class)->build($log->fresh() ?? $log);

        $this->assertContains(sprintf('duplicate_output: section_%d, section_%d', $first->id, $second->id), $packet['gaps']);
    }

    /** An output whose provenance names another run describes some other recording's cut. */
    #[Test]
    public function it_flags_an_output_recorded_for_another_run(): void
    {
        $log = $this->auditedRun();
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $metadata['media_outputs']['sermon'] = $this->media([[95.0, 200.0]]);
        $metadata['media_outputs']['sermon']['provenance']['processing_id'] = 'another-run';
        $log->forceFill(['processing_metadata' => $metadata])->save();
        Sermon::factory()->create(['livestream_processing_id' => $log->processing_id, 'asset_disk' => 'audit_outputs', 'transcript_file_path' => null]);

        $packet = app(ServiceAuditPacket::class)->build($log->fresh() ?? $log);

        $this->assertContains('sermon_recorded_for_another_run', $packet['gaps']);
    }

    /** The audit reads a frozen copy: the working and testing databases are refused before anything is read. */
    #[Test]
    public function the_command_refuses_any_database_but_an_audit_copy(): void
    {
        $this->artisan('service-audit:packets', ['runs' => [1], '--database' => 'crockenhill', '--out' => sys_get_temp_dir()])
            ->expectsOutputToContain('crockenhill_audit')
            ->assertFailed();
        $this->artisan('service-audit:packets', ['runs' => [1], '--database' => 'testing', '--out' => sys_get_temp_dir()])
            ->assertFailed();
        $this->artisan('service-audit:packets', ['runs' => [1], '--out' => sys_get_temp_dir()])
            ->assertFailed();
    }
}
