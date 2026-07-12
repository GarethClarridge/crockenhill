<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;

class MockSermonAnalysisService implements SermonAnalysisInterface
{
    /**
     * @param  array<int, string>  $existingSeries
     */
    public function analyzeSermon(
        string $transcript,
        array $existingSeries = [],
        ?string $processingId = null,
    ): SermonAnalysis {
        return SermonAnalysis::create(
            title: 'Mock Sermon Title',
            series: 'Mock Sermon Series',
            reference: 'John 3:16',
            points: [
                'The first mock sermon point',
                'The second mock sermon point',
                'The third mock sermon point',
            ],
            summary: 'A deterministic sermon summary for tests and local development.',
            transcript: $transcript,
        );
    }
}
