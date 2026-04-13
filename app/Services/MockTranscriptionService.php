<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TranscriptionServiceInterface;
use App\Traits\HandlesTranscriptStorage;
use Illuminate\Support\Facades\Log;

class MockTranscriptionService implements TranscriptionServiceInterface
{
    use HandlesTranscriptStorage;

    private const MOCK_TRANSCRIPT = <<<'TRANSCRIPT'
Good morning, everyone. Today we're going to be looking at Romans chapter 8, verse 28, and our first point is the scope of God's sovereignty. The apostle Paul writes, "And we know that in all things God works for the good of those who love him, who have been called according to his purpose." Paul is not limiting this promise to comfortable seasons; he includes uncertainty, disappointment, and delay. In every season, the Lord is active, wise, and present with his people.

Our second point is how believers respond when life is hard. We are not called to deny pain, but to bring it honestly before God in prayer while remaining rooted in Scripture. Christian hope is not passive optimism; it is active trust that God can redeem what we cannot yet understand. As we walk through trials, faith grows through obedience, patience, and mutual encouragement in the church.

Our third point is the purpose of this promise in daily mission. When we remember God's faithful providence, we become steadier in service, kinder in speech, and more courageous in witness. We forgive more quickly, we persevere in doing good, and we care for people who are weary. The same grace that sustains us also equips us to strengthen others and point them to Christ.
TRANSCRIPT;

    public function __construct(
        private readonly SermonProcessingLogger $logger,
        private readonly TranscriptStorageService $storageService,
    ) {}

    /**
     * Mock transcription that returns static in-code content
     *
     * @param  string  $audioFilePath  Path to the audio file (not actually used)
     * @param  string  $processingId  Processing ID for logging
     * @return string The mock transcribed text
     */
    public function transcribe(string $audioFilePath, string $processingId = 'unknown', ?string $disk = null): string
    {
        $startTime = microtime(true);

        $this->logger->logProcessingStep(
            $processingId,
            'mock_transcription',
            'started',
            [
                'file_path' => $audioFilePath,
                'mock' => true,
            ]
        );

        $transcript = self::MOCK_TRANSCRIPT;

        $processingTime = microtime(true) - $startTime;

        $this->logger->logProcessingStep(
            $processingId,
            'mock_transcription',
            'completed',
            [
                'transcript_length' => strlen($transcript),
                'word_count' => str_word_count($transcript),
                'processing_time' => $processingTime,
                'mock' => true,
            ]
        );

        Log::info('Mock transcription completed', [
            'processing_id' => $processingId,
            'audio_file' => basename($audioFilePath),
            'transcript_length' => strlen($transcript),
            'processing_time' => $processingTime,
            'mock' => true,
        ]);

        return $transcript;
    }
}
