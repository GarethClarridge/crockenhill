<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Media;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Media\Audio\UntranscribedSpeechRecovery;
use App\Support\ServiceArtifactDisk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Canary 11 (operator, 2026-10-06): speech the classifier hears but the transcript has no line
 * for should be transcribed again and processed by the normal logic, not placed by a rule after
 * detection. Re-decoding the 62 corpus stretches on the local whisper returned words for 57
 * (949's "But before I hand over to him I'm just going to pray. So let's pray.").
 */
class UntranscribedSpeechRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function speech_with_no_transcribed_line_is_decoded_into_the_transcript(): void
    {
        $log = $this->serviceRun([
            ['start' => 0.0, 'end' => 95.0, 'text' => 'Earlier words.'],
            ['start' => 130.0, 'end' => 140.0, 'text' => 'Our Heavenly Father,'],
        ], speech: [[100, 130]]);
        $this->decodes([[99.0, 131.0, [
            ['start' => 2.0, 'end' => 2.5, 'word' => ' Before'],
            ['start' => 2.5, 'end' => 3.0, 'word' => ' I'],
            ['start' => 3.0, 'end' => 3.6, 'word' => ' hand'],
            ['start' => 3.6, 'end' => 4.2, 'word' => ' over,'],
            ['start' => 9.0, 'end' => 9.4, 'word' => ' let\'s'],
            ['start' => 9.4, 'end' => 10.0, 'word' => ' pray.'],
        ]]]);

        $outcome = app(UntranscribedSpeechRecovery::class)->recover($log);

        $this->assertSame(['stretches' => 1, 'recovered' => 1], $outcome);
        $log->refresh();
        $transcript = $this->transcript($log);
        $this->assertSame([
            ['start' => 0.0, 'end' => 95.0, 'text' => 'Earlier words.'],
            ['start' => 101.0, 'end' => 103.2, 'text' => 'Before I hand over,'],
            ['start' => 108.0, 'end' => 109.0, 'text' => 'let\'s pray.'],
            ['start' => 130.0, 'end' => 140.0, 'text' => 'Our Heavenly Father,'],
        ], $transcript->cues);
        $this->assertSame(MediaProcessingLog::hashServiceTranscriptContent($transcript), $log->processing_metadata->raw['service_transcript_content']['hash']);
        $this->assertSame([['start' => 100.0, 'end' => 130.0, 'words' => 6]], array_map(
            static fn (array $entry): array => ['start' => (float) $entry['start'], 'end' => (float) $entry['end'], 'words' => $entry['words']],
            $log->processing_metadata->raw['untranscribed_speech_recovery'],
        ));
    }

    #[Test]
    public function covered_speech_short_gaps_and_music_are_left_alone(): void
    {
        $log = $this->serviceRun([
            ['start' => 0.0, 'end' => 100.0, 'text' => 'Covered.'],
            ['start' => 105.0, 'end' => 200.0, 'text' => 'Also covered.'],
        ], speech: [[0, 200]], music: [[200, 300]]);
        $path = $log->serviceTranscriptPath();
        $this->decodes([]);

        $this->assertSame(['stretches' => 0, 'recovered' => 0], app(UntranscribedSpeechRecovery::class)->recover($log));
        $this->assertSame($path, $log->fresh()->serviceTranscriptPath());
    }

    /** A decode that hears nothing, or loops, changes nothing; the stretch stays for F11 to mark. */
    #[Test]
    public function a_stretch_that_still_decodes_to_nothing_or_a_loop_leaves_the_transcript_alone(): void
    {
        $log = $this->serviceRun([
            ['start' => 0.0, 'end' => 95.0, 'text' => 'Earlier words.'],
            ['start' => 300.0, 'end' => 310.0, 'text' => 'Later words.'],
        ], speech: [[100, 130], [200, 230]]);
        $path = $log->serviceTranscriptPath();
        $loop = array_map(static fn (int $i): array => ['start' => 1.0 + $i, 'end' => 1.8 + $i, 'word' => ' Amen'], range(0, 9));
        $this->decodes([[99.0, 131.0, []], [199.0, 231.0, $loop]]);

        $this->assertSame(['stretches' => 2, 'recovered' => 0], app(UntranscribedSpeechRecovery::class)->recover($log));
        $log->refresh();
        $this->assertSame($path, $log->serviceTranscriptPath());
        $this->assertSame([0, 0], array_column($log->processing_metadata->raw['untranscribed_speech_recovery'], 'words'));
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{0: int, 1: int}>  $speech
     * @param  list<array{0: int, 1: int}>  $music
     */
    private function serviceRun(array $cues, array $speech, array $music = []): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 400, 'audio_timeline_path' => 'service-transcripts/run.classes.json']);
        $log->putServiceTranscriptPath('service-transcripts/run.normalized.json');
        Storage::disk('local')->put('service-transcripts/run.normalized.json', json_encode(ChurchServiceTranscript::fromCues($cues, 400, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER)->toArray(), JSON_THROW_ON_ERROR));
        $windows = [];
        for ($start = 0; $start < 400; $start += 5) {
            $inside = static fn (array $spans): bool => array_any($spans, static fn (array $span): bool => $start >= $span[0] && $start < $span[1]);
            $windows[] = ['start' => $start, 'end' => $start + 5, 'music' => $inside($music) ? 0.9 : 0.05, 'speech' => $inside($speech) ? 0.9 : 0.05];
        }
        Storage::disk('local')->put('service-transcripts/run.classes.json', json_encode([
            'model' => 'test', 'model_revision' => 'r1', 'window_seconds' => 5, 'audio_seconds' => 400, 'windows' => $windows,
        ], JSON_THROW_ON_ERROR));
        Storage::disk('local')->put('service-audio/run.mp3', 'audio');
        $log->writeProcessingMetadata(static function (array $metadata): array {
            $metadata[ServiceArtifactStorage::METADATA_KEY][] = ['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/run.mp3', 'recorded_at' => now()->toIso8601String()];

            return $metadata;
        });

        return $log->fresh();
    }

    /** @param list<array{0: float, 1: float, 2: list<array{start: float, end: float, word: string}>}> $windows */
    private function decodes(array $windows): void
    {
        $this->mock(ServiceAudioWindowExtractor::class, function (MockInterface $mock) use ($windows): void {
            foreach ($windows as [$start, $end]) {
                $mock->shouldReceive('extract')->once()->withArgs(static fn (string $audio, float $from, float $to): bool => abs($from - $start) < 0.001 && abs($to - $end) < 0.001)
                    ->andReturn("temp/clip-{$start}.wav");
            }
            $mock->shouldReceive('delete');
        });
        $this->mock(ServiceTranscriptionInterface::class, function (MockInterface $mock) use ($windows): void {
            if ($windows === []) {
                $mock->shouldNotReceive('transcribeEdgeWindow');
            }
            foreach ($windows as [$start, , $words]) {
                $mock->shouldReceive('transcribeEdgeWindow')->once()->with("temp/clip-{$start}.wav")->andReturn($words);
            }
        });
    }

    private function transcript(MediaProcessingLog $log): ChurchServiceTranscript
    {
        $path = $log->serviceTranscriptPath();

        return ChurchServiceTranscript::fromArray(json_decode((string) Storage::disk(ServiceArtifactDisk::for($path))->get($path), true));
    }
}
