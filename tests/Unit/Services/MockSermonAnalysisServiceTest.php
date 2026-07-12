<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Sermon\MockSermonAnalysisService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MockSermonAnalysisServiceTest extends TestCase
{
    #[Test]
    public function it_returns_a_deterministic_analysis_fixture(): void
    {
        $transcript = 'The supplied sermon transcript.';

        $analysis = app(MockSermonAnalysisService::class)->analyzeSermon(
            $transcript,
            ['An existing series'],
            'processing-id',
        );

        $this->assertSame([
            'title' => 'Mock Sermon Title',
            'series' => 'Mock Sermon Series',
            'reference' => 'John 3:16',
            'points' => [
                'The first mock sermon point',
                'The second mock sermon point',
                'The third mock sermon point',
            ],
            'summary' => 'A deterministic sermon summary for tests and local development.',
            'transcript' => $transcript,
        ], $analysis->toArray());
    }
}
