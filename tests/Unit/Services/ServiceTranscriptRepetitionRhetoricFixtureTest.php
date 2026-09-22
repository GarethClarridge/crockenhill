<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Enums\DetectorCaseBasis;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceTranscriptRepetitionRhetoricFixtureTest extends TestCase
{
    #[Test]
    #[DataProvider('rhetoricWindows')]
    public function it_preserves_provisional_rhetoric_controls_from_real_decoder_cues(int $run): void
    {
        $fixture = json_decode(
            (string) file_get_contents(base_path("tests/Fixtures/TranscriptRepetition/run-{$run}-rhetoric.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('clean', $fixture['truth']);
        $this->assertSame(DetectorCaseBasis::SourceRedecode->value, $fixture['basis']);
        $this->assertFalse($fixture['adjudicated']);
        $this->assertFalse(DetectorCaseBasis::from($fixture['basis'])->isAdjudicated());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fixture['source_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fixture['audio_sha256']);
        $this->assertNotEmpty($fixture['cues']);

        $transcript = ChurchServiceTranscript::fromCues(
            $fixture['cues'],
            (float) $fixture['duration'],
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
        );

        $this->assertSame([], app(ServiceTranscriptRepetitionScreen::class)->screen($transcript));
    }

    /** @return array<string, array{int}> */
    public static function rhetoricWindows(): array
    {
        return [
            'anaphora: the Lord was one who' => [1051],
            'emphasis: in all things' => [1078],
            'quotation: look unto me' => [1079],
        ];
    }
}
