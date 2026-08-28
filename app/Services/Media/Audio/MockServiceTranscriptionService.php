<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Services\Processing\SermonProcessingLogger;

class MockServiceTranscriptionService implements ServiceTranscriptionInterface
{
    private static ?ChurchServiceTranscript $fixtureTranscript = null;

    public function __construct(
        private readonly SermonProcessingLogger $logger,
    ) {}

    /**
     * Set the transcript the next transcription call should return.
     *
     * Pass null to restore the built-in deterministic fixture. Tests that set
     * a fixture must reset it (tearDown) because the state is static.
     */
    public static function useTranscript(?ChurchServiceTranscript $transcript): void
    {
        self::$fixtureTranscript = $transcript;
    }

    public function transcribeService(string $audioOrVideoPath, string $processingId, ?string $prompt = null): ChurchServiceTranscript
    {
        $this->logger->logProcessingStep(
            $processingId,
            'mock_service_transcription',
            'completed',
            [
                'file_path' => $audioOrVideoPath,
                'mock' => true,
            ]
        );

        return self::$fixtureTranscript ?? self::defaultFixture();
    }

    /**
     * A deterministic miniature service: welcome, song, reading, sermon, closing.
     */
    private static function defaultFixture(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 20.0, 'text' => 'Good morning everyone and a very warm welcome to Crockenhill Baptist Church.'],
            ['start' => 20.0, 'end' => 60.0, 'text' => 'Let us begin our service by singing our first hymn together.'],
            ['start' => 60.0, 'end' => 240.0, 'text' => 'Praise my soul the King of heaven, to his feet thy tribute bring.'],
            ['start' => 245.0, 'end' => 300.0, 'text' => 'Our reading this morning is from Joshua chapter one, verses one to nine.'],
            ['start' => 300.0, 'end' => 420.0, 'text' => 'After the death of Moses the servant of the Lord, the Lord said to Joshua son of Nun.'],
            ['start' => 430.0, 'end' => 500.0, 'text' => 'Please turn with me in your Bibles to the passage we have just read.'],
            ['start' => 500.0, 'end' => 2200.0, 'text' => 'Our first point this morning is the faithfulness of God to his promises.'],
            ['start' => 2200.0, 'end' => 2280.0, 'text' => 'Let us pray. Heavenly Father, we thank you for your word to us this morning.'],
            ['start' => 2290.0, 'end' => 2400.0, 'text' => 'We close our service by singing together our final hymn.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK);
    }
}
