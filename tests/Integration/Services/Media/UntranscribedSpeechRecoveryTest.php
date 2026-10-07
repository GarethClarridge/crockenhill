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
            ...array_map(static fn (int $i): array => ['start' => $i * 10.0, 'end' => $i * 10.0 + 9.5, 'text' => 'Covered by a line of ordinary speech at an ordinary pace.'], range(0, 9)),
            ...array_map(static fn (int $i): array => ['start' => 105.0 + $i * 10.0, 'end' => 105.0 + $i * 10.0 + 9.5, 'text' => 'Also covered by a line of ordinary speech at a pace.'], range(0, 9)),
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
     * Canary 12, 1025 615–631: a 30 s "Thank you." cue lay over "…Let's join together in prayer,
     * shall we? Let's pray.", so recovery took the stretch as covered and the song end ran through
     * it. A cue whose words could not fill a tenth of its span is no evidence of where speech is.
     * The decode replaces it, and the run records what was replaced.
     */
    #[Test]
    public function speech_under_a_long_filler_cue_is_decoded_and_replaces_it(): void
    {
        $filler = ['start' => 100.8, 'end' => 130.78, 'text' => 'Thank you.'];
        $log = $this->serviceRun([
            ['start' => 0.0, 'end' => 9.0, 'text' => 'Earlier words, spoken at an ordinary pace for a line.'],
            $filler,
            ['start' => 130.8, 'end' => 137.0, 'text' => 'Father, we do come to you this morning, very conscious,'],
        ], speech: [[115, 135]], music: [[95, 110]]);
        $this->decodes([[114.0, 131.8, [
            ['start' => 1.5, 'end' => 1.9, 'word' => ' Well,'],
            ['start' => 1.9, 'end' => 2.2, 'word' => ' he'],
            ['start' => 2.2, 'end' => 2.8, 'word' => ' rescues'],
            ['start' => 2.8, 'end' => 3.0, 'word' => ' us.'],
            ['start' => 9.0, 'end' => 9.3, 'word' => ' Let\'s'],
            ['start' => 9.3, 'end' => 9.8, 'word' => ' pray.'],
        ]]]);

        $this->assertSame(['stretches' => 1, 'recovered' => 1], app(UntranscribedSpeechRecovery::class)->recover($log));

        $log->refresh();
        $cues = $this->transcript($log)->cues;
        $this->assertNotContains($filler, $cues);
        $this->assertContains(['start' => 115.5, 'end' => 117.0, 'text' => 'Well, he rescues us.'], $cues);
        $this->assertContains(['start' => 123.0, 'end' => 123.8, 'text' => 'Let\'s pray.'], $cues);
        $attempt = $log->processing_metadata->raw['untranscribed_speech_recovery'][0];
        $this->assertEquals([$filler], $attempt['replaced_cues']);
    }

    /** A dense long line and a short "Thank you." are where their words are; nothing under them is decoded. */
    #[Test]
    public function genuine_long_speech_and_genuine_short_utterances_still_cover_their_stretch(): void
    {
        $long = ['start' => 100.0, 'end' => 117.0, 'text' => implode(' ', array_fill(0, 40, 'word'))];
        $log = $this->serviceRun([
            $long,
            ['start' => 117.5, 'end' => 118.4, 'text' => 'Thank you.'],
            ['start' => 118.5, 'end' => 128.0, 'text' => implode(' ', array_fill(0, 25, 'more'))],
        ], speech: [[100, 128]]);
        $path = $log->serviceTranscriptPath();
        $this->decodes([]);

        $this->assertSame(['stretches' => 0, 'recovered' => 0], app(UntranscribedSpeechRecovery::class)->recover($log));
        $this->assertSame($path, $log->fresh()->serviceTranscriptPath());
    }

    /**
     * Codex review: length and density only raise suspicion. A slow speaker's long line, and a
     * reading with pauses, are heard as speech throughout with words enough for it: they cover
     * their stretch, and nothing under them is decoded.
     */
    #[Test]
    public function slow_genuine_speech_and_a_reading_with_pauses_still_cover_their_stretch(): void
    {
        $slow = ['start' => 100.0, 'end' => 116.0, 'text' => 'We pray for those who are grieving this week, Lord, and for all.'];
        $reading = ['start' => 120.0, 'end' => 140.0, 'text' => 'In the beginning was the Word, and the Word was with God, and the Word was God.'];
        $log = $this->serviceRun([$slow, $reading], speech: [[100, 130], [135, 140]]);
        $path = $log->serviceTranscriptPath();
        $this->decodes([]);

        $this->assertSame(['stretches' => 0, 'recovered' => 0], app(UntranscribedSpeechRecovery::class)->recover($log));
        $this->assertSame($path, $log->fresh()->serviceTranscriptPath());
    }

    /** A "Thank you." stretched over 30 s of speech says too little for what is heard: it is suspect. */
    #[Test]
    public function a_long_filler_cue_over_speech_alone_is_still_decoded_under(): void
    {
        $filler = ['start' => 100.0, 'end' => 130.0, 'text' => 'Thank you.'];
        $log = $this->serviceRun([
            ['start' => 0.0, 'end' => 9.0, 'text' => 'Earlier words, spoken at an ordinary pace for a line.'],
            $filler,
        ], speech: [[100, 130]]);
        $this->decodes([[99.0, 131.0, [
            ['start' => 2.0, 'end' => 2.4, 'word' => ' Let\'s'],
            ['start' => 2.4, 'end' => 2.9, 'word' => ' pray.'],
        ]]]);

        $this->assertSame(['stretches' => 1, 'recovered' => 1], app(UntranscribedSpeechRecovery::class)->recover($log));
    }

    /** Canary 12: cues of punctuation alone (". . . .") are neither speech nor coverage. */
    #[Test]
    public function a_punctuation_only_cue_does_not_cover_speech(): void
    {
        $log = $this->serviceRun([
            ['start' => 0.0, 'end' => 9.0, 'text' => 'Earlier words, spoken at an ordinary pace for a line.'],
            ['start' => 100.0, 'end' => 112.0, 'text' => '. . . .'],
        ], speech: [[100, 115]]);
        $this->decodes([[99.0, 116.0, [
            ['start' => 2.0, 'end' => 2.4, 'word' => ' Let\'s'],
            ['start' => 2.4, 'end' => 2.9, 'word' => ' pray.'],
        ]]]);

        $this->assertSame(['stretches' => 1, 'recovered' => 1], app(UntranscribedSpeechRecovery::class)->recover($log));
        $this->assertContains(['start' => 101.0, 'end' => 101.9, 'text' => 'Let\'s pray.'], $this->transcript($log->fresh())->cues);
    }

    /**
     * A long cue's own words heard in the decode are said once: the decode's timing replaces the
     * cue's, and decoding again finds the stretch covered (no duplicated text).
     */
    #[Test]
    public function a_long_cue_whose_words_are_heard_is_replaced_once_and_recovery_is_repeatable(): void
    {
        $smeared = ['start' => 100.0, 'end' => 129.0, 'text' => 'May the God of hope fill you.'];
        $log = $this->serviceRun([
            ['start' => 0.0, 'end' => 9.0, 'text' => 'Earlier words, spoken at an ordinary pace for a line.'],
            $smeared,
        ], speech: [[115, 130]], music: [[100, 115]]);
        $this->decodes([[114.0, 131.0, [
            ['start' => 10.0, 'end' => 10.2, 'word' => ' May'],
            ['start' => 10.2, 'end' => 10.4, 'word' => ' the'],
            ['start' => 10.4, 'end' => 10.7, 'word' => ' God'],
            ['start' => 10.7, 'end' => 10.9, 'word' => ' of'],
            ['start' => 10.9, 'end' => 11.3, 'word' => ' hope'],
            ['start' => 11.3, 'end' => 11.5, 'word' => ' fill'],
            ['start' => 11.5, 'end' => 11.8, 'word' => ' you.'],
        ]]]);
        $recovery = app(UntranscribedSpeechRecovery::class);

        $this->assertSame(['stretches' => 1, 'recovered' => 1], $recovery->recover($log));
        $log->refresh();
        $once = $this->transcript($log)->cues;
        $this->assertSame([['start' => 124.0, 'end' => 125.8, 'text' => 'May the God of hope fill you.']], array_values(array_filter($once, static fn (array $cue): bool => $cue['start'] >= 99.0)));

        $this->assertSame(['stretches' => 0, 'recovered' => 0], $recovery->recover($log));
        $this->assertSame($once, $this->transcript($log->fresh())->cues);
    }

    /**
     * A failed decode leaves the long cue where it was, and its uncertainty on the run: it does
     * not count as coverage again, so the next detection still marks the stretch (F11).
     */
    #[Test]
    public function a_failed_decode_under_a_long_cue_keeps_it_visible_as_suspect(): void
    {
        $filler = ['start' => 100.8, 'end' => 130.78, 'text' => 'Thank you.'];
        $log = $this->serviceRun([
            ['start' => 0.0, 'end' => 9.0, 'text' => 'Earlier words, spoken at an ordinary pace for a line.'],
            $filler,
        ], speech: [[115, 135]]);
        $path = $log->serviceTranscriptPath();
        $this->decodes([[114.0, 136.0, []]]);

        $this->assertSame(['stretches' => 1, 'recovered' => 0], app(UntranscribedSpeechRecovery::class)->recover($log));
        $log->refresh();
        $this->assertSame($path, $log->serviceTranscriptPath());
        $attempt = $log->processing_metadata->raw['untranscribed_speech_recovery'][0];
        $this->assertSame(0, $attempt['words']);
        $this->assertEquals([$filler], $attempt['suspect_cues']);
        $this->assertSame([], $attempt['replaced_cues']);
    }

    /**
     * Speech under a long cue that the decode did not reach may be where the cue's own words are:
     * the cue stays unless its words were heard.
     */
    #[Test]
    public function a_long_cue_with_undecoded_speech_beneath_it_is_kept(): void
    {
        $long = ['start' => 100.0, 'end' => 140.0, 'text' => 'Thank you, Aled.'];
        $log = $this->serviceRun([
            ['start' => 0.0, 'end' => 9.0, 'text' => 'Earlier words, spoken at an ordinary pace for a line.'],
            $long,
        ], speech: [[100, 105], [115, 130]]);
        $this->decodes([[114.0, 131.0, [
            ['start' => 2.0, 'end' => 2.4, 'word' => ' Something'],
            ['start' => 2.4, 'end' => 2.9, 'word' => ' else.'],
        ]]]);

        $this->assertSame(['stretches' => 1, 'recovered' => 1], app(UntranscribedSpeechRecovery::class)->recover($log));
        $log->refresh();
        $this->assertContains($long, $this->transcript($log)->cues);
        $this->assertSame([], $log->processing_metadata->raw['untranscribed_speech_recovery'][0]['replaced_cues']);
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
